<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * Opponent-Adjusted Performance v1.
 *
 * Corrects a team's recent shooting/goal metrics for the quality of the
 * opponents faced, based on calibrated OLS coefficients stored in config.
 *
 * ── Core formula (per match M) ────────────────────────────────────────────────
 *
 *   opponent_elo_delta(M) = opponent_pre_match_elo(M) − league_mean_elo_pre_match(M)
 *
 *   adjusted_metric(M) = raw_metric(M) + coeff(metric) × opponent_elo_delta(M)
 *
 * Interpretation:
 *   - Strong opponent (delta > 0) suppressed our raw output → adjustment pushes
 *     the value UP toward neutral-opponent level.
 *   - Weak opponent (delta < 0) inflated our raw output → adjustment pushes DOWN.
 *
 * ── Calibration ──────────────────────────────────────────────────────────────
 *
 *   Coefficients from config('analytics.opponent_adjustment.coefficients').
 *   Calibrated via OLS on 7 324 team-match observations (5 leagues, ~2.5 seasons),
 *   Model C: metric ~ elo_diff + is_home.  Details in config/analytics.php.
 *
 * ── Null semantics ───────────────────────────────────────────────────────────
 *
 *   Raw metric null (stats not available for that match) → adjusted also null.
 *   Null is never promoted to 0.
 *
 * ── POV orientation ──────────────────────────────────────────────────────────
 *
 *   All metrics are from the observed team's perspective (POV-positive = better
 *   for the observed team).  goal_diff = team_goals − opponent_goals, etc.
 *
 * ── Windows ──────────────────────────────────────────────────────────────────
 *
 *   Three pre-sliced windows: last5, last10, venue.  Passed in as Collections
 *   already filtered by the caller.  The $eloContext comes from
 *   TeamOpponentQualityCalculator (the '_elo_context' key), which computed
 *   league_mean_elo in the same Elo replay — zero additional DB queries.
 *
 * ── Output per window ────────────────────────────────────────────────────────
 *
 *   matches_considered          int     matches in this window
 *   [metric]_raw_avg            float?  unweighted average of raw per-match diffs
 *   [metric]_adjusted_avg       float?  unweighted average of adjusted diffs
 *   [metric]_avg_adjustment     float?  = adjusted_avg − raw_avg
 *   [metric]_coverage           int     matches where stats (and thus raw) ≠ null
 *   where metric ∈ {shot_diff, sot_diff, goal_diff}
 *
 * ── Performance ──────────────────────────────────────────────────────────────
 *
 *   0 additional DB queries.  All Elo data comes from $eloContext passed in.
 *   Match statistics looked up from $statsMap (Collection already loaded by
 *   MatchController for E-block rendering).
 */
class TeamOpponentAdjustedPerformanceCalculator
{
    /** @var string[] */
    private const METRICS = ['shot_diff', 'sot_diff', 'goal_diff'];

