<?php

namespace App\Services\Analytics;

use App\Models\Season;
use App\Models\TeamMarketValueSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Opponent Quality v1: how strong were the recent opponents?
 *
 * Returns two independent signals per match window:
 *
 *   1. Elo (dynamic) — opponent's rating immediately before that historical
 *      match's kickoff, via a single batch Elo replay.  Strict pre-match
 *      as-of: opponent Elo = rating available before that kickoff timestamp.
 *
 *   2. Structural (season baseline) — opponent's market-value-derived rating
 *      using the SEASON BASELINE snapshot rule:
 *
 *        a. The FIRST snapshot of the season is valid from the start of the
 *           season, even if the historical match pre-dates that snapshot.
 *        b. Each SUBSEQUENT snapshot becomes active from its own snapshot_date.
 *        c. Snapshots from OTHER seasons are NEVER used.
 *
 *      This reflects the post-transfer-window structural strength of each team
 *      for the full season, not a strict point-in-time value.
 *
 * ── Structural season baseline rule ─────────────────────────────────────────
 *
 *   season_range = [Season.start_date (or year_start-07-01) .. Season.end_date (or year_end-06-30)]
 *   snapshots    = all snapshots for opponent in season_range, ordered ASC
 *
 *   for a match on kickoff_date:
 *     applicable = last snapshot where snapshot_date ≤ kickoff_date
 *                  ?? first snapshot in season (= season opener baseline)
 *
 *   If no season snapshot exists at all → structural = null.
 *
 * ── Other invariants ─────────────────────────────────────────────────────────
 *
 *   - Signals are NEVER combined into a single score.
 *   - Match results are NOT factored in (no weighted wins/losses).
 *   - Structural formula delegates entirely to
 *     TeamStructuralRatingCalculator::calculateFromMarketValue() — no duplication.
 *
 * ── Performance ──────────────────────────────────────────────────────────────
 *
 *   2 DB queries per calculate() call (regardless of how many windows):
 *     1. TeamEloCalculator::calculateRatingsBeforeMatches — one Elo replay.
 *     2. Bulk structural snapshot load for all opponents in the season range;
 *        per-match selection applied in PHP (no N sub-queries).
 */
