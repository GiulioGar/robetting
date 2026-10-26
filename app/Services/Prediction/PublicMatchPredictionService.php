<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Analytics\PreMatchFeatureAggregator;
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
 *
 * Runtime semantics (all requests): recentWindowMode=team_history + eloMode=extended,
 * Candidate47 artifact resolved by CandidateModelService (Team History Recent file
 * when present). UEFA club competitions additionally use the Champions profile below
 * (without exposing the diagnostic profile unless ?debug_predict=1).
 *
 * Step C-DEBUG-EXPERIMENTAL ($experimental=true, driven ONLY by MatchController's
 * ?debug_predict=1): forces the Champions profile for any match, with recentWindowMode=team_history + eloMode=extended on the
 * SAME injected $predictionService (its PreMatchFeatureAggregator call takes the
 * mode as a parameter — no duplicated aggregation logic), and a freshly
 * constructed, request-local CandidateModelService pointed at the 3 experimental
 * artifacts via its constructor overrides — the shared/injected $candidateService
 * instance (used by every other caller: official predictions, Learning Loop,
 * admin page) is NEVER touched or reconfigured. Normal (non-debug) requests never
 * take this branch — identical behaviour to before this step.
 */
class PublicMatchPredictionService
{
    public const MODEL_CANDIDATE_V2 = 'candidate47_structural_log';
    public const MODEL_FULL59       = 'full59';

    private const UEFA_CLUB_SLUGS = ['uefa-champions-league', 'uefa-europa-league', 'uefa-conference-league'];

    private const EXPERIMENTAL_CAND47_ARTIFACT    = 'prediction_engine_candidate47_team_history_recent.json';
    private const EXPERIMENTAL_LATENT_SNAPSHOT     = 'latent_strength_champions_anchored.json';
    private const EXPERIMENTAL_STRUCTURAL_SNAPSHOT = 'structural_strength_champions_extended.json';

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
    public function predict(FootballMatch $match, bool $experimental = false): ?array
    {
        if (! $this->isPreMatch($match)) {
            return null;
        }

        // Feature routing (MatchPredictionService::predictWithDebug): 'features' keeps
        // the legacy semantics FULL59 was trained with; 'features_candidate47' is
        // Recent team_history + Elo extended and feeds ONLY Candidate47 Team History.
        $recentWindowMode = PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY;
        $eloMode          = PreMatchFeatureAggregator::ELO_MODE_EXTENDED;

        try {
            $base = $this->predictionService->predictWithDebug($match);
        } catch (Throwable) {
            return null;
        }

        // Champions profile (anchored latent + extended structural snapshots):
        // UEFA club competitions, or any match under ?debug_predict=1.
        if ($experimental || $this->isUefaClubCompetition($match)) {
            return $this->predictExperimental($match, $base, $recentWindowMode, $eloMode, $experimental);
        }

        $comparison = null;
        try {
            $comparison = $this->candidateService->compare(
                $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
                $base['features_candidate47'] ?? null,
            );
        } catch (Throwable) {
            // Candidate models unavailable — fall back to FULL59 below.
        }

        if (($comparison['candidate47_structural_log_available'] ?? false) && $comparison['candidate47_structural_log'] !== null) {
            return $this->format($comparison['candidate47_structural_log'], self::MODEL_CANDIDATE_V2, false, null, null);
        }

        // Diagnostic-only (never affects which model/probabilities are used above):
        // explain WHY Candidate47 fell back, for display on the match page.
        $fallbackReason = $this->candidateService->diagnoseLatentStructuralAvailability(
            (int) $match->home_team_id,
            (int) $match->away_team_id,
            (string) $match->kickoff_at,
        );

        return $this->format($comparison['full59'] ?? $base, self::MODEL_FULL59, true, $fallbackReason, null);
    }