    /**
     * Compute opponent-adjusted performance for three pre-filtered windows.
     *
     * @param  int         $teamId      The observed team.
     * @param  Collection  $last5       Last-5 matches (all venues).
     * @param  Collection  $last10      Last-10 matches (all venues).
     * @param  Collection  $venue       Venue-filtered last-5.
     * @param  array       $eloContext  Output of TeamOpponentQualityCalculator's
     *                                 '_elo_context' key.  Shape per match id:
     *                                 {home_elo, away_elo, league_mean_elo}.
     *                                 When empty (no league context) adjustments
     *                                 degrade to raw values (delta = 0).
     * @param  Collection  $statsMap    Keyed by match_id; each element must expose
     *                                 home_shots, away_shots, home_shots_on_target,
     *                                 away_shots_on_target (nullable).
     *
     * @return array{last5: array, last10: array, venue: array}
     */
    public static function calculate(
        int        $teamId,
        Collection $last5,
        Collection $last10,
        Collection $venue,
        array      $eloContext,
        Collection $statsMap
    ): array {
        return [
            'last5'  => self::computeWindow($last5,  $teamId, $eloContext, $statsMap),
            'last10' => self::computeWindow($last10, $teamId, $eloContext, $statsMap),
            'venue'  => self::computeWindow($venue,  $teamId, $eloContext, $statsMap),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private
    // ─────────────────────────────────────────────────────────────────────────

    private static function computeWindow(
        Collection $matches,
        int        $teamId,
        array      $eloContext,
        Collection $statsMap
    ): array {
        if ($matches->isEmpty()) {
            return self::emptyWindow();
        }

        $coefficients = config('analytics.opponent_adjustment.coefficients');

        // Accumulators: [metric => [raw[], adjusted[]]]
        $rawValues  = array_fill_keys(self::METRICS, []);
        $adjValues  = array_fill_keys(self::METRICS, []);
        $coverage   = array_fill_keys(self::METRICS, 0);

        foreach ($matches as $m) {
            $isHome = (int) $m->home_team_id === $teamId;

            // ── Elo delta ─────────────────────────────────────────────────
            $eloEntry       = $eloContext[$m->id] ?? null;
            $opponentElo    = null;
            $leagueMeanElo  = null;
            $eloDelta       = 0.0; // neutral fallback when no context

            if ($eloEntry !== null) {
                $opponentElo   = $isHome ? ($eloEntry['away_elo'] ?? null) : ($eloEntry['home_elo'] ?? null);
                $leagueMeanElo = $eloEntry['league_mean_elo'] ?? null;

                if ($opponentElo !== null && $leagueMeanElo !== null) {
                    $eloDelta = $opponentElo - $leagueMeanElo;
                }
            }

            // ── Raw metrics ───────────────────────────────────────────────
            $stat = $statsMap->get($m->id);

            $raw = self::rawMetrics($m, $isHome, $stat);

            // ── Per-metric accumulation ───────────────────────────────────
            foreach (self::METRICS as $metric) {
                $rawVal = $raw[$metric];

                if ($rawVal !== null) {
                    $coeff   = $coefficients[$metric] ?? 0.0;
                    $adjVal  = $rawVal + $coeff * $eloDelta;

                    $rawValues[$metric][]  = $rawVal;
                    $adjValues[$metric][]  = $adjVal;
                    $coverage[$metric]++;
                }
                // null raw → not included; adjusted also null for this match
            }
        }

        $count  = $matches->count();
        $result = ['matches_considered' => $count];

        foreach (self::METRICS as $metric) {
            $rawAvg = self::avg($rawValues[$metric]);
            $adjAvg = self::avg($adjValues[$metric]);

            $result[$metric . '_raw_avg']        = $rawAvg;
            $result[$metric . '_adjusted_avg']   = $adjAvg;
            $result[$metric . '_avg_adjustment'] = ($rawAvg !== null && $adjAvg !== null)
                ? round($adjAvg - $rawAvg, 4)
                : null;
            $result[$metric . '_coverage']       = $coverage[$metric];
        }

        return $result;
    }

    /**
     * Compute raw POV-oriented diffs for all three metrics from a single match.
     *
     * Goal diff uses FT score (always available on finished matches).
     * Shot/SOT diffs use the stats row (nullable per-column).
     *
     * @return array{shot_diff: float|null, sot_diff: float|null, goal_diff: float}
     */
    private static function rawMetrics(object $match, bool $isHome, ?object $stat): array
    {
        $homeGoals = (int) $match->home_score_ft;
        $awayGoals = (int) $match->away_score_ft;
        $goalDiff  = $isHome
            ? ($homeGoals - $awayGoals)
            : ($awayGoals - $homeGoals);

        $shotDiff = null;
        $sotDiff  = null;

        if ($stat !== null) {
            if ($stat->home_shots !== null && $stat->away_shots !== null) {
                $shotDiff = $isHome
                    ? ((int) $stat->home_shots - (int) $stat->away_shots)
                    : ((int) $stat->away_shots - (int) $stat->home_shots);
            }
            if ($stat->home_shots_on_target !== null && $stat->away_shots_on_target !== null) {
                $sotDiff = $isHome
                    ? ((int) $stat->home_shots_on_target - (int) $stat->away_shots_on_target)
                    : ((int) $stat->away_shots_on_target - (int) $stat->home_shots_on_target);
            }
        }

        return [
            'shot_diff'  => $shotDiff !== null ? (float) $shotDiff : null,
            'sot_diff'   => $sotDiff  !== null ? (float) $sotDiff  : null,
            'goal_diff'  => (float) $goalDiff,
        ];
    }

    private static function emptyWindow(): array
    {
        $result = ['matches_considered' => 0];
        foreach (self::METRICS as $metric) {
            $result[$metric . '_raw_avg']        = null;
            $result[$metric . '_adjusted_avg']   = null;
            $result[$metric . '_avg_adjustment'] = null;
            $result[$metric . '_coverage']       = 0;
        }
        return $result;
    }

    /** @param float[] $values */
    private static function avg(array $values): ?float
    {
        return empty($values) ? null : array_sum($values) / count($values);
    }
}
