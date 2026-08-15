<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * Derives a single team's analytics profile from a plain collection of
 * canonical matches. Purely computational: no query, no Request, no Blade,
 * no knowledge of competition/season — the caller decides which matches to
 * pass in (full season, home-only, away-only, last-N, ...) and this class
 * simply orients every match from that team's point of view.
 *
 * Result/goal stats reuse LeagueStandingsCalculator::calculate() (same
 * finished/home_score_ft/away_score_ft validity rule, same points scheme)
 * and just extract the row for $teamId, instead of recomputing the same
 * home/away orientation logic a second time.
 *
 * Market trends reuse CompetitionMarketTrendsCalculator::calculate() as-is
 * on the team's matches. Its 'result_1x2' block is home/draw/away oriented,
 * not team win/draw/loss, so it is dropped here — W/D/L belongs to 'summary'
 * instead. The remaining blocks (btts, full/half-time goals, btts_half_time,
 * team_scoring) describe the matches this team played in and are kept as-is;
 * note 'team_scoring' itself is still home/away-oriented internally (see
 * CompetitionMarketTrendsCalculator), so it is only strictly a "did this team
 * score" reading when the input collection is already home-only or away-only.
 *
 * @see LeagueStandingsCalculator
 * @see CompetitionMarketTrendsCalculator
 * @see \App\Services\Matches\PreferredMatchStatisticResolver
 */
class TeamAnalyticsCalculator
{
    /** metric key => [home column, away column] on MatchStatistic — tracked as a for/against pair. */
    private const PAIR_METRICS = [
        'shots'           => ['home_shots', 'away_shots'],
        'shots_on_target' => ['home_shots_on_target', 'away_shots_on_target'],
        'corners'         => ['home_corners', 'away_corners'],
        'fouls'           => ['home_fouls', 'away_fouls'],
    ];

    /** metric key => [home column, away column] on MatchStatistic — tracked as the team's own count only. */
    private const SINGLE_METRICS = [
        'yellow_cards' => ['home_yellow_cards', 'away_yellow_cards'],
        'red_cards'    => ['home_red_cards', 'away_red_cards'],
    ];

    /**
     * @param  Collection  $matches  Eloquent collection; each item must have:
     *                               home_team_id, away_team_id, status, kickoff_at,
     *                               home_score_ft, away_score_ft, home_score_ht, away_score_ht,
     *                               homeTeam->name, awayTeam->name (required by LeagueStandingsCalculator).
     *                               Matches not involving $teamId are ignored.
     * @param  int  $teamId
     * @param  ?Collection  $matchStatistics  Preferred MatchStatistic per match_id
     *                                        (see PreferredMatchStatisticResolver::forMatchIds()),
     *                                        keyed by match_id. Omit/null if unavailable.
     * @return array{
     *     team_id: int,
     *     summary: array,
     *     form: array<int, string>,
     *     technical: array,
     *     coverage: array,
     *     market_trends: array
     * }
     */
    public static function calculate(Collection $matches, int $teamId, ?Collection $matchStatistics = null): array
    {
        $matchStatistics ??= collect();

        $teamMatches = $matches->filter(static function ($match) use ($teamId): bool {
            return (int) $match->home_team_id === $teamId || (int) $match->away_team_id === $teamId;
        })->values();

        $finished = $teamMatches->filter(static function ($match): bool {
            return $match->status === 'finished'
                && $match->home_score_ft !== null
                && $match->away_score_ft !== null;
        })->values();

        $technical = self::technical($finished, $teamId, $matchStatistics);

        return [
            'team_id'       => $teamId,
            'summary'       => self::summary($teamMatches, $teamId),
            'form'          => self::form($finished, $teamId),
            'technical'     => $technical['technical'],
            'coverage'      => $technical['coverage'],
            'market_trends' => self::marketTrends($teamMatches),
        ];
    }

    /**
     * @return array{
     *     matches_played: int, wins: int, draws: int, losses: int, points: int,
     *     goals_for: int, goals_against: int, goal_difference: int,
     *     avg_goals_for: ?float, avg_goals_against: ?float, avg_total_goals: ?float,
     *     win_percentage: ?float, draw_percentage: ?float, loss_percentage: ?float
     * }
     */
    private static function summary(Collection $teamMatches, int $teamId): array
    {
        $row = collect(LeagueStandingsCalculator::calculate($teamMatches))
            ->firstWhere('team_id', $teamId);

        $played = $row['played'] ?? 0;
        $wins   = $row['wins'] ?? 0;
        $draws  = $row['draws'] ?? 0;
        $losses = $row['losses'] ?? 0;
        $points = $row['points'] ?? 0;
        $gf     = $row['goals_for'] ?? 0;
        $ga     = $row['goals_against'] ?? 0;

        return [
            'matches_played'    => $played,
            'wins'              => $wins,
            'draws'             => $draws,
            'losses'            => $losses,
            'points'            => $points,
            'goals_for'         => $gf,
            'goals_against'     => $ga,
            'goal_difference'   => $gf - $ga,
            'avg_goals_for'     => $played > 0 ? round($gf / $played, 2) : null,
            'avg_goals_against' => $played > 0 ? round($ga / $played, 2) : null,
            'avg_total_goals'   => $played > 0 ? round(($gf + $ga) / $played, 2) : null,
            'win_percentage'    => $played > 0 ? round($wins / $played * 100, 2) : null,
            'draw_percentage'   => $played > 0 ? round($draws / $played * 100, 2) : null,
            'loss_percentage'   => $played > 0 ? round($losses / $played * 100, 2) : null,
        ];
    }

