<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
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
    ): int {
        $this->runStartup($refreshService, $fixtureService, $statsService, $eventsService, $lineupsService);

        if ($this->option('once')) {
            $this->runRefresh($refreshService);
            $this->runPendingLineups($lineupsService);
            $this->runLiveEvents($eventsService);
            $this->runLiveStats($statsService);
            $this->runPendingStats($statsService);
            $this->runPendingEvents($eventsService);
            return self::SUCCESS;
        }

        $server = null;
        if (!$this->option('skip-server')) {
            $server = $this->startDevServer();
        }

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
        }

        return self::SUCCESS;
    }

    private function runStartup(
        ApiFootballResultRefreshService       $refreshService,
        ApiFootballFixtureSyncService         $fixtureService,
        ApiFootballMatchStatisticsSyncService $statsService,
        ApiFootballMatchEventSyncService      $eventsService,
        ApiFootballMatchLineupSyncService     $lineupsService,
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
