<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use Illuminate\Support\Facades\Log;

/**
 * Manual/debug orchestrator: updates all available API-Football data for one match.
 * Resolves the external fixture ID once, then delegates to the existing atomic services.
 * Each component (result, lineup, events, statistics) is attempted independently —
 * a failure in one never prevents the others from running.
 */
class ApiFootballMatchUpdateService
{
    public function __construct(
        private readonly ApiFootballResultRefreshService       $resultRefresh,
        private readonly ApiFootballMatchLineupSyncService     $lineupSync,
        private readonly ApiFootballMatchEventSyncService      $eventSync,
        private readonly ApiFootballMatchStatisticsSyncService $statsSync,
    ) {}

    /**
     * Update all available data for a single match.
     *
     * Routing by match status:
     *   scheduled → result + lineup only (events/stats not available yet)
     *   live      → result + lineup + events (live) + statistics (live)
     *   finished  → result + lineup + events (post-match) + statistics (post-match)
     *
     * @return array{
     *   status: string,
     *   api_calls: int,
     *   result: array|null,
     *   lineup: array|null,
     *   events: array|null,
     *   statistics: array|null,
     *   warnings: list<string>,
     * }
     */
    public function update(FootballMatch $match): array
    {
        $ds    = DataSource::where('slug', 'api-football')->firstOrFail();
        $extId = MatchExternalId::where('data_source_id', $ds->id)
            ->where('match_id', $match->id)
            ->value('external_id');

        if ($extId === null) {
            Log::warning("api-football-manual-update: match {$match->id} has no api-football external ID");
            return [
                'status'     => 'error',
                'reason'     => 'no_external_id',
                'api_calls'  => 0,
                'result'     => null,
                'lineup'     => null,
                'events'     => null,
                'statistics' => null,
                'warnings'   => ['no api-football external ID for this match'],
            ];
        }

        $isDefinitive = in_array($match->status, ApiFootballFixtureSyncService::DEFINITIVE_STATUSES, true);
        $isLive       = $match->status === 'live';

        $apiCalls = 0;
        $warnings = [];

        // ── 1. Result / fixture refresh ──────────────────────────────────────
        $resultOutcome = null;
        try {
            $resultOutcome = $this->resultRefresh->refreshSingle($match, $extId);
            $apiCalls     += $resultOutcome['api_calls'] ?? 0;
        } catch (ApiFootballException $e) {
            $warnings[]    = "result: {$e->getMessage()}";
            $resultOutcome = ['outcome' => 'error', 'api_calls' => 0];
        }

        // ── 2. Lineup ─────────────────────────────────────────────────────────
        // Manual mode: no window constraint — useful for finished matches too.
        $lineupOutcome = $this->lineupSync->syncSingle($match, $extId);
        $apiCalls     += $lineupOutcome['api_calls'] ?? 0;
        if (in_array($lineupOutcome['outcome'] ?? '', ['http_error', 'unparsable'], true)) {
            $warnings[] = "lineup: {$lineupOutcome['outcome']}";
        }

        // ── 3. Events ─────────────────────────────────────────────────────────
        $eventsOutcome = null;
        if ($isDefinitive) {
            try {
                $eventsOutcome = $this->eventSync->syncSingle($match, $extId);
                $apiCalls     += $eventsOutcome['api_calls'] ?? 0;
            } catch (ApiFootballException $e) {
                $warnings[]    = "events: {$e->getMessage()}";
                $eventsOutcome = ['outcome' => 'error', 'api_calls' => 0];
            }
        } elseif ($isLive) {
            try {
                $eventsOutcome = $this->eventSync->syncLiveSingle($match, $extId);
                $apiCalls     += $eventsOutcome['api_calls'] ?? 0;
            } catch (ApiFootballException $e) {
                $warnings[]    = "events: {$e->getMessage()}";
                $eventsOutcome = ['outcome' => 'error', 'api_calls' => 0];
            }
        } else {
            $eventsOutcome = ['outcome' => 'skipped_scheduled'];
        }

        // ── 4. Statistics ─────────────────────────────────────────────────────
        $statsOutcome = null;
        if ($isDefinitive) {
            try {
                $statsOutcome = $this->statsSync->syncSingle($match, $extId);
                $apiCalls    += $statsOutcome['api_calls'] ?? 0;
            } catch (ApiFootballException $e) {
                $warnings[]   = "statistics: {$e->getMessage()}";
                $statsOutcome = ['outcome' => 'error', 'api_calls' => 0];
            }
        } elseif ($isLive) {
            try {
                $statsOutcome = $this->statsSync->syncLiveSingle($match, $extId);
                $apiCalls    += $statsOutcome['api_calls'] ?? 0;
            } catch (ApiFootballException $e) {
                $warnings[]   = "statistics: {$e->getMessage()}";
                $statsOutcome = ['outcome' => 'error', 'api_calls' => 0];
            }
        } else {
            $statsOutcome = ['outcome' => 'skipped_scheduled'];
        }

        return [
            'status'     => empty($warnings) ? 'ok' : 'partial',
            'api_calls'  => $apiCalls,
            'result'     => $resultOutcome,
            'lineup'     => $lineupOutcome,
            'events'     => $eventsOutcome,
            'statistics' => $statsOutcome,
            'warnings'   => $warnings,
        ];
    }
}