    /**
     * Chronological W/D/L sequence, oriented to $teamId. Does not assume the
     * input is already ordered — sorts by kickoff_at itself.
     *
     * @return array<int, string>
     */
    private static function form(Collection $finished, int $teamId): array
    {
        return $finished->sortBy('kickoff_at')->values()
            ->map(static function ($match) use ($teamId): string {
                $isHome  = (int) $match->home_team_id === $teamId;
                $for     = $isHome ? (int) $match->home_score_ft : (int) $match->away_score_ft;
                $against = $isHome ? (int) $match->away_score_ft : (int) $match->home_score_ft;

                if ($for > $against) {
                    return 'W';
                }

                return $for === $against ? 'D' : 'L';
            })
            ->values()
            ->all();
    }

    /**
     * Per-metric for/against (or own-count) averages, each with its own
     * independent coverage denominator — never a single shared denominator.
     *
     * @return array{technical: array, coverage: array}
     */
    private static function technical(Collection $finished, int $teamId, Collection $matchStatistics): array
    {
        $totalFinished = $finished->count();

        $sums = [];
        foreach (self::PAIR_METRICS as $metric => $cols) {
            $sums[$metric] = ['for' => 0, 'against' => 0, 'count' => 0];
        }
        foreach (self::SINGLE_METRICS as $metric => $cols) {
            $sums[$metric] = ['sum' => 0, 'count' => 0];
        }

        foreach ($finished as $match) {
            $stat = $matchStatistics->get($match->id);
            if ($stat === null) {
                continue;
            }

            $isHome = (int) $match->home_team_id === $teamId;

            foreach (self::PAIR_METRICS as $metric => [$homeField, $awayField]) {
                $forVal     = $stat->{$isHome ? $homeField : $awayField};
                $againstVal = $stat->{$isHome ? $awayField : $homeField};
                if ($forVal === null || $againstVal === null) {
                    continue;
                }
                $sums[$metric]['for']     += (int) $forVal;
                $sums[$metric]['against'] += (int) $againstVal;
                $sums[$metric]['count']++;
            }

            foreach (self::SINGLE_METRICS as $metric => [$homeField, $awayField]) {
                $val = $stat->{$isHome ? $homeField : $awayField};
                if ($val === null) {
                    continue;
                }
                $sums[$metric]['sum'] += (int) $val;
                $sums[$metric]['count']++;
            }
        }

        $technical = [];
        $coverage  = [];

        foreach (self::PAIR_METRICS as $metric => $cols) {
            $count = $sums[$metric]['count'];
            $technical["avg_{$metric}_for"]     = $count > 0 ? round($sums[$metric]['for'] / $count, 2) : null;
            $technical["avg_{$metric}_against"] = $count > 0 ? round($sums[$metric]['against'] / $count, 2) : null;
            $coverage[$metric] = self::coverageRow($count, $totalFinished);
        }

        foreach (self::SINGLE_METRICS as $metric => $cols) {
            $count = $sums[$metric]['count'];
            $technical["avg_{$metric}"] = $count > 0 ? round($sums[$metric]['sum'] / $count, 2) : null;
            $coverage[$metric] = self::coverageRow($count, $totalFinished);
        }

        return ['technical' => $technical, 'coverage' => $coverage];
    }

    /**
     * @return array{available_matches: int, total_finished_matches: int, coverage_percent: float}
     */
    private static function coverageRow(int $availableMatches, int $totalFinished): array
    {
        return [
            'available_matches'      => $availableMatches,
            'total_finished_matches' => $totalFinished,
            'coverage_percent'       => $totalFinished > 0
                ? round($availableMatches / $totalFinished * 100, 1)
                : 0,
        ];
    }

    /**
     * Reuses CompetitionMarketTrendsCalculator verbatim on the team's matches,
     * dropping only 'result_1x2' (home/draw/away — not team W/D/L; see 'summary').
     */
    private static function marketTrends(Collection $teamMatches): array
    {
        $trends = CompetitionMarketTrendsCalculator::calculate($teamMatches);
        unset($trends['result_1x2']);

        return $trends;
    }
}
