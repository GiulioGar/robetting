<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;

/**
 * P27C — second ACTIVE component of the Learning Loop: link the real match
 * result to official predictions already saved (P15/P26B/P27B), once the
 * match becomes definitive.
 *
 * Purely additive: reuses OfficialPredictionRecorder::recordResult() as-is
 * (P15) — no duplicated update logic, no new write path. Works EXCLUSIVELY
 * on data already in the DB (football_matches, predictions) — no coupling
 * to ApiFootballFixtureSyncService/ApiFootballResultRefreshService beyond
 * reading the same DEFINITIVE_STATUSES policy constant they already define,
 * so "definitive" can never silently diverge between the sync layer and
 * this result-capture layer.
 *
 * Definitive status policy is NOT re-invented here: it reuses
 * ApiFootballFixtureSyncService::DEFINITIVE_STATUSES (finished/awarded/
 * walkover), the exact same list already used for fixture sync REFRESH mode
 * and for Latent fit eligibility elsewhere in the project.
 *
 * awarded/walkover are captured like any other definitive result (a
 * historical fact), but `match_status_at_result` — already part of the P15
 * schema — records the real status string, so a future Evaluation/Learning
 * Dataset step can filter them out without any new column.
 */
class OfficialPredictionResultCaptureService
{
    /**
     * Distinct match_ids that have at least one official prediction row,
     * oldest first (stable, deterministic iteration order).
     *
     * @return array<int>
     */
    public function matchIdsWithPredictions(): array
    {
        return Prediction::query()
            ->distinct()
            ->orderBy('match_id')
            ->pluck('match_id')
            ->all();
    }

    /**
     * Classify and, unless $dryRun, capture the result for one match's
     * official predictions. Never throws — callers (the command) can safely
     * loop over many matches without one failure affecting the others.
     *
     * @return array{outcome: 'ALREADY_RECORDED'|'WAITING_RESULT'|'INVALID_RESULT'|'RECORDED'|'WOULD_RECORD'|'ERROR', predictions_affected: int, reason?: string}
     */
    public function processMatch(int $matchId, bool $dryRun): array
    {
        try {
            $unresolvedCount = Prediction::where('match_id', $matchId)
                ->whereNull('home_goals')
                ->count();

            if ($unresolvedCount === 0) {
                return ['outcome' => 'ALREADY_RECORDED', 'predictions_affected' => 0];
            }

            $match = FootballMatch::find($matchId);
            if ($match === null) {
                return ['outcome' => 'ERROR', 'predictions_affected' => 0, 'reason' => "FootballMatch #{$matchId} not found"];
            }

            if (! in_array($match->status, ApiFootballFixtureSyncService::DEFINITIVE_STATUSES, true)) {
                return ['outcome' => 'WAITING_RESULT', 'predictions_affected' => $unresolvedCount];
            }

            if ($match->home_score_ft === null || $match->away_score_ft === null) {
                return ['outcome' => 'INVALID_RESULT', 'predictions_affected' => $unresolvedCount, 'reason' => 'definitive status but FT score missing'];
            }

            if ($dryRun) {
                return ['outcome' => 'WOULD_RECORD', 'predictions_affected' => $unresolvedCount];
            }

            $updated = OfficialPredictionRecorder::recordResult(
                $match->id,
                (int) $match->home_score_ft,
                (int) $match->away_score_ft,
                (string) $match->status,
            );

            return ['outcome' => 'RECORDED', 'predictions_affected' => $updated];
        } catch (\Throwable $e) {
            return ['outcome' => 'ERROR', 'predictions_affected' => 0, 'reason' => $e->getMessage()];
        }
    }
}