    /**
     * Step C-DEBUG-EXPERIMENTAL — ?debug_predict=1 ONLY. Reuses compare()/
     * diagnoseLatentStructuralAvailability() verbatim (no duplicated Candidate47
     * logic) on a freshly-constructed CandidateModelService pointed at the 3
     * experimental artifacts. Requirement: a missing/invalid experimental
     * artifact must NEVER silently fall through to the production files —
     * checked explicitly below BEFORE constructing/calling the experimental
     * service, so the error surfaced in $fallback_reason always names exactly
     * which experimental file(s) are missing, never a generic/ambiguous one.
     */
    private function predictExperimental(FootballMatch $match, array $base, string $recentWindowMode, string $eloMode, bool $exposeProfile = true): array
    {
        $profile = $exposeProfile ? [
            'recent_window_mode'  => $recentWindowMode,
            'elo_mode'            => $eloMode,
            'cand47_artifact'     => self::EXPERIMENTAL_CAND47_ARTIFACT,
            'latent_snapshot'     => self::EXPERIMENTAL_LATENT_SNAPSHOT,
            'structural_snapshot' => self::EXPERIMENTAL_STRUCTURAL_SNAPSHOT,
        ] : null;

        $filenames = [
            self::EXPERIMENTAL_CAND47_ARTIFACT,
            self::EXPERIMENTAL_LATENT_SNAPSHOT,
            self::EXPERIMENTAL_STRUCTURAL_SNAPSHOT,
        ];
        $missing = array_values(array_filter(
            $filenames,
            static fn (string $f): bool => ! file_exists(base_path('tools/models/' . $f))
        ));

        if ($missing !== []) {
            return $this->format($base, self::MODEL_FULL59, true, [
                'experimental_artifacts_missing' => $missing,
            ], $profile);
        }

        // Request-local instance only — the shared/injected $this->candidateService
        // (official predictions, Learning Loop, admin page) is never touched.
        $experimentalCandidateService = new CandidateModelService(
            base_path('tools/models/' . self::EXPERIMENTAL_CAND47_ARTIFACT),
            base_path('tools/models/' . self::EXPERIMENTAL_LATENT_SNAPSHOT),
            base_path('tools/models/' . self::EXPERIMENTAL_STRUCTURAL_SNAPSHOT),
        );

        $comparison = null;
        try {
            $comparison = $experimentalCandidateService->compare(
                $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
                $base['features_candidate47'] ?? null,
            );
        } catch (Throwable) {
        }

        if (($comparison['candidate47_structural_log_available'] ?? false) && $comparison['candidate47_structural_log'] !== null) {
            return $this->format($comparison['candidate47_structural_log'], self::MODEL_CANDIDATE_V2, false, null, $profile);
        }

        $fallbackReason = $experimentalCandidateService->diagnoseLatentStructuralAvailability(
            (int) $match->home_team_id,
            (int) $match->away_team_id,
            (string) $match->kickoff_at,
        );

        return $this->format($comparison['full59'] ?? $base, self::MODEL_FULL59, true, $fallbackReason, $profile);
    }

    private function isUefaClubCompetition(FootballMatch $match): bool
    {
        $slug = (string) $match->competition?->slug;

        return in_array($slug, self::UEFA_CLUB_SLUGS, true);
    }

    private function isPreMatch(FootballMatch $match): bool
    {
        return $match->status === 'scheduled'
            && $match->kickoff_at !== null
            && $match->kickoff_at->isFuture();
    }

    private function format(array $p, string $modelUsed, bool $fallbackUsed, ?array $fallbackReason, ?array $experimentalProfile): array
    {
        $odds = fn (float $prob): ?float => $prob > 0.0 ? 1.0 / $prob : null;

        return [
            'model_used'       => $modelUsed,
            'fallback_used'    => $fallbackUsed,
            // Diagnostic only — never read by any inference/probability logic.
            'fallback_reason'  => $fallbackReason,
            // Diagnostic only, null outside ?debug_predict=1 — never affects
            // which model/probabilities were computed above.
            'experimental_profile' => $experimentalProfile,
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
