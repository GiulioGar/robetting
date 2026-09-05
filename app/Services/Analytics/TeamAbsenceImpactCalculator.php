<?php

namespace App\Services\Analytics;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\PlayerAbsence;

/**
 * Measures the real impact of pre-match injuries/absences for a team,
 * cross-referencing player_absences with recent match_player_statistics.
 * Four queries total, no N+1.
 *
 * ── Data source ───────────────────────────────────────────────────────────────
 *
 * Absences are read from player_absences WHERE data_source_id = api-football,
 * which holds the pre-match snapshot fetched by ApiFootballInjuryAvailability-
 * SyncService.  No real-time API call is made.
 *
 * ── Metrics ───────────────────────────────────────────────────────────────────
 *
 * absences_count
 *   Total absent players for this fixture/team/source.
 *
 * absent_minutes_last_30_days
 *   Sum of non-null minutes played by absent players within the 30-day window
 *   [target − 30 days, target).  NULL when no absent player has any non-null
 *   minute data in that window (data gap, not 0 played).
 *
 * team_minutes_last_30_days
 *   Sum of non-null minutes for ALL team members in the same window.  Includes
 *   absent players (they are a subset of the team).
 *
 * absent_minutes_share_percentage
 *   absent_minutes_last_30_days / team_minutes_last_30_days × 100.
 *   NULL when either operand is null or the denominator is 0.
 *
 * absent_appearances_last_5
 *   Total appearances (rows in last-5 team matches) by absent players.
 *   A player who appeared in 3 of the last 5 contributes 3.
 *
 * absent_starts_last_5
 *   Total starter appearances (games_substitute = false) by absent players
 *   in the last-5 team matches.
 *
 * heavily_used_absences_count
 *   Count of absent players who started ≥4 of the last-5 team matches.
 *
 * absent_players_with_stats_count
 *   Count of absent players who appear in ANY previous stat row.  Used to
 *   compute coverage (some players may be new or data-scarce).
 *
 * absence_stats_coverage_percentage
 *   absent_players_with_stats_count / absences_count × 100.
 *   NULL when absences_count = 0.
 *
 * ── "Last 5 team matches" definition ─────────────────────────────────────────
 *
 * Identical to E2/E3/E4: the 5 most recent distinct match_ids (strictly before
 * target kickoff, definitive status) for which at least one player-stat row
 * exists for the requested team.
 *
 * ── NULL-minutes policy ───────────────────────────────────────────────────────
 *
 * games_minutes NULL means "played but exact duration unknown" — it is NOT
 * treated as 0.  Only non-null values contribute to minute sums.  A sum is
 * null when every relevant row has null minutes (not 0).
 *
 * ── No-leakage guarantee ─────────────────────────────────────────────────────
 *
 * All stats queries are guarded by kickoff_at < target (query) plus an internal
 * timestamp comparison (second line of defence).
 */
class TeamAbsenceImpactCalculator
{
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /**
     * @return array{
     *     absences_count:                   int,
     *     absent_minutes_last_30_days:      int|null,
     *     team_minutes_last_30_days:        int|null,
     *     absent_minutes_share_percentage:  float|null,
     *     absent_appearances_last_5:        int,
     *     absent_starts_last_5:             int,
     *     heavily_used_absences_count:      int,
     *     absent_players_with_stats_count:  int,
     *     absence_stats_coverage_percentage: float|null,
     * }
     */
    public static function calculateForMatch(FootballMatch $targetMatch, int $teamId): array
    {
        if ($targetMatch->kickoff_at === null) {
            return self::emptyResult();
        }

        $targetKickoff = $targetMatch->kickoff_at;
        $targetTs      = $targetKickoff->getTimestamp();

        // ── Q1: api-football data source id ───────────────────────────────────
        $dsId = DataSource::where('slug', 'api-football')->value('id');
        if ($dsId === null) {
            return self::emptyResult();
        }

        // ── Q2: absent player ids for this fixture/team/source ────────────────
        $absenceRows = PlayerAbsence::where('match_id', $targetMatch->id)
            ->where('team_id', $teamId)
            ->where('data_source_id', $dsId)
            ->get(['player_id']);

        $absencesCount = $absenceRows->count();

        if ($absencesCount === 0) {
            return self::emptyResult(); // 0 absences is a valid, informative result
        }

        $absentPlayerIds    = $absenceRows->pluck('player_id')->map(fn($id) => (int) $id)->all();
        $absentPlayerIdSet  = array_flip($absentPlayerIds); // O(1) membership test

        // ── Q3: definitive matches for this team strictly before target ────────
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
            return self::noStatsResult($absencesCount);
        }

        $kickoffByMatchId = $prevMatches->pluck('kickoff_at', 'id');

