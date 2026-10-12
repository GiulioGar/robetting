<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ServeLocal extends Command
{
    protected $signature = 'robetting:serve
        {--port=8000    : Port for the dev server}
        {--season=2026  : Current season year for calendar stale check}
        {--skip-server  : Skip starting the dev server (for tests)}
        {--once         : Run one refresh cycle then exit (for tests)}
        {--interval=300 : Result refresh interval in seconds}';

    protected $description = 'Start local dev environment: catch-up, calendar check, dev server + result refresh';

    public function handle(
        ApiFootballResultRefreshService       $refreshService,
        ApiFootballFixtureSyncService         $fixtureService,
        ApiFootballMatchStatisticsSyncService $statsService,
        ApiFootballMatchEventSyncService      $eventsService,
        ApiFootballMatchLineupSyncService     $lineupsService,
        ApiFootballInjurySyncService          $injuriesService,
    ): int {
        $this->runStartup($refreshService, $fixtureService, $statsService, $eventsService, $lineupsService, $injuriesService);

        if ($this->option('once')) {
            $this->runRefresh($refreshService);
            $this->runPendingLineups($lineupsService);
            $this->runLiveEvents($eventsService);
            $this->runLiveStats($statsService);
            $this->runPendingStats($statsService);
            $this->runPendingEvents($eventsService);
            $this->runPendingInjuries($injuriesService);
            $this->info('[startup] Refreshing public predictions...');
            $this->runPublicPredictionsRefresh();
            return self::SUCCESS;
        }

        $server = null;
        if (!$this->option('skip-server')) {
            $server = $this->startDevServer();
        }

        // Step 1A.6 — deliberately AFTER the dev server is already up: the
        // first refresh of a given day can take well over a minute (full
        // Candidate47 pipeline per eligible match), and running it inside
        // runStartup() (before the server starts) made the whole site
        // unreachable for that entire time. The site is reachable the moment
        // startDevServer() returns; this call only delays the command's own
        // entry into the periodic sleep loop below, not the site itself.
        // Still exactly once per process start, never inside the loop — the
        // per-match/per-day freshness guard already in
        // PublicPredictionRecorder/PublicPredictionRefreshService is the only
        // source of truth for whether any work actually happens here.
        $this->info('[startup] Refreshing public predictions...');
        $this->runPublicPredictionsRefresh();

        $interval = max(60, (int) $this->option('interval'));
        $this->info("Result refresh every {$interval}s. Ctrl+C to stop.");

        while (true) {
            sleep($interval);

            if ($server && !$server->isRunning()) {
                $this->error('Dev server stopped unexpectedly.');
                break;
            }

            $this->runRefresh($refreshService);
            $this->runPendingLineups($lineupsService);
            $this->runLiveEvents($eventsService);
            $this->runLiveStats($statsService);
            $this->runPendingStats($statsService);
            $this->runPendingEvents($eventsService);
            $this->runPendingInjuries($injuriesService);
        }

        return self::SUCCESS;
    }

    private function runStartup(
        ApiFootballResultRefreshService       $refreshService,
        ApiFootballFixtureSyncService         $fixtureService,
        ApiFootballMatchStatisticsSyncService $statsService,
        ApiFootballMatchEventSyncService      $eventsService,
        ApiFootballMatchLineupSyncService     $lineupsService,
        ApiFootballInjurySyncService          $injuriesService,
    ): void {
        $this->info('[startup] Running catch-up...');
        $catchUp = $refreshService->catchUp();
        $this->line("  candidates={$catchUp['candidates']}  updated={$catchUp['updated']}  api_calls={$catchUp['api_calls']}");

        $this->info('[startup] Checking calendar staleness...');
        $this->maybeRefreshCalendar($fixtureService);

        $this->info('[startup] Syncing pending lineups...');
        $this->runPendingLineups($lineupsService);

        $this->info('[startup] Syncing pending statistics...');
        $this->runPendingStats($statsService);

        $this->info('[startup] Syncing pending events...');
        $this->runPendingEvents($eventsService);

        $this->info('[startup] Syncing pending injuries (72h window)...');
        $this->runPendingInjuries($injuriesService);

        $this->info('[startup] Capturing learning data...');
        $this->runOfficialResultsCapture();
        $this->runOfficialPredictionsCapture();
    }

    /**
     * P27G — local-dev convenience: keep the Learning Loop's official
     * predictions/results (P27B/P27C) moving forward automatically whenever
     * the dev portal is started, instead of requiring a manual artisan call.
     * Runs once per startup only (never in the periodic refresh loop).
     *
     * Reuses the existing capture commands as-is via $this->callSilently()
     * (no logic duplicated here) — their own idempotency is what makes
     * running them on every `robetting:serve` startup safe. Output is
     * suppressed (no payload/feature dump), only a one-line status is
     * shown. A failure here is support tooling, not a hard dependency —
     * it must never prevent the dev server from starting.
     */
    private function runOfficialResultsCapture(): void
    {
        try {
            $this->callSilently('robetting:capture-official-results');
            $this->line('[learning] official results capture completed');
        } catch (\Throwable $e) {
            $this->error('[learning] official results capture failed: ' . $e->getMessage());
        }
    }

    private function runOfficialPredictionsCapture(): void
    {
        try {
            $this->callSilently('robetting:capture-official-predictions', ['--within-hours' => 72]);
            $this->line('[learning] official predictions capture completed');
        } catch (\Throwable $e) {
            $this->error('[learning] official predictions capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Step 1A.5/1A.6 — portal-facing PUBLIC_LATEST predictions, kept fresh
     * automatically once per process start (called from handle(), not
     * runStartup() — see the Step 1A.6 comment at the call site: the first
     * refresh of a given day can take well over a minute, so it must never
     * run before the dev server is already reachable). Still exactly once
     * per start, never in the periodic refresh loop. The command/service's
     * own per-match, per-day (Europe/Rome) freshness guard is the sole source
     * of truth for whether any work actually happens on a given call;
     * ServeLocal does not duplicate or re-check that freshness itself. Same
     * fail-safe posture as the official capture calls above: a failure here
     * must never prevent the dev server from starting.
     */
    private function runPublicPredictionsRefresh(): void
    {
        try {
            $this->callSilently('robetting:refresh-public-predictions', ['--days' => 30]);
            $this->line('[public] public predictions refresh completed');
        } catch (\Throwable $e) {
            $this->error('[public] public predictions refresh failed: ' . $e->getMessage());
        }
    }

    private function maybeRefreshCalendar(ApiFootballFixtureSyncService $fixtureService): void
    {
        $ds = DataSource::where('slug', 'api-football')->first();
        if (!$ds) {
            $this->warn('  API-Football data source not found — skipping.');
            return;
        }

        $lastSync = DataSyncRun::where('data_source_id', $ds->id)
            ->where('sync_type', 'fixture_sync')
            ->orderBy('started_at', 'desc')
            ->first();

        $isStale = $lastSync === null || $lastSync->started_at->lt(now()->subHours(36));

        if (!$isStale) {
            $this->line("  Calendar fresh (last: {$lastSync->started_at->format('d/m/Y H:i')}) — skipping.");
            return;
        }

        $reason = $lastSync === null ? 'never synced' : 'last sync > 36h ago';
        $this->info("  Calendar stale ({$reason}) — running REFRESH sync...");

        $season = (int) $this->option('season');
        $result = $fixtureService->syncAllCompetitions($season, ApiFootballFixtureSyncService::MODE_REFRESH);

        $updated = array_sum(array_column($result['results'], 'updated'));
        $calls   = array_sum(array_column($result['results'], 'api_calls'));
        $this->line("  Done: {$updated} updated, {$calls} API calls.");
    }

    private function runRefresh(ApiFootballResultRefreshService $service): void
    {
        $result = $service->refresh();
        $msg    = "[refresh] candidates={$result['candidates']}  updated={$result['updated']}  api_calls={$result['api_calls']}";
        if ($result['daily_remaining'] !== null) {
            $msg .= "  [rem:{$result['daily_remaining']}]";
        }
        $this->line($msg);
    }

    private function runPendingStats(ApiFootballMatchStatisticsSyncService $service): void
    {
        $result = $service->syncPending();
        if ($result['candidates'] > 0) {
            $this->line("[pending-stats] candidates={$result['candidates']}  synced={$result['synced']}  failed={$result['failed']}  api_calls={$result['api_calls']}");
        }
    }

    private function runLiveStats(ApiFootballMatchStatisticsSyncService $service): void
    {
        $result = $service->syncLive();
        if ($result['candidates'] > 0) {
            $this->line("[live-stats] candidates={$result['candidates']}  synced={$result['synced']}  failed={$result['failed']}  api_calls={$result['api_calls']}");
        }
    }

    private function runLiveEvents(ApiFootballMatchEventSyncService $service): void
    {
        $result = $service->syncLive();
        if ($result['candidates'] > 0) {
            $this->line("[live-events] candidates={$result['candidates']}  synced={$result['synced']}  failed={$result['failed']}  api_calls={$result['api_calls']}");
        }
    }

    private function runPendingEvents(ApiFootballMatchEventSyncService $service): void
    {
        $result = $service->syncPending();
        if ($result['candidates'] > 0) {
            $this->line("[pending-events] candidates={$result['candidates']}  synced={$result['synced']}  failed={$result['failed']}  api_calls={$result['api_calls']}");
        }
    }

    private function runPendingLineups(ApiFootballMatchLineupSyncService $service): void
    {
        $result = $service->syncPending();
        if ($result['candidates'] > 0) {
            $this->line("[pending-lineups] candidates={$result['candidates']}  synced={$result['synced']}  failed={$result['failed']}  empty={$result['empty']}  api_calls={$result['api_calls']}");
        }
    }

    /**
     * Availability V1 Step 5D — prospective player-absence snapshot on a
     * fixed 72h window (matches the validated manual run in Step 5C). The
     * service owns its own kickoff-distance throttle (Step 5B); nothing is
     * duplicated here. Wrapped in try/catch because an uncaught failure here
     * (e.g. missing api-football DataSource) must never block server startup
     * or the periodic refresh loop — same fail-safe posture as the P27G
     * learning-capture calls above.
     */
    private function runPendingInjuries(ApiFootballInjurySyncService $service): void
    {
        try {
            $result = $service->syncPending(3);
            if ($result['candidates'] > 0) {
                $this->line("[pending-injuries] candidates={$result['candidates']}  synced={$result['synced']}  skipped_throttle={$result['skipped_throttle']}  failed={$result['failed']}  api_calls={$result['api_calls']}");
            }
        } catch (\Throwable $e) {
            $this->error('[pending-injuries] sync failed: ' . $e->getMessage());
        }
    }

    private function startDevServer(): Process
    {
        $port    = (int) $this->option('port');
        $artisan = base_path('artisan');
        $process = new Process([PHP_BINARY, $artisan, 'serve', "--port={$port}"]);
        $process->start(fn($type, $buf) => $this->output->write($buf));
        $this->info("Dev server started at http://localhost:{$port}");
        return $process;
    }
}
