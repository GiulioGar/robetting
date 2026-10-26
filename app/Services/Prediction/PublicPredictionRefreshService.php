<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Step 1A.4 — generates/refreshes PUBLIC_LATEST (portal-facing) predictions.
 *
 * Deliberately separate from OfficialPredictionCaptureService (Learning
 * Loop): that service's persistence (append-only via OfficialPredictionRecorder)
 * and idempotency (per exact kickoff_at, never per day) are wrong for a
 * value meant to be refreshed daily. This service reuses its
 * findEligibleMatches() AS-IS (same 5-core-leagues/current-season/scheduled/
 * kickoff-window policy, single source of truth, never duplicated) but never
 * calls its capture()/preClassify() — persistence here goes exclusively
 * through PublicPredictionRecorder.
 *
 * Model: candidate47_structural_log ONLY, via the exact same validated path
 * CandidateModelService::officialPredictionData() already uses for Learning
 * Loop capture (same feature resolution, same inferRobustBP() math). NEVER
 * falls back to FULL59 — a match with no Candidate47 available is counted
 * unavailable, never given a substitute prediction.
 */
class PublicPredictionRefreshService
{
    public const MODEL_KEY = OfficialPredictionCaptureService::MODEL_KEY;

    public function __construct(
        private readonly MatchPredictionService $predictionService,
        private readonly CandidateModelService $candidateService,
        private readonly OfficialPredictionCaptureService $eligibilityService,
    ) {}

    /**
     * @return array{eligible: int, created: int, updated: int, skipped_today: int, unavailable: int, errors: int}
     */
    public function refresh(CarbonImmutable $now, int $days): array
    {
        $matches = $this->eligibilityService->findEligibleMatches($now, $days * 24);

        $counts = [
            'eligible'      => 0,
            'created'       => 0,
            'updated'       => 0,
            'skipped_today' => 0,
            'unavailable'   => 0,
            'errors'        => 0,
        ];

        foreach ($matches as $match) {
            $counts['eligible']++;
            $this->processMatch($match, $now, $counts);
        }

        return $counts;
    }

    /**
     * @param array{eligible: int, created: int, updated: int, skipped_today: int, unavailable: int, errors: int} $counts
     */
    private function processMatch(FootballMatch $match, CarbonImmutable $now, array &$counts): void
    {
        try {
            // Cheap pre-check (one query) BEFORE running the full pipeline —
            // this is exactly the cost this whole refresh mechanism exists to
            // avoid paying for matches already fresh today.
            if (PublicPredictionRecorder::alreadyUpdatedToday($match->id, self::MODEL_KEY, $now)) {
                $counts['skipped_today']++;
                return;
            }

            $resolved = $this->resolveCandidate47Data($match);

            if ($resolved['status'] === 'error' || $resolved['status'] === 'unavailable') {
                $counts[$resolved['status'] === 'error' ? 'errors' : 'unavailable']++;
                return;
            }

            $official = $resolved['data'];

            $result = PublicPredictionRecorder::upsert([
                'match_id'                     => $match->id,
                'model_key'                    => $official['model_key'],
                'model_version'                => $official['model_version'],
                'feature_set_version'          => $official['feature_set_version'],
                'artifact_path'                => $official['artifact_path'],
                'kickoff_at'                   => $match->kickoff_at,
                'lambda_home'                  => $official['lambda_home'],
                'lambda_away'                  => $official['lambda_away'],
                'lambda3'                      => $official['lambda3'],
                'probability_home'             => $official['probability_home'],
                'probability_draw'             => $official['probability_draw'],
                'probability_away'             => $official['probability_away'],
                'features_json'                => $official['features_json'],
                'availability_snapshot_json'   => AvailabilitySnapshotBuilder::buildOrNull($match, $now),
                'structural_snapshot_version'  => $official['structural_snapshot_generated_at'],
                'latent_snapshot_generated_at' => $official['latent_snapshot_generated_at'],
            ], $now);

            match ($result['outcome']) {
                'CREATED'       => $counts['created']++,
                'UPDATED'       => $counts['updated']++,
                'SKIPPED_TODAY' => $counts['skipped_today']++,
            };
        } catch (Throwable) {
            $counts['errors']++;
        }
    }

    /**
     * Resolve the Candidate47 (candidate47_structural_log) payload for a
     * match, WITHOUT persisting anything — mirrors
     * OfficialPredictionCaptureService::resolveOfficialData() (private there,
     * so re-implemented here rather than modifying that class) but this copy
     * exists purely to feed PublicPredictionRecorder, never
     * OfficialPredictionRecorder.
     *
     * @return array{status: 'ok', data: array}|array{status: 'unavailable'|'error', reason: string}
     */
    private function resolveCandidate47Data(FootballMatch $match): array
    {
        try {
            $base = $this->predictionService->predictWithDebug($match);
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => 'feature extraction failed: ' . $e->getMessage()];
        }

        try {
            $official = $this->candidateService->officialPredictionData(
                $base['features_candidate47'] ?? $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
            );
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => 'officialPredictionData() failed: ' . $e->getMessage()];
        }

        if ($official === null) {
            return ['status' => 'unavailable', 'reason' => 'Structural/Latent snapshot mancante o squadra non presente nello snapshot'];
        }

        return ['status' => 'ok', 'data' => $official];
    }
}