        // ── Q4: all player-stat rows for this team in those matches ───────────
        $allStats = MatchPlayerStatistic::whereIn('match_id', $prevMatches->pluck('id'))
            ->where('team_id', $teamId)
            ->get(['player_id', 'match_id', 'games_minutes', 'games_substitute']);

        if ($allStats->isEmpty()) {
            return self::noStatsResult($absencesCount);
        }

        // "Last 5 team matches" — same definition as E2/E3/E4.
        $last5MatchIds = $allStats
            ->pluck('match_id')
            ->unique()
            ->sortByDesc(fn($mid) => $kickoffByMatchId->get((int) $mid)?->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        // 30-day window lower bound (inclusive).
        $window30StartTs = $targetKickoff->copy()->subDays(30)->getTimestamp();

        // Accumulators.
        $absentMins30        = 0;
        $absentMins30HasData = false;
        $teamMins30          = 0;
        $teamMins30HasData   = false;
        $absentApps5         = 0;
        $absentStarts5       = 0;
        $absentStartCounts5  = []; // player_id → starter appearances in last 5
        $seenAbsentWithStats = []; // player_id → true (coverage numerator)

        foreach ($allStats as $row) {
            $mid    = (int) $row->match_id;
            $pid    = (int) $row->player_id;
            $mins   = $row->games_minutes;           // int|null
            $isSub  = (bool) $row->games_substitute; // false = starter
            $ko     = $kickoffByMatchId->get($mid);
            if ($ko === null) {
                continue;
            }
            $koTs    = $ko->getTimestamp();
            $isAbsent = isset($absentPlayerIdSet[$pid]);

            // Coverage: track any absent player that has at least one stat row.
            if ($isAbsent) {
                $seenAbsentWithStats[$pid] = true;
            }

            // 30-day window (time-based, independent of "last 5").
            if ($koTs >= $window30StartTs) {
                if ($mins !== null) {
                    $teamMins30        += $mins;
                    $teamMins30HasData  = true;
                    if ($isAbsent) {
                        $absentMins30        += $mins;
                        $absentMins30HasData  = true;
                    }
                }
            }

            // Last-5 appearances / starts (last-5-match window).
            if ($isAbsent && in_array($mid, $last5MatchIds, true)) {
                $absentApps5++;
                if (!$isSub) {
                    $absentStarts5++;
                    $absentStartCounts5[$pid] = ($absentStartCounts5[$pid] ?? 0) + 1;
                }
            }
        }

        $absentPlayersWithStatsCount = count($seenAbsentWithStats);
        $heavilyUsedCount            = 0;
        foreach ($absentStartCounts5 as $startCount) {
            if ($startCount >= 4) {
                $heavilyUsedCount++;
            }
        }

        return [
            'absences_count'                  => $absencesCount,
            'absent_minutes_last_30_days'     => $absentMins30HasData ? $absentMins30 : null,
            'team_minutes_last_30_days'       => $teamMins30HasData ? $teamMins30 : null,
            'absent_minutes_share_percentage' => ($teamMins30HasData && $teamMins30 > 0)
                ? (float) ($absentMins30 / $teamMins30 * 100)
                : null,
            'absent_appearances_last_5'       => $absentApps5,
            'absent_starts_last_5'            => $absentStarts5,
            'heavily_used_absences_count'     => $heavilyUsedCount,
            'absent_players_with_stats_count' => $absentPlayersWithStatsCount,
            'absence_stats_coverage_percentage' => (float) ($absentPlayersWithStatsCount / $absencesCount * 100),
        ];
    }

    /** Zero absences or null kickoff — no impact to measure. */
    private static function emptyResult(): array
    {
        return [
            'absences_count'                   => 0,
            'absent_minutes_last_30_days'      => null,
            'team_minutes_last_30_days'        => null,
            'absent_minutes_share_percentage'  => null,
            'absent_appearances_last_5'        => 0,
            'absent_starts_last_5'             => 0,
            'heavily_used_absences_count'      => 0,
            'absent_players_with_stats_count'  => 0,
            'absence_stats_coverage_percentage' => null,
        ];
    }

    /** Absences known but no usable player-stats history. */
    private static function noStatsResult(int $absencesCount): array
    {
        return [
            'absences_count'                   => $absencesCount,
            'absent_minutes_last_30_days'      => null,
            'team_minutes_last_30_days'        => null,
            'absent_minutes_share_percentage'  => null,
            'absent_appearances_last_5'        => 0,
            'absent_starts_last_5'             => 0,
            'heavily_used_absences_count'      => 0,
            'absent_players_with_stats_count'  => 0,
            'absence_stats_coverage_percentage' => 0.0,
        ];
    }
}
