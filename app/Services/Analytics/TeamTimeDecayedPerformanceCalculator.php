<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Recent Performance with Time Decay v1 (E12).
 *
 * Computes exponentially weighted averages of recent team performance metrics,
 * giving more weight to matches closer in time to the target kickoff.
 *
 * ── Decay formula ────────────────────────────────────────────────────────────
 *
 *   days_ago = (target_kickoff_at − match_kickoff_at) in fractional days
 *   weight   = 0.5 ^ (days_ago / half_life_days)
 *
 *   Examples at half_life = 28:
 *     0 days ago  → weight 1.000
 *    28 days ago  → weight 0.500
 *    56 days ago  → weight 0.250
 *    90 days ago  → weight 0.108
 *
 * ── Window selection ─────────────────────────────────────────────────────────
 *
 *   1. Strictly before target kickoff (anti-leakage; caller should pre-filter).
 *   2. Within max_horizon_days (default 90) — excludes stale pre-season form.
 *   3. Most recent max_matches (default 10) after applying the horizon cap.
 *
 *   No venue split: all-venue window is empirically stronger than home/away-only
 *   with the available sample size (see E12 empirical analysis, 2026-09-14).
 *
 * ── Metrics ──────────────────────────────────────────────────────────────────
 *
 *   Raw (team-POV, positive = better for observed team):
 *     goal_diff  = team_goals_ft − opponent_goals_ft          (always available)
 *     shot_diff  = team_shots − opponent_shots                 (requires stats row)
 *     sot_diff   = team_sot  − opponent_sot                    (requires stats row)
 *
 *   Adjusted (opponent-quality correction from E9/E10 Elo context):
 *     adjusted_metric = raw_metric + coeff(metric) × (opponent_elo − league_mean_elo)
 *     Coefficients read from config('analytics.opponent_adjustment.coefficients').
 *     When no Elo context is available for a match, delta = 0 → adjusted = raw.
 *
 * ── Null semantics ───────────────────────────────────────────────────────────
 *
 *   Per-metric weighted average denominator = Σ weight_i over non-null values only.
 *   Null (unavailable stat) is never promoted to 0.
 *   When all values for a metric are null → weighted output = null.
 *
 * ── Relationship to E8 ───────────────────────────────────────────────────────
 *
 *   E8 uses flat (unweighted) Last-5 / Last-10 windows and is empirically the
 *   stronger predictor on the Robetting dataset.  E12 is a complementary
 *   candidate feature for future feature-selection — it does NOT replace E8.
 *
 * ── Performance ──────────────────────────────────────────────────────────────
 *
 *   0 additional DB queries.  All input (match history, stats, Elo context) is
 *   passed by the caller from existing collections.
 */
class TeamTimeDecayedPerformanceCalculator
{
    private const METRICS = ['goal_diff', 'shot_diff', 'sot_diff'];

    /** Maps each metric to its output coverage key. */
    private const COVERAGE_KEY = [
        'goal_diff' => 'goal_matches_available',
        'shot_diff' => 'shot_matches_available',
        'sot_diff'  => 'sot_matches_available',
    ];

