<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use InvalidArgumentException;

class MatchOutcomeLabelBuilder
{
    /**
     * Statuses representing a conclusive, immutable match outcome.
     * Mirrors ApiFootballFixtureSyncService::DEFINITIVE_STATUSES — keep in sync.
     */
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /**
     * Build outcome labels from the regulation-time (FT) score only.
     *
     * ET and penalty scores (home_score_et, home_score_penalties, etc.) are
     * intentionally ignored: home_score_ft / away_score_ft always store
     * regulation time only, which is the correct unit for league prediction.
     *
     * @return array{
     *   home_goals: int, away_goals: int, goal_diff: int, total_goals: int,
     *   result_1x2: 'H'|'D'|'A',
     *   home_win: 0|1, draw: 0|1, away_win: 0|1,
     *   btts: 0|1, over_2_5: 0|1,
     * }
     *
     * @throws InvalidArgumentException if the match is not eligible for labelling
     */
    public static function build(FootballMatch $match): array
    {
        self::assertEligible($match);

        $homeGoals  = (int) $match->home_score_ft;
        $awayGoals  = (int) $match->away_score_ft;

        if ($homeGoals < 0 || $awayGoals < 0) {
            throw new InvalidArgumentException(
                'Match #' . ($match->id ?? 'unsaved') . ': negative score'
                . " (home={$homeGoals}, away={$awayGoals})."
            );
        }

        $totalGoals = $homeGoals + $awayGoals;
        $homeWin    = $homeGoals > $awayGoals ? 1 : 0;
        $draw       = $homeGoals === $awayGoals ? 1 : 0;
        $awayWin    = $homeGoals < $awayGoals ? 1 : 0;

        return [
            'home_goals'  => $homeGoals,
            'away_goals'  => $awayGoals,
            'goal_diff'   => $homeGoals - $awayGoals,
            'total_goals' => $totalGoals,
            'result_1x2'  => $homeWin ? 'H' : ($draw ? 'D' : 'A'),
            'home_win'    => $homeWin,
            'draw'        => $draw,
            'away_win'    => $awayWin,
            'btts'        => ($homeGoals > 0 && $awayGoals > 0) ? 1 : 0,
            'over_2_5'    => $totalGoals >= 3 ? 1 : 0,
        ];
    }

    private static function assertEligible(FootballMatch $match): void
    {
        if (! in_array($match->status, self::DEFINITIVE_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Match #' . ($match->id ?? 'unsaved') . ": status '{$match->status}'"
                . ' is not a definitive outcome status.'
            );
        }

        if ($match->home_score_ft === null) {
            throw new InvalidArgumentException(
                'Match #' . ($match->id ?? 'unsaved') . ': home_score_ft is null.'
            );
        }

        if ($match->away_score_ft === null) {
            throw new InvalidArgumentException(
                'Match #' . ($match->id ?? 'unsaved') . ': away_score_ft is null.'
            );
        }
    }
}
