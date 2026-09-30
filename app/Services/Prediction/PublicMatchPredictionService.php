<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use Throwable;

/**
 * Public-facing 1X2 prediction for the match page (P17B).
 *
 * Primary model: ROBETTING CANDIDATE V2 LOG (candidate47_structural_log, P18D).
 * Technical fallback: FULL59 production, only when Candidate V2 LOG is unavailable.
 *
 * No inference logic lives here: features come from
 * MatchPredictionService::predictWithDebug() and all models run through
 * CandidateModelService::compare() — the exact same path used by the Admin
 * prediction engine page, so the numbers are identical by construction.
 *
 * Returns null (no public block) for non pre-match fixtures or when not even
 * the FULL59 fallback can be computed. No DB writes.
 */
class PublicMatchPredictionService
{
    public const MODEL_CANDIDATE_V2 = 'candidate47_structural_log';
    public const MODEL_FULL59       = 'full59';

    public function __construct(
        private readonly MatchPredictionService $predictionService,
        private readonly CandidateModelService  $candidateService,
    ) {}

    /**
     * @return array{
     *     model_used: string,
     *     fallback_used: bool,
     *     lambda_home: float,
     *     lambda_away: float,
     *     lambda3: float|null,
     *     probability_home: float,
     *     probability_draw: float,
     *     probability_away: float,
     *     fair_odds_home: float|null,
     *     fair_odds_draw: float|null,
     *     fair_odds_away: float|null,
     * }|null
     */
    public function predict(FootballMatch $match): ?array
    {
        if (! $this->isPreMatch($match)) {
            return null;
        }

        try {
            $base = $this->predictionService->predictWithDebug($match);
        } catch (Throwable) {
            return null;
        }

        $comparison = null;
        try {
            $comparison = $this->candidateService->compare(
                $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
            );
        } catch (Throwable) {
            // Candidate models unavailable — fall back to FULL59 below.
        }

        if (($comparison['candidate47_structural_log_available'] ?? false) && $comparison['candidate47_structural_log'] !== null) {
            return $this->format($comparison['candidate47_structural_log'], self::MODEL_CANDIDATE_V2, false);
        }

        return $this->format($comparison['full59'] ?? $base, self::MODEL_FULL59, true);
    }

    private function isPreMatch(FootballMatch $match): bool
    {
        return $match->status === 'scheduled'
            && $match->kickoff_at !== null
            && $match->kickoff_at->isFuture();
    }

    private function format(array $p, string $modelUsed, bool $fallbackUsed): array
    {
        $odds = fn (float $prob): ?float => $prob > 0.0 ? 1.0 / $prob : null;

        return [
            'model_used'       => $modelUsed,
            'fallback_used'    => $fallbackUsed,
            'lambda_home'      => (float) $p['lambda_home'],
            'lambda_away'      => (float) $p['lambda_away'],
            'lambda3'          => isset($p['lambda3']) ? (float) $p['lambda3'] : null,
            'probability_home' => (float) $p['probability_home'],
            'probability_draw' => (float) $p['probability_draw'],
            'probability_away' => (float) $p['probability_away'],
            'fair_odds_home'   => $odds((float) $p['probability_home']),
            'fair_odds_draw'   => $odds((float) $p['probability_draw']),
            'fair_odds_away'   => $odds((float) $p['probability_away']),
        ];
    }
}
