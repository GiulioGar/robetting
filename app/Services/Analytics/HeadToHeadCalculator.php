<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * Derives a head-to-head summary from a plain collection of previous
 * meetings between two teams. Purely computational: no query, no Request,
 * no Blade — the caller decides which matches qualify as H2H (competition,
 * cutoff, limit) and passes an already-loaded, already-ordered Collection.
 *
 * The summary is oriented to the TARGET match's home/away teams, not to
 * whichever side each historical match was played on — e.g. if the target
 * match is Inter–Milan, a past "Milan 0–2 Inter" counts as a win for the
 * team that is home in the target match (Inter), not for the home team of
 * that past match (Milan). This is what makes the summary meaningful when
 * reused verbatim for a "Milan–Inter" target match later, where the same
 * historical fixture would flip which side it counts for.
 */
class HeadToHeadCalculator
{
    /**
     * @param  Collection  $matches  Eloquent collection of previous meetings between
     *                               $targetHomeTeamId and $targetAwayTeamId; each item
     *                               must have home_team_id, away_team_id, home_score_ft,
     *                               away_score_ft (finished, FT available — caller's job).
     * @return array{
     *     total_h2h: int,
     *     target_home_team_wins: int, draws: int, target_away_team_wins: int,
     *     target_home_team_goals: int, target_away_team_goals: int,
     *     avg_total_goals: ?float,
     *     btts: array{count: int, total: int, percentage: ?float},
     *     over_2_5: array{count: int, total: int, percentage: ?float}
     * }
     */
    public static function calculate(Collection $matches, int $targetHomeTeamId, int $targetAwayTeamId): array
    {
        $total = $matches->count();

        $targetHomeWins  = 0;
        $draws           = 0;
        $targetAwayWins  = 0;
        $targetHomeGoals = 0;
        $targetAwayGoals = 0;
        $bttsYes         = 0;
        $over25          = 0;

        foreach ($matches as $match) {
            $targetHomeWasHome = (int) $match->home_team_id === $targetHomeTeamId;

            $homeGoals = (int) $match->home_score_ft;
            $awayGoals = (int) $match->away_score_ft;

            $forTargetHome = $targetHomeWasHome ? $homeGoals : $awayGoals;
            $forTargetAway = $targetHomeWasHome ? $awayGoals : $homeGoals;

            $targetHomeGoals += $forTargetHome;
            $targetAwayGoals += $forTargetAway;

            if ($forTargetHome > $forTargetAway) {
                $targetHomeWins++;
            } elseif ($forTargetHome === $forTargetAway) {
                $draws++;
            } else {
                $targetAwayWins++;
            }

            if ($homeGoals > 0 && $awayGoals > 0) {
                $bttsYes++;
            }
            if (($homeGoals + $awayGoals) >= 3) {
                $over25++;
            }
        }

        return [
            'total_h2h'              => $total,
            'target_home_team_wins'  => $targetHomeWins,
            'draws'                  => $draws,
            'target_away_team_wins'  => $targetAwayWins,
            'target_home_team_goals' => $targetHomeGoals,
            'target_away_team_goals' => $targetAwayGoals,
            'avg_total_goals'        => $total > 0 ? round(($targetHomeGoals + $targetAwayGoals) / $total, 2) : null,
            'btts'                   => self::outcome($bttsYes, $total),
            'over_2_5'               => self::outcome($over25, $total),
        ];
    }

    /**
     * @return array{count: int, total: int, percentage: ?float}
     */
    private static function outcome(int $count, int $total): array
    {
        return [
            'count'      => $count,
            'total'      => $total,
            'percentage' => $total > 0 ? round($count / $total * 100, 1) : null,
        ];
    }
}
