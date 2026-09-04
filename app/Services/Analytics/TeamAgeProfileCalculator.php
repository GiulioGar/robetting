<?php

namespace App\Services\Analytics;

use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use Carbon\Carbon;

/**
 * Computes age-profile metrics for the players recently fielded by a team,
 * relative to a target match kickoff. Three queries total, no N+1.
 *
 * Canonical call sites:
 *
 *   $homeAge = TeamAgeProfileCalculator::calculateForMatch($match, $match->home_team_id);
 *   $awayAge = TeamAgeProfileCalculator::calculateForMatch($match, $match->away_team_id);
 *
 * ── Age reference: TARGET KICKOFF ────────────────────────────────────────────
 *
 * All three age metrics use the TARGET MATCH kickoff as the reference date,
 * not the individual historical match dates and not "now".  Rationale: we want
 * to describe the age of the squad at the moment of the match being analysed,
 * so a backtest on a historical match produces the same result as a live
 * prediction run on the same data.  Age is a fractional float (years + decimals)
 * computed as (targetTs − birthTs) / SECONDS_PER_YEAR.
 *
 * ── birth_date NULL policy ───────────────────────────────────────────────────
 *
 * Players without birth_date are EXCLUDED from the age averages.  They ARE
 * included in players_used_count (they did play) but NOT in
 * players_with_birth_date_count.  The coverage percentage lets the caller
 * judge how reliable the computed averages are.
 *
 * ── Metric definitions ───────────────────────────────────────────────────────
 *
 * average_age_used_last_5:
 *   Each UNIQUE player with at least one appearance in the last 5 team matches
 *   contributes their age once.  A player in 4 of the 5 still counts once.
 *   Formula: sum(age_at_target, unique players with birth_date) / count.
 *
 * weighted_average_age_last_5:
 *   Players weighted by total minutes played across the last 5 matches.
 *   NULL minutes are excluded from the weight (same policy as E2).
 *   Formula: sum(age * total_minutes) / sum(total_minutes).
 *   Returns null when no valid minutes exist.
 *
 * average_starter_age_last_5:
 *   Every STARTER APPEARANCE (games_substitute = false) contributes one age
 *   entry.  A player who starts 3 of the 5 matches contributes 3 entries.
 *   This describes the average age of the actual starting lineup slots.
 *   Formula: sum(age_at_target per starter row with birth_date) / count(starter rows with birth_date).
 *
 * ── "Last 5 team matches" definition ────────────────────────────────────────
 *
 * Identical to PlayerRecentLoadCalculator E2: the 5 most recent definitive
 * match_ids (strictly before target kickoff) for which at least one
 * player-stat row exists for this team.
 *
 * ── No-leakage guarantee ─────────────────────────────────────────────────────
 *
 * The caller MUST supply matches with kickoff_at < targetKickoff.
 * The calculator ALSO enforces this internally via timestamp comparison
 * (same second-line-of-defence pattern as E1 and E2).
 */
class TeamAgeProfileCalculator
{
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /** Approximate seconds per year used for all age calculations. */
    private const SECONDS_PER_YEAR = 365.25 * 86400;

    /**
     * @return array{
     *     average_age_used_last_5:        float|null,
     *     weighted_average_age_last_5:    float|null,
     *     average_starter_age_last_5:     float|null,
     *     players_used_count:             int,
     *     players_with_birth_date_count:  int,
     *     birth_date_coverage_percentage: float|null,
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

        // ── Q2: all player-stat rows for those matches, this team only ─────────
        $allStats = MatchPlayerStatistic::whereIn('match_id', $prevMatches->pluck('id'))
            ->where('team_id', $teamId)
            ->get(['player_id', 'match_id', 'games_minutes', 'games_substitute']);

        if ($allStats->isEmpty()) {
            return self::emptyResult();
        }

        // "Last 5 team matches": 5 most recent match_ids with ≥1 stat row for this team.
        $last5MatchIds = $allStats
            ->pluck('match_id')
            ->unique()
            ->sortByDesc(fn($mid) => $kickoffByMatchId->get((int) $mid)?->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $last5Stats = $allStats->filter(
            fn($row) => in_array((int) $row->match_id, $last5MatchIds, true)
        );

        if ($last5Stats->isEmpty()) {
            return self::emptyResult();
        }

        $uniquePlayerIds = $last5Stats
            ->pluck('player_id')
            ->unique()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        // ── Q3: birth_dates for the players used in the last 5 matches ─────────
        $playerRecords = Player::whereIn('id', $uniquePlayerIds)
            ->get(['id', 'birth_date'])
            ->keyBy('id');

        // Pre-compute fractional age at target for each player that has birth_date.
        // Players without birth_date are absent from this map.
        $ageAtTarget = [];
        foreach ($uniquePlayerIds as $pid) {
            $p = $playerRecords->get($pid);
            if ($p !== null && $p->birth_date !== null) {
                $ageAtTarget[$pid] = ($targetTs - $p->birth_date->getTimestamp()) / self::SECONDS_PER_YEAR;
            }
        }

        $playersUsedCount          = count($uniquePlayerIds);
        $playersWithBirthDateCount = count($ageAtTarget);

        // ── average_age_used_last_5 ────────────────────────────────────────────
        // One entry per unique player with birth_date.
        $agesUsed = array_values($ageAtTarget);

        // ── weighted_average_age_last_5 ────────────────────────────────────────
        // Total valid minutes per player across last 5 matches.
        $minutesByPlayer = [];
        foreach ($last5Stats as $row) {
            $pid  = (int) $row->player_id;
            $mins = $row->games_minutes; // int|null
            if ($mins !== null) {
                $minutesByPlayer[$pid] = ($minutesByPlayer[$pid] ?? 0) + $mins;
            }
        }

        $wNumerator   = 0.0;
        $wDenominator = 0.0;
        foreach ($ageAtTarget as $pid => $age) {
            $totalMins = $minutesByPlayer[$pid] ?? null;
            if ($totalMins !== null && $totalMins > 0) {
                $wNumerator   += $age * $totalMins;
                $wDenominator += $totalMins;
            }
        }

        // ── average_starter_age_last_5 ─────────────────────────────────────────
        // One entry per starter appearance (games_substitute = false) with birth_date.
        $starterAges = [];
        foreach ($last5Stats as $row) {
            if ((bool) $row->games_substitute) {
                continue;
            }
            $pid = (int) $row->player_id;
            if (isset($ageAtTarget[$pid])) {
                $starterAges[] = $ageAtTarget[$pid];
            }
        }

        return [
            'average_age_used_last_5'        => !empty($agesUsed)
                ? array_sum($agesUsed) / count($agesUsed)
                : null,
            'weighted_average_age_last_5'    => $wDenominator > 0
                ? $wNumerator / $wDenominator
                : null,
            'average_starter_age_last_5'     => !empty($starterAges)
                ? array_sum($starterAges) / count($starterAges)
                : null,
            'players_used_count'             => $playersUsedCount,
            'players_with_birth_date_count'  => $playersWithBirthDateCount,
            'birth_date_coverage_percentage' => $playersUsedCount > 0
                ? $playersWithBirthDateCount / $playersUsedCount * 100
                : null,
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'average_age_used_last_5'        => null,
            'weighted_average_age_last_5'     => null,
            'average_starter_age_last_5'      => null,
            'players_used_count'              => 0,
            'players_with_birth_date_count'   => 0,
            'birth_date_coverage_percentage'  => null,
        ];
    }
}
