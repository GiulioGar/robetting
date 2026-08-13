<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class CompetitionStatisticsCalculator
{
    /**
     * metric key => [home column, away column] on MatchStatistic.
     * Shared by competition-level (avg_team_X, avg_match_X) and team-level
     * (for/against) gameplay stats.
     */
    private const GAMEPLAY_METRICS = [
        'shots'           => ['home_shots', 'away_shots'],
        'shots_on_target' => ['home_shots_on_target', 'away_shots_on_target'],
        'corners'         => ['home_corners', 'away_corners'],
        'fouls'           => ['home_fouls', 'away_fouls'],
        'yellow_cards'    => ['home_yellow_cards', 'away_yellow_cards'],
        'red_cards'       => ['home_red_cards', 'away_red_cards'],
    ];

    // Team-level metrics tracked as a for/against pair.
    private const TEAM_PAIR_METRICS = ['shots', 'shots_on_target', 'corners'];

    // Team-level metrics tracked as the team's own count only (no "against" side).
    private const TEAM_SINGLE_METRICS = ['yellow_cards', 'red_cards'];

    /**
     * Calculate aggregate season statistics from a season's full match collection
     * and its already-computed standings (see LeagueStandingsCalculator).
     *
     * A match contributes to stats only when BOTH conditions hold:
     *   - status = 'finished'
     *   - home_score_ft and away_score_ft are not null
     *
     * Team-based records (best attack/defense, most wins/draws/losses, ...) are
     * derived from $standings rather than recomputed from $matches, and only
     * consider teams with played > 0 so a team that hasn't played yet cannot
     * win a MIN-based record (e.g. best_defense) by default.
     *
     * Ties are never broken arbitrarily: every record entry is a list, holding
     * every team/match sharing the extreme value.
     *
     * @param  Collection  $matches    Eloquent collection; each item must have:
     *                                 status, home_score_ft, away_score_ft,
     *                                 homeTeam->name, awayTeam->name, kickoff_at
     * @param  array  $standings  Output of LeagueStandingsCalculator::calculate()
     * @param  ?Collection  $matchStatistics  Preferred MatchStatistic per match_id
     *                                        (see PreferredMatchStatisticResolver),
     *                                        keyed by match_id. Omit/null if no
     *                                        gameplay data source is available yet.
     * @return array{
     *     available: bool,
     *     played_matches: int,
     *     total_goals: ?int,
     *     avg_goals_per_match: ?float,
     *     best_attack: array, best_defense: array, best_goal_difference: array,
     *     worst_defense: array, most_wins: array, most_draws: array, most_losses: array,
     *     highest_scoring_match: array, biggest_win: array,
     *     gameplay: array{competition: array, teams: array, leaders: array}
     * }
     */
    public static function calculate(Collection $matches, array $standings, ?Collection $matchStatistics = null): array
    {
        $matchStatistics ??= collect();

        $finished = $matches->filter(static function ($match): bool {
            return $match->status === 'finished'
                && $match->home_score_ft !== null
                && $match->away_score_ft !== null;
        });

        $playedMatches = $finished->count();

        if ($playedMatches === 0) {
            return self::emptyResult();
        }

        $totalGoals = 0;
        foreach ($finished as $match) {
            $totalGoals += (int) $match->home_score_ft + (int) $match->away_score_ft;
        }

        // Only teams that have actually played can contend for records: otherwise
        // a team with 0 matches played (goals_against = 0) would falsely tie
        // best_defense / most_draws / most_losses early in the season.
        $playedTeams = array_values(array_filter($standings, static fn(array $row): bool => $row['played'] > 0));

        $gameplayTeams = self::gameplayTeamStats($finished, $matchStatistics);

        return [
            'available'             => true,
            'played_matches'        => $playedMatches,
            'total_goals'           => $totalGoals,
            'avg_goals_per_match'   => round($totalGoals / $playedMatches, 2),
            'best_attack'           => self::teamRecord($playedTeams, 'goals_for', 'max'),
            'best_defense'          => self::teamRecord($playedTeams, 'goals_against', 'min'),
            'best_goal_difference'  => self::teamRecord($playedTeams, 'goal_difference', 'max'),
            'worst_defense'         => self::teamRecord($playedTeams, 'goals_against', 'max'),
            'most_wins'             => self::teamRecord($playedTeams, 'wins', 'max'),
            'most_draws'            => self::teamRecord($playedTeams, 'draws', 'max'),
            'most_losses'           => self::teamRecord($playedTeams, 'losses', 'max'),
            'highest_scoring_match' => self::highestScoringMatches($finished),
            'biggest_win'           => self::biggestWinMatches($finished),
            'gameplay'              => [
                'competition' => self::gameplayCompetitionStats($finished, $matchStatistics, $playedMatches),
                'teams'       => array_values($gameplayTeams),
                'leaders'     => self::gameplayLeaders($gameplayTeams),
            ],
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'available'             => false,
            'played_matches'        => 0,
            'total_goals'           => null,
            'avg_goals_per_match'   => null,
            'best_attack'           => [],
            'best_defense'          => [],
            'best_goal_difference'  => [],
            'worst_defense'         => [],
            'most_wins'             => [],
            'most_draws'            => [],
            'most_losses'           => [],
            'highest_scoring_match' => [],
            'biggest_win'           => [],
            'gameplay'              => [
                'competition' => [],
                'teams'       => [],
                'leaders'     => [],
            ],
        ];
    }

    /**
     * @param  array<int, array>  $rows  Standings rows (already filtered to played > 0)
     * @return array<int, array{team_id: int, team: string, value: int}>
     */
    private static function teamRecord(array $rows, string $key, string $mode): array
    {
        if (empty($rows)) {
            return [];
        }

        $values = array_column($rows, $key);
        $target = $mode === 'max' ? max($values) : min($values);

        $result = [];
        foreach ($rows as $row) {
            if ($row[$key] === $target) {
                $result[] = [
                    'team_id' => $row['team_id'],
                    'team'    => $row['name'],
                    'value'   => $target,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array>
     */
    private static function highestScoringMatches(Collection $finished): array
    {
        if ($finished->isEmpty()) {
            return [];
        }

        $maxGoals = $finished->max(static fn($m) => (int) $m->home_score_ft + (int) $m->away_score_ft);

        return $finished
            ->filter(static fn($m) => (int) $m->home_score_ft + (int) $m->away_score_ft === $maxGoals)
            ->map(static fn($m) => [
                'match_id'    => $m->id,
                'home_team'   => $m->homeTeam->name,
                'away_team'   => $m->awayTeam->name,
                'home_score'  => (int) $m->home_score_ft,
                'away_score'  => (int) $m->away_score_ft,
                'total_goals' => $maxGoals,
                'kickoff_at'  => $m->kickoff_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array>
     */
    private static function biggestWinMatches(Collection $finished): array
    {
        $decisive = $finished->filter(static fn($m) => (int) $m->home_score_ft !== (int) $m->away_score_ft);

        if ($decisive->isEmpty()) {
            return [];
        }

        $maxMargin = $decisive->max(static fn($m) => abs((int) $m->home_score_ft - (int) $m->away_score_ft));

        return $decisive
            ->filter(static fn($m) => abs((int) $m->home_score_ft - (int) $m->away_score_ft) === $maxMargin)
            ->map(static function ($m) use ($maxMargin) {
                $homeWon = (int) $m->home_score_ft > (int) $m->away_score_ft;

                return [
                    'match_id'     => $m->id,
                    'winning_team' => $homeWon ? $m->homeTeam->name : $m->awayTeam->name,
                    'losing_team'  => $homeWon ? $m->awayTeam->name : $m->homeTeam->name,
                    'home_team'    => $m->homeTeam->name,
                    'away_team'    => $m->awayTeam->name,
                    'home_score'   => (int) $m->home_score_ft,
                    'away_score'   => (int) $m->away_score_ft,
                    'margin'       => $maxMargin,
                    'kickoff_at'   => $m->kickoff_at,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Competition-level gameplay averages, one entry per GAMEPLAY_METRICS key
     * for both the "per team per match" (avg_team_*) and "per match" (avg_match_*)
     * readings. A match counts toward a metric's coverage only when BOTH its
     * home and away values are present — this keeps the team-performance
     * denominator (available_matches * 2) exact, per spec.
     *
     * @param  Collection  $finished  Finished matches (home_score_ft/away_score_ft not null)
     * @param  Collection  $matchStatistics  Preferred MatchStatistic keyed by match_id
     * @return array<string, array{value: ?float, coverage: array}>
     */
    private static function gameplayCompetitionStats(Collection $finished, Collection $matchStatistics, int $totalFinished): array
    {
        $result = [];

        foreach (self::GAMEPLAY_METRICS as $metric => [$homeField, $awayField]) {
            $sum              = 0;
            $availableMatches = 0;

            foreach ($finished as $match) {
                $stat = $matchStatistics->get($match->id);
                if ($stat === null || $stat->{$homeField} === null || $stat->{$awayField} === null) {
                    continue;
                }

                $sum += (int) $stat->{$homeField} + (int) $stat->{$awayField};
                $availableMatches++;
            }

            $coverage = [
                'available_matches'      => $availableMatches,
                'total_finished_matches' => $totalFinished,
                'coverage_percent'       => $totalFinished > 0
                    ? round($availableMatches / $totalFinished * 100, 1)
                    : 0,
            ];

            $result["avg_team_{$metric}"] = [
                'value'    => $availableMatches > 0 ? round($sum / ($availableMatches * 2), 2) : null,
                'coverage' => $coverage,
            ];
            $result["avg_match_{$metric}"] = [
                'value'    => $availableMatches > 0 ? round($sum / $availableMatches, 2) : null,
                'coverage' => $coverage,
            ];
        }

        return $result;
    }

    /**
     * Per-team gameplay averages (home/away orientation collapsed to for/against).
     * A team's match only contributes to a given metric when that match has
     * both home and away values available for it.
     *
     * @return array<int, array{team_id: int, team: string, avg_shots_for: ?float, ...}>  Keyed by team_id
     */
    private static function gameplayTeamStats(Collection $finished, Collection $matchStatistics): array
    {
        $teams = [];
        $sums  = [];

        $ensure = static function (int $teamId, string $name) use (&$teams, &$sums): void {
            if (isset($teams[$teamId])) {
                return;
            }
            $teams[$teamId] = ['team_id' => $teamId, 'team' => $name];
            $sums[$teamId]  = [];
            foreach (self::TEAM_PAIR_METRICS as $metric) {
                $sums[$teamId]["{$metric}_for"]     = 0;
                $sums[$teamId]["{$metric}_against"] = 0;
                $sums[$teamId]["{$metric}_count"]   = 0;
            }
            foreach (self::TEAM_SINGLE_METRICS as $metric) {
                $sums[$teamId]["{$metric}_sum"]   = 0;
                $sums[$teamId]["{$metric}_count"] = 0;
            }
        };

        foreach ($finished as $match) {
            $stat = $matchStatistics->get($match->id);
            if ($stat === null) {
                continue;
            }

            $homeId = $match->home_team_id;
            $awayId = $match->away_team_id;
            $ensure($homeId, $match->homeTeam->name);
            $ensure($awayId, $match->awayTeam->name);

            foreach (self::TEAM_PAIR_METRICS as $metric) {
                [$homeField, $awayField] = self::GAMEPLAY_METRICS[$metric];
                $h = $stat->{$homeField};
                $a = $stat->{$awayField};
                if ($h === null || $a === null) {
                    continue;
                }

                $sums[$homeId]["{$metric}_for"]     += (int) $h;
                $sums[$homeId]["{$metric}_against"] += (int) $a;
                $sums[$homeId]["{$metric}_count"]++;
                $sums[$awayId]["{$metric}_for"]     += (int) $a;
                $sums[$awayId]["{$metric}_against"] += (int) $h;
                $sums[$awayId]["{$metric}_count"]++;
            }

            foreach (self::TEAM_SINGLE_METRICS as $metric) {
                [$homeField, $awayField] = self::GAMEPLAY_METRICS[$metric];
                if ($stat->{$homeField} !== null) {
                    $sums[$homeId]["{$metric}_sum"] += (int) $stat->{$homeField};
                    $sums[$homeId]["{$metric}_count"]++;
                }
                if ($stat->{$awayField} !== null) {
                    $sums[$awayId]["{$metric}_sum"] += (int) $stat->{$awayField};
                    $sums[$awayId]["{$metric}_count"]++;
                }
            }
        }

        foreach ($teams as $teamId => &$row) {
            foreach (self::TEAM_PAIR_METRICS as $metric) {
                $count = $sums[$teamId]["{$metric}_count"];
                $row["avg_{$metric}_for"]     = $count > 0 ? round($sums[$teamId]["{$metric}_for"] / $count, 2) : null;
                $row["avg_{$metric}_against"] = $count > 0 ? round($sums[$teamId]["{$metric}_against"] / $count, 2) : null;
            }
            foreach (self::TEAM_SINGLE_METRICS as $metric) {
                $count               = $sums[$teamId]["{$metric}_count"];
                $row["avg_{$metric}"] = $count > 0 ? round($sums[$teamId]["{$metric}_sum"] / $count, 2) : null;
            }
        }
        unset($row);

        return $teams;
    }

    /**
     * @param  array<int, array>  $teams  Output of gameplayTeamStats()
     * @return array<string, array>
     */
    private static function gameplayLeaders(array $teams): array
    {
        return [
            'most_shots'           => self::teamValueRecord($teams, 'avg_shots_for', 'max'),
            'most_shots_on_target' => self::teamValueRecord($teams, 'avg_shots_on_target_for', 'max'),
            'most_corners'         => self::teamValueRecord($teams, 'avg_corners_for', 'max'),
            'fewest_shots_against' => self::teamValueRecord($teams, 'avg_shots_against', 'min'),
        ];
    }

    /**
     * @param  array<int, array>  $teams
     * @return array<int, array{team_id: int, team: string, value: float}>
     */
    private static function teamValueRecord(array $teams, string $key, string $mode): array
    {
        $rows = array_values(array_filter($teams, static fn(array $t) => $t[$key] !== null));
        if (empty($rows)) {
            return [];
        }

        $values = array_column($rows, $key);
        $target = $mode === 'max' ? max($values) : min($values);

        $result = [];
        foreach ($rows as $row) {
            if ($row[$key] === $target) {
                $result[] = [
                    'team_id' => $row['team_id'],
                    'team'    => $row['team'],
                    'value'   => $target,
                ];
            }
        }

        return $result;
    }
}
