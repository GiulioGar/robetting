<?php

namespace App\Services\Analytics;

use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;

/**
 * Measures how stable a team's starting eleven is across their last 5 matches
 * before a target match kickoff. Two queries total, no N+1.
 *
 * ── Definition: "starter" ────────────────────────────────────────────────────
 *
 * A player is a starter in match M for team T when a MatchPlayerStatistic row
 * exists with games_substitute = false, team_id = T, in a definitive match
 * strictly before the target kickoff.
 *
 * Ambiguous cases:
 *   - Same player listed twice as starter in the same match (data quality error):
 *     deduplicated to one entry per (player_id, match_id) via associative-array
 *     set — has no effect on the intersection maths.
 *   - Transferred player: rows are team-scoped (Q2 WHERE team_id = $teamId),
 *     so a player who played for two teams is counted only for the team being
 *     queried.
 *
 * ── "Last 5 team matches" definition ────────────────────────────────────────
 *
 * The 5 most recent distinct match_ids (before target, definitive) that have
 * at least one starter row for the requested team — identical semantics to
 * PlayerRecentLoadCalculator (E2) and TeamAgeProfileCalculator (E3).
 *
 * ── Consecutive comparison ───────────────────────────────────────────────────
 *
 * Sort the last 5 chronologically → [M1, M2, M3, M4, M5].
 * For each pair (Mi, Mi+1):
 *   retained  = |starters(Mi) ∩ starters(Mi+1)|
 *   ref_size  = min(|starters(Mi)|, |starters(Mi+1)|)
 *   changed   = ref_size − retained
 *
 * When a match has fewer than 11 starter rows (incomplete data), ref_size uses
 * the smaller XI rather than assuming 11.  This is conservative but accurate.
 * The lineup_coverage_percentage field exposes how reliable the averages are.
 *
 * ── No-leakage guarantee ─────────────────────────────────────────────────────
 *
 * Caller must pass kickoff_at < target.  The calculator also enforces this
 * internally via timestamp comparison (second line of defence).
 */
