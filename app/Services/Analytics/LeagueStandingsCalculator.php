<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class LeagueStandingsCalculator
{
    /**
     * Calculate league standings from a season's full match collection.
     *
     * Pass ALL matches of the season (scheduled + finished) so that every
     * participating team appears in the table even before the season starts.
     *
     * A match contributes to stats only when BOTH conditions hold:
     *   - status = 'finished'
     *   - home_score_ft and away_score_ft are not null
     *
     * 'awarded' is not a possible status in the canonical DB: the FDO importer
     * normalises AWARDED → 'finished' before persisting, so no special case is needed.
     *
     * Tie-breaking: points → goal_difference → goals_for → name ASC.
     * Head-to-head is NOT applied — flag this if official rules require it.
     *
     * @param  Collection  $matches  Eloquent collection; each item must have:
     *                               home_team_id, away_team_id, status,
     *                               homeTeam->name, awayTeam->name,
     *                               home_score_ft, away_score_ft
     * @return array<int, array{
     *     position: int, team_id: int, name: string,
     *     played: int, wins: int, draws: int, losses: int,
     *     goals_for: int, goals_against: int, goal_difference: int, points: int
     * }>
     */
    public static function calculate(Collection $matches): array
    {
        $table = [];

        // First pass: register every team that appears in any match of the season.
        foreach ($matches as $match) {
            $homeId = $match->home_team_id;
            $awayId = $match->away_team_id;

            if (! isset($table[$homeId])) {
                $table[$homeId] = self::emptyRow($homeId, $match->homeTeam->name);
            }
            if (! isset($table[$awayId])) {
                $table[$awayId] = self::emptyRow($awayId, $match->awayTeam->name);
            }
        }

        // Second pass: accumulate stats only from definitively finished matches.
        foreach ($matches as $match) {
            if ($match->status !== 'finished'
                || $match->home_score_ft === null
                || $match->away_score_ft === null) {
                continue;
            }

            $homeId = $match->home_team_id;
            $awayId = $match->away_team_id;
            $hg     = (int) $match->home_score_ft;
            $ag     = (int) $match->away_score_ft;

            $table[$homeId]['played']++;
            $table[$awayId]['played']++;

            $table[$homeId]['goals_for']     += $hg;
            $table[$homeId]['goals_against'] += $ag;
            $table[$awayId]['goals_for']     += $ag;
            $table[$awayId]['goals_against'] += $hg;

            if ($hg > $ag) {
                $table[$homeId]['wins']++;
                $table[$homeId]['points'] += 3;
                $table[$awayId]['losses']++;
            } elseif ($hg === $ag) {
                $table[$homeId]['draws']++;
                $table[$homeId]['points']++;
                $table[$awayId]['draws']++;
                $table[$awayId]['points']++;
            } else {
                $table[$awayId]['wins']++;
                $table[$awayId]['points'] += 3;
                $table[$homeId]['losses']++;
            }
        }

        foreach ($table as &$row) {
            $row['goal_difference'] = $row['goals_for'] - $row['goals_against'];
        }
        unset($row);

        // NOTE: tie-breaking does not include head-to-head (e.g. Serie A official rules).
        usort($table, static function (array $a, array $b): int {
            if ($a['points'] !== $b['points'])          return $b['points'] <=> $a['points'];
            if ($a['goal_difference'] !== $b['goal_difference']) return $b['goal_difference'] <=> $a['goal_difference'];
            if ($a['goals_for'] !== $b['goals_for'])    return $b['goals_for'] <=> $a['goals_for'];
            return strcmp($a['name'], $b['name']);
        });

        foreach ($table as $i => &$row) {
            $row['position'] = $i + 1;
        }
        unset($row);

        return array_values($table);
    }

    private static function emptyRow(int $teamId, string $name): array
    {
        return [
            'team_id'         => $teamId,
            'name'            => $name,
            'played'          => 0,
            'wins'            => 0,
            'draws'           => 0,
            'losses'          => 0,
            'goals_for'       => 0,
            'goals_against'   => 0,
            'goal_difference' => 0,
            'points'          => 0,
            'position'        => 0,
        ];
    }
}