    /**
     * Compute time-decayed performance for a team relative to a target match.
     *
     * @param  int               $teamId         The observed team.
     * @param  Collection        $matchHistory   Pre-match history ordered any way;
     *                                           must be strictly before $targetKickoff
     *                                           (caller's responsibility — this method
     *                                           guards with an additional < check).
     * @param  \DateTimeInterface $targetKickoff  Kickoff of the target match.
     * @param  array             $eloContext     From E9 '_elo_context': keyed by match_id,
     *                                           each entry {home_elo, away_elo, league_mean_elo}.
     *                                           Empty array is a valid (neutral-fallback) input.
     * @param  Collection        $statsMap       Keyed by match_id; must expose home_shots,
     *                                           away_shots, home_shots_on_target,
     *                                           away_shots_on_target (nullable).
     *
     * @return array{
     *   matches_considered: int,
     *   half_life_days: int,
     *   max_horizon_days: int,
     *   effective_weight_sum: float,
     *   weighted_goal_diff: float|null,
     *   weighted_shot_diff: float|null,
     *   weighted_sot_diff: float|null,
     *   weighted_adjusted_goal_diff: float|null,
     *   weighted_adjusted_shot_diff: float|null,
     *   weighted_adjusted_sot_diff: float|null,
     *   goal_matches_available: int,
     *   shot_matches_available: int,
     *   sot_matches_available: int,
     * }
     */
    public static function calculate(
        int               $teamId,
        Collection        $matchHistory,
        \DateTimeInterface $targetKickoff,
        array             $eloContext,
        Collection        $statsMap
    ): array {
        $cfg        = config('analytics.recent_time_decay');
        $halfLife   = (float) ($cfg['half_life_days']   ?? 28);
        $maxMatches = (int)   ($cfg['max_matches']      ?? 10);
        $maxHorizon = (int)   ($cfg['max_horizon_days'] ?? 90);

        $targetTs = Carbon::instance($targetKickoff);

        // Select window: strictly before target, within horizon, most recent N.
        $window = $matchHistory
            ->filter(function ($m) use ($targetTs, $maxHorizon): bool {
                if ($m->kickoff_at === null || ! $m->kickoff_at->lt($targetTs)) {
                    return false;
                }
                $daysAgo = $m->kickoff_at->diffInSeconds($targetTs) / 86400.0;
                return $daysAgo <= $maxHorizon;
            })
            ->sortByDesc('kickoff_at')
            ->take($maxMatches)
            ->values();

        if ($window->isEmpty()) {
            return self::emptyResult((int) $halfLife, $maxHorizon);
        }

        $coefficients = config('analytics.opponent_adjustment.coefficients', []);

        // Per-metric accumulators: weighted sum and weight sum over non-null values.
        $weightedRawSum  = array_fill_keys(self::METRICS, 0.0);
        $weightedAdjSum  = array_fill_keys(self::METRICS, 0.0);
        $weightSumRaw    = array_fill_keys(self::METRICS, 0.0);
        $weightSumAdj    = array_fill_keys(self::METRICS, 0.0);
        $coverage        = array_fill_keys(self::METRICS, 0);
        $effectiveWeightSum = 0.0;

        foreach ($window as $m) {
            $daysAgo = $m->kickoff_at->diffInSeconds($targetTs) / 86400.0;
            $weight  = pow(0.5, $daysAgo / $halfLife);
            $effectiveWeightSum += $weight;

            $isHome = (int) $m->home_team_id === $teamId;
            $stat   = $statsMap->get($m->id);
            $raw    = self::rawMetrics($m, $isHome, $stat);

            // Elo delta for opponent-quality adjustment (E9 context).
            $eloDelta = self::eloAdjustmentDelta($m->id, $isHome, $eloContext);

            foreach (self::METRICS as $metric) {
                $rawVal = $raw[$metric];
                if ($rawVal === null) {
                    continue; // null not counted in denominator for this metric
                }

                $adjVal = $rawVal + ($coefficients[$metric] ?? 0.0) * $eloDelta;

                $weightedRawSum[$metric] += $rawVal * $weight;
                $weightSumRaw[$metric]   += $weight;
                $weightedAdjSum[$metric] += $adjVal * $weight;
                $weightSumAdj[$metric]   += $weight;
                $coverage[$metric]++;
            }
        }

        $result = [
            'matches_considered'   => $window->count(),
            'half_life_days'       => (int) $halfLife,
            'max_horizon_days'     => $maxHorizon,
            'effective_weight_sum' => round($effectiveWeightSum, 4),
        ];

        foreach (self::METRICS as $metric) {
            $result['weighted_' . $metric] = $weightSumRaw[$metric] > 0.0
                ? round($weightedRawSum[$metric] / $weightSumRaw[$metric], 4)
                : null;

            $result['weighted_adjusted_' . $metric] = $weightSumAdj[$metric] > 0.0
                ? round($weightedAdjSum[$metric] / $weightSumAdj[$metric], 4)
                : null;

            $result[self::COVERAGE_KEY[$metric]] = $coverage[$metric];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Raw POV-oriented diffs for all three metrics from a single match.
     *
     * goal_diff: always computable from FT score.
     * shot_diff / sot_diff: null when stats row is absent or columns are null.
     *
     * @return array{goal_diff: float, shot_diff: float|null, sot_diff: float|null}
     */
    private static function rawMetrics(object $match, bool $isHome, ?object $stat): array
    {
        $homeGoals = (int) $match->home_score_ft;
        $awayGoals = (int) $match->away_score_ft;
        $goalDiff  = $isHome ? ($homeGoals - $awayGoals) : ($awayGoals - $homeGoals);

        $shotDiff = null;
        $sotDiff  = null;

        if ($stat !== null) {
            if ($stat->home_shots !== null && $stat->away_shots !== null) {
                $rawDiff  = (int) $stat->home_shots - (int) $stat->away_shots;
                $shotDiff = $isHome ? (float) $rawDiff : (float) -$rawDiff;
            }
            if ($stat->home_shots_on_target !== null && $stat->away_shots_on_target !== null) {
                $rawDiff = (int) $stat->home_shots_on_target - (int) $stat->away_shots_on_target;
                $sotDiff = $isHome ? (float) $rawDiff : (float) -$rawDiff;
            }
        }

        return [
            'goal_diff' => (float) $goalDiff,
            'shot_diff' => $shotDiff,
            'sot_diff'  => $sotDiff,
        ];
    }

    /**
     * Compute opponent Elo delta from E9 context for a single match.
     *
     * opponent_elo_delta = opponent_pre_match_elo − league_mean_elo_pre_match
     *
     * Returns 0.0 (neutral) when context is absent or incomplete.
     */
    private static function eloAdjustmentDelta(int $matchId, bool $isHome, array $eloContext): float
    {
        $entry = $eloContext[$matchId] ?? null;
        if ($entry === null) {
            return 0.0;
        }

        $opponentElo   = $isHome ? ($entry['away_elo'] ?? null) : ($entry['home_elo'] ?? null);
        $leagueMeanElo = $entry['league_mean_elo'] ?? null;

        if ($opponentElo === null || $leagueMeanElo === null) {
            return 0.0;
        }

        return $opponentElo - $leagueMeanElo;
    }

    private static function emptyResult(int $halfLife, int $maxHorizon): array
    {
        $result = [
            'matches_considered'   => 0,
            'half_life_days'       => $halfLife,
            'max_horizon_days'     => $maxHorizon,
            'effective_weight_sum' => 0.0,
        ];

        foreach (self::METRICS as $metric) {
            $result['weighted_' . $metric]          = null;
            $result['weighted_adjusted_' . $metric] = null;
            $result[self::COVERAGE_KEY[$metric]]    = 0;
        }

        return $result;
    }
}