class TeamStarterContinuityCalculator
{
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /**
     * @return array{
     *     average_starters_retained:         float|null,
     *     average_starters_changed:          float|null,
     *     players_started_4_of_last_5:       int,
     *     players_started_5_of_last_5:       int,
     *     distinct_starters_last_5:          int,
     *     matches_considered:                int,
     *     matches_with_complete_starting_xi: int,
     *     lineup_coverage_percentage:        float|null,
     * }
     */
    public static function calculateForMatch(FootballMatch $targetMatch, int $teamId): array
    {
        if ($targetMatch->kickoff_at === null) {
            return self::emptyResult();
        }

        $targetKickoff = $targetMatch->kickoff_at;
        $targetTs      = $targetKickoff->getTimestamp();

        // ── Q1: definitive matches for this team strictly before target ────────
        $prevMatches = FootballMatch::whereIn('status', self::DEFINITIVE_STATUSES)
            ->where('kickoff_at', '<', $targetKickoff)
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('kickoff_at')
            ->get(['id', 'kickoff_at']);

        // Internal anti-leakage guard (second line of defence, timestamp-safe).
        $prevMatches = $prevMatches->filter(
            fn($m) => $m->kickoff_at->getTimestamp() < $targetTs
        );

        if ($prevMatches->isEmpty()) {
            return self::emptyResult();
        }

        $kickoffByMatchId = $prevMatches->pluck('kickoff_at', 'id');

        // ── Q2: starter rows only, this team only, for those match_ids ─────────
        // Filtered at DB level (games_substitute = 0) — avoids pulling sub rows
        // that would be discarded anyway.
        $allStarters = MatchPlayerStatistic::whereIn('match_id', $prevMatches->pluck('id'))
            ->where('team_id', $teamId)
            ->where('games_substitute', false)
            ->get(['player_id', 'match_id']);

        if ($allStarters->isEmpty()) {
            return self::emptyResult();
        }

        // Last 5 team matches: most recent match_ids with ≥1 starter row.
        $last5MatchIds = $allStarters
            ->pluck('match_id')
            ->unique()
            ->sortByDesc(fn($mid) => $kickoffByMatchId->get((int) $mid)?->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $matchesConsidered = count($last5MatchIds);

        // Build unique starter sets: match_id → [player_id, ...] (no dupes).
        $startersByMatch = array_fill_keys($last5MatchIds, []);
        foreach ($allStarters as $row) {
            $mid = (int) $row->match_id;
            $pid = (int) $row->player_id;
            if (array_key_exists($mid, $startersByMatch)) {
                $startersByMatch[$mid][$pid] = true; // associative-array set
            }
        }
        foreach ($last5MatchIds as $mid) {
            $startersByMatch[$mid] = array_keys($startersByMatch[$mid]);
        }

        // Coverage: how many of the last 5 have exactly 11 starters.
        $matchesWithCompleteXI = 0;
        foreach ($last5MatchIds as $mid) {
            if (count($startersByMatch[$mid]) === 11) {
                $matchesWithCompleteXI++;
            }
        }

        // Sort to chronological order for consecutive comparison.
        $chronologicalIds = collect($last5MatchIds)
            ->sortBy(fn($mid) => $kickoffByMatchId->get((int) $mid)?->getTimestamp() ?? 0)
            ->values()
            ->all();

        // Compute retained / changed only for COMPLETE consecutive pairs.
        // A pair is complete when BOTH matches have exactly 11 starter rows.
        // Incomplete pairs are skipped entirely — using min() as a proxy would
        // produce a lower-bound estimate that looks like a precise value.
        // complete_transitions_count tells the caller how many pairs fed the means.
        $retainedValues      = [];
        $changedValues       = [];
        $completeTransitions = 0;

        for ($i = 0, $n = count($chronologicalIds) - 1; $i < $n; $i++) {
            $midA = $chronologicalIds[$i];
            $midB = $chronologicalIds[$i + 1];

            $startersA = $startersByMatch[$midA];
            $startersB = $startersByMatch[$midB];

            if (count($startersA) !== 11 || count($startersB) !== 11) {
                continue; // at least one XI is incomplete — skip this transition
            }

            $retained = count(array_intersect($startersA, $startersB));

            $retainedValues[]    = $retained;
            $changedValues[]     = 11 - $retained;
            $completeTransitions++;
        }

        // Per-player start counts across the last 5.
        $startCountByPlayer = [];
        foreach ($last5MatchIds as $mid) {
            foreach ($startersByMatch[$mid] as $pid) {
                $startCountByPlayer[$pid] = ($startCountByPlayer[$pid] ?? 0) + 1;
            }
        }

        $distinctStarters = count($startCountByPlayer);
        $started4of5      = 0;
        $started5of5      = 0;
        foreach ($startCountByPlayer as $count) {
            if ($count >= 4) {
                $started4of5++;
            }
            if ($count >= 5) {
                $started5of5++;
            }
        }

        // Note on players_started_4_of_last_5 / players_started_5_of_last_5:
        // These counts include appearances from incomplete XI matches.  A player
        // absent from an incomplete lineup might still have started — that absence
        // is a data gap, not a confirmed non-start.  lineup_coverage_percentage
        // lets the caller assess how much this might undercount true starts.
        return [
            'average_starters_retained'         => $completeTransitions > 0
                ? array_sum($retainedValues) / $completeTransitions
                : null,
            'average_starters_changed'          => $completeTransitions > 0
                ? array_sum($changedValues) / $completeTransitions
                : null,
            'complete_transitions_count'        => $completeTransitions,
            'players_started_4_of_last_5'       => $started4of5,
            'players_started_5_of_last_5'       => $started5of5,
            'distinct_starters_last_5'          => $distinctStarters,
            'matches_considered'                => $matchesConsidered,
            'matches_with_complete_starting_xi' => $matchesWithCompleteXI,
            'lineup_coverage_percentage'        => $matchesConsidered > 0
                ? (float) ($matchesWithCompleteXI / $matchesConsidered * 100)
                : null,
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'average_starters_retained'         => null,
            'average_starters_changed'          => null,
            'complete_transitions_count'        => 0,
            'players_started_4_of_last_5'       => 0,
            'players_started_5_of_last_5'       => 0,
            'distinct_starters_last_5'          => 0,
            'matches_considered'                => 0,
            'matches_with_complete_starting_xi' => 0,
            'lineup_coverage_percentage'        => null,
        ];
    }
}