class TeamOpponentQualityCalculator
{
    /**
     * Compute opponent quality for three pre-filtered windows.
     *
     * $last5 and $last10 are the overall last-5 / last-10 collections already
     * sliced by the controller.  $venue is the venue-filtered last-5 (home-only
     * for the home team, away-only for the away team).
     *
     * $dataSourceId  = Transfermarkt data_sources.id.  Pass null to skip the
     *                  structural lookup entirely; all structural fields null.
     * $season        = the canonical Season model for these matches.  Its
     *                  start_date/end_date define the snapshot window; if those
     *                  fields are null the calculator falls back to year_start-07-01
     *                  / year_end-06-30.
     *
     * @return array{
     *   last5:  array,
     *   last10: array,
     *   venue:  array,
     * }
     */
    public static function calculate(
        int        $teamId,
        Collection $last5,
        Collection $last10,
        Collection $venue,
        ?int       $dataSourceId,
        Season     $season
    ): array {
        // Union of all matches to batch both Elo replay and structural lookup.
        // last5 ⊆ last10 for the overall windows; venue may differ.
        $allMatches = $last10->merge($venue)->unique('id');

        // ── Elo: one replay covers all windows ────────────────────────────────
        $eloMap = $allMatches->isNotEmpty()
            ? TeamEloCalculator::calculateRatingsBeforeMatches($allMatches)
            : [];

        // ── Structural: one bulk query, season-baseline selection in PHP ─────
        $structuralMap = self::loadStructuralBaselines($allMatches, $teamId, $dataSourceId, $season);

        return [
            'last5'  => self::computeWindow($last5,  $teamId, $eloMap, $structuralMap),
            'last10' => self::computeWindow($last10, $teamId, $eloMap, $structuralMap),
            'venue'  => self::computeWindow($venue,  $teamId, $eloMap, $structuralMap),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Opponent quality stats for a single match window.
     *
     * @param  array<int, array{home_elo: float, away_elo: float}>  $eloMap
     * @param  array<int, float|null>  $structuralMap  [match_id => structural_rating|null]
     */
    private static function computeWindow(
        Collection $matches,
        int        $teamId,
        array      $eloMap,
        array      $structuralMap
    ): array {
        if ($matches->isEmpty()) {
            return self::emptyWindow();
        }

        $eloValues        = [];
        $structuralValues = [];

        foreach ($matches as $m) {
            $isHome = (int) $m->home_team_id === $teamId;

            // Opponent's Elo immediately before this historical match's kickoff.
            $matchElo    = $eloMap[$m->id] ?? null;
            $eloValues[] = $matchElo !== null
                ? ($isHome ? $matchElo['away_elo'] : $matchElo['home_elo'])
                : TeamEloCalculator::INITIAL_ELO;

            // Opponent's structural as-of this match's kickoff (null if no prior snapshot).
            $structuralValues[] = $structuralMap[$m->id] ?? null;
        }

        $count               = $matches->count();
        $availableStructural = array_values(array_filter($structuralValues, fn ($v) => $v !== null));
        $structuralCount     = count($availableStructural);

        return [
            'matches_considered'             => $count,
            'average_opponent_elo'           => self::avg($eloValues),
            'median_opponent_elo'            => self::median($eloValues),
            'average_opponent_structural'    => $structuralCount > 0 ? self::avg($availableStructural) : null,
            'median_opponent_structural'     => $structuralCount > 0 ? self::median($availableStructural) : null,
            'structural_matches_available'   => $structuralCount,
            'structural_coverage_percentage' => self::coverage($structuralCount, $count),
        ];
    }

    /**
     * Bulk-load structural snapshots for all opponents in $allMatches, then
     * select the applicable snapshot per match using SEASON BASELINE semantics.
     *
     * Season baseline rule:
     *   - Only snapshots within the canonical season window are used.
     *     Window = [Season.start_date .. Season.end_date] when those fields are set;
     *     otherwise falls back to [{year_start}-07-01 .. {year_end}-06-30].
     *   - The first snapshot of the season is valid from the season opener
     *     (even if the historical match pre-dates that snapshot).
     *   - Each subsequent snapshot becomes active from its own snapshot_date.
     *
     * Algorithm per match:
     *   applicable = last snapshot where snapshot_date ≤ kickoff_date
     *                ?? first snapshot in season  (= season opener baseline)
     *
     * DB queries: 1.  Loads all in-season snapshots for all opponents in one query;
     * per-match selection is O(S) in PHP where S = snapshots per team.
     *
     * Returns [match_id => structural_rating|null].
     * Absent key = null (callers may use ?? null).
     */
    private static function loadStructuralBaselines(
        Collection $allMatches,
        int        $teamId,
        ?int       $dataSourceId,
        Season     $season
    ): array {
        if ($allMatches->isEmpty() || $dataSourceId === null) {
            return [];
        }

        $opponentIds = $allMatches
            ->map(fn ($m) => (int) $m->home_team_id === $teamId
                ? (int) $m->away_team_id
                : (int) $m->home_team_id)
            ->unique()
            ->values()
            ->all();

        if (empty($opponentIds)) {
            return [];
        }

        // Season snapshot window: use canonical Season dates when available,
        // fall back to conventional year_start-07-01 / year_end-06-30.
        $seasonStart = $season->start_date
            ? $season->start_date->toDateString()
            : Carbon::create($season->year_start, 7, 1)->toDateString();

        $seasonEnd = $season->end_date
            ? $season->end_date->toDateString()
            : Carbon::create($season->year_end, 6, 30)->toDateString();

        // One query: all in-season snapshots for all opponents, ordered ASC.
        // ASC ordering lets ->last(filter) find the latest valid one and ->first()
        // find the season opener baseline.
        $allSnapshots = TeamMarketValueSnapshot::where('data_source_id', $dataSourceId)
            ->whereIn('team_id', $opponentIds)
            ->whereBetween('snapshot_date', [$seasonStart, $seasonEnd])
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get(['team_id', 'snapshot_date', 'market_value'])
            ->groupBy('team_id'); // [team_id_string => Collection of snapshots ASC]

        $map = [];
        foreach ($allMatches as $m) {
            if ($m->kickoff_at === null) {
                $map[$m->id] = null;
                continue;
            }

            $opponentId  = (int) $m->home_team_id === $teamId
                ? (int) $m->away_team_id
                : (int) $m->home_team_id;
            $kickoffDate = $m->kickoff_at->toDateString(); // 'YYYY-MM-DD'

            $snaps = $allSnapshots->get((string) $opponentId);
            if ($snaps === null || $snaps->isEmpty()) {
                $map[$m->id] = null;
                continue;
            }

            // Season baseline semantics:
            //   - Normal case: latest snapshot where snapshot_date ≤ kickoff_date.
            //   - Season-start fallback: if all snapshots are after kickoff_date,
            //     use the first snapshot of the season (the post-mercato baseline).
            // ISO-8601 string comparison is lexicographically correct.
            $applicable = $snaps->last(
                fn ($s) => $s->snapshot_date->toDateString() <= $kickoffDate
            ) ?? $snaps->first();

            $computed    = TeamStructuralRatingCalculator::calculateFromMarketValue((int) $applicable->market_value);
            $map[$m->id] = $computed['structural_rating'];
        }

        return $map;
    }

    private static function emptyWindow(): array
    {
        return [
            'matches_considered'             => 0,
            'average_opponent_elo'           => null,
            'median_opponent_elo'            => null,
            'average_opponent_structural'    => null,
            'median_opponent_structural'     => null,
            'structural_matches_available'   => 0,
            'structural_coverage_percentage' => null,
        ];
    }

    /** @param float[] $values */
    private static function avg(array $values): ?float
    {
        return empty($values) ? null : array_sum($values) / count($values);
    }

    /**
     * Standard median: middle element (odd N) or average of two middle
     * elements (even N).  No internal rounding.
     *
     * @param float[] $values Non-empty array.
     */
    private static function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        return ($n % 2 !== 0)
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    private static function coverage(int $available, int $total): ?float
    {
        return $total === 0 ? null : round($available / $total * 100.0, 1);
    }
}
