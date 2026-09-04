<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;

/**
 * Manual admin orchestrator: runs all API-Football sync steps in sequence.
 * Each block is isolated — a failure never prevents subsequent blocks from running.
 * Mirrors ServeLocal's startup sequence but returns a structured report instead of
 * printing to console, and uses catchUp() (not refresh()) for a comprehensive update.
 *
 * Calendar stale threshold: same 36h as ServeLocal, keyed on any fixture_sync run.
 * Team sync is intentionally excluded — it is a separate, less-frequent operation.
 */
class ApiFootballFullUpdateService
{
    private const CALENDAR_STALE_HOURS = 36;

    public function __construct(
        private readonly ApiFootballResultRefreshService              $resultRefresh,
        private readonly ApiFootballFixtureSyncService                $fixtureSync,
        private readonly ApiFootballMatchLineupSyncService            $lineupSync,
        private readonly ApiFootballMatchEventSyncService             $eventSync,
        private readonly ApiFootballMatchStatisticsSyncService        $statsSync,
        private readonly ApiFootballMatchPlayerStatisticsSyncService  $playerStatsSync,
        private readonly ApiFootballInjurySyncService                 $injurySync,
    ) {}

    /**
     * Execute all sync steps and return a consolidated report.
     *
     * @return array{
     *   status: string,
     *   api_calls: int,
     *   daily_remaining: int|null,
     *   blocks: array<string, array>,
     * }
     */
    public function updateAll(): array
    {
        $apiCalls = 0;
        $blocks   = [];

        // 1. Result catch-up — all non-definitive past matches
        $blocks['result_catchup'] = $this->run(fn() => $this->resultRefresh->catchUp());
        $apiCalls += $blocks['result_catchup']['api_calls'] ?? 0;

        // 2. Calendar REFRESH — only when stale (mirrors ServeLocal::maybeRefreshCalendar)
        $blocks['calendar'] = $this->maybeRefreshCalendar();
        $apiCalls += $blocks['calendar']['api_calls'] ?? 0;

        // 3. Pending lineups (window: kickoff ±30–75 min, throttle 15 min)
        $blocks['lineups'] = $this->run(fn() => $this->lineupSync->syncPending());
        $apiCalls += $blocks['lineups']['api_calls'] ?? 0;

        // 4. Live events
        $blocks['events_live'] = $this->run(fn() => $this->eventSync->syncLive());
        $apiCalls += $blocks['events_live']['api_calls'] ?? 0;

        // 5. Live statistics
        $blocks['stats_live'] = $this->run(fn() => $this->statsSync->syncLive());
        $apiCalls += $blocks['stats_live']['api_calls'] ?? 0;

        // 6. Pending statistics post-match
        $blocks['stats_pending'] = $this->run(fn() => $this->statsSync->syncPending());
        $apiCalls += $blocks['stats_pending']['api_calls'] ?? 0;

        // 7. Pending events post-match
        $blocks['events_pending'] = $this->run(fn() => $this->eventSync->syncPending());
        $apiCalls += $blocks['events_pending']['api_calls'] ?? 0;

        // 8. Pending player statistics post-match (current season only)
        $blocks['player_stats'] = $this->run(fn() => $this->playerStatsSync->syncPending());
        $apiCalls += $blocks['player_stats']['api_calls'] ?? 0;

        // 9. Pending injuries (upcoming 7 days)
        $blocks['injuries'] = $this->run(fn() => $this->injurySync->syncPending());
        $apiCalls += $blocks['injuries']['api_calls'] ?? 0;

        $hasErrors = collect($blocks)->contains(fn($b) => ($b['status'] ?? '') === 'error');

        return [
            'status'          => $hasErrors ? 'partial' : 'ok',
            'api_calls'       => $apiCalls,
            'daily_remaining' => $this->extractDailyRemaining($blocks),
            'blocks'          => $blocks,
        ];
    }

    /**
     * Run a sync closure and catch any exception, returning a normalised error block.
     */
    private function run(\Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage(), 'api_calls' => 0];
        }
    }

    /**
     * Run a fixture REFRESH sync only when the last fixture_sync run is older than
     * CALENDAR_STALE_HOURS — identical logic to ServeLocal::maybeRefreshCalendar().
     */
    private function maybeRefreshCalendar(): array
    {
        $ds = DataSource::where('slug', 'api-football')->first();

        if (!$ds) {
            return ['status' => 'skipped_no_datasource', 'api_calls' => 0];
        }

        $lastSync = DataSyncRun::where('data_source_id', $ds->id)
            ->where('sync_type', 'fixture_sync')
            ->orderByDesc('started_at')
            ->first();

        $isStale = $lastSync === null || $lastSync->started_at->lt(now()->subHours(self::CALENDAR_STALE_HOURS));

        if (!$isStale) {
            return [
                'status'    => 'skipped_fresh',
                'api_calls' => 0,
                'last_run'  => $lastSync->started_at->toIso8601String(),
            ];
        }

        try {
            $season   = (int) date('Y');
            $result   = $this->fixtureSync->syncAllCompetitions($season, ApiFootballFixtureSyncService::MODE_REFRESH);
            $apiCalls = array_sum(array_column($result['results'] ?? [], 'api_calls'));
            return array_merge($result, ['status' => 'ok', 'api_calls' => $apiCalls]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'api_calls' => 0, 'error' => $e->getMessage()];
        }
    }

    private function extractDailyRemaining(array $blocks): ?int
    {
        foreach (array_reverse($blocks) as $block) {
            if (isset($block['daily_remaining']) && $block['daily_remaining'] !== null) {
                return (int) $block['daily_remaining'];
            }
        }
        return null;
    }
}
