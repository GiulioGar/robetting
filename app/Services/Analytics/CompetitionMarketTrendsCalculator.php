<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * Derives market-trend frequencies (1X2, BTTS, over/under lines, ...) from a
 * plain collection of canonical matches. Purely computational: no query,
 * no Request, no Blade, no knowledge of competition/season/matchday — the
 * caller decides which matches to pass in, so the same formulas work for a
 * full season, a team's home/away matches, or a "last N" window.
 *
 * Full-time markets use matches where:
 *   - status === 'finished'
 *   - home_score_ft and away_score_ft are not null
 *
 * Half-time markets use matches where:
 *   - home_score_ht and away_score_ht are not null
 *
 * The two denominators are independent — a finished match without HT data
 * contributes to full-time markets but not half-time ones.
 */
class CompetitionMarketTrendsCalculator
{
    private const FT_GOAL_LINES = [0, 1, 2, 3, 4]; // 0.5, 1.5, 2.5, 3.5, 4.5
    private const HT_GOAL_LINES = [0, 1, 2];        // 0.5, 1.5, 2.5

    /**
     * @param  Collection  $matches  Eloquent collection; each item must have:
     *                               status, home_score_ft, away_score_ft,
     *                               home_score_ht, away_score_ht
     * @return array{
     *     coverage: array{full_time: int, half_time: int},
     *     result_1x2: array, btts: array,
     *     full_time_goals: array, half_time_goals: array, btts_half_time: array,
     *     team_scoring: array
     * }
     */
    public static function calculate(Collection $matches): array
    {
        $ft = $matches->filter(static function ($match): bool {
            return $match->status === 'finished'
                && $match->home_score_ft !== null
                && $match->away_score_ft !== null;
        })->values();

        $ht = $matches->filter(static function ($match): bool {
            return $match->home_score_ht !== null && $match->away_score_ht !== null;
        })->values();

        $ftTotal = $ft->count();
        $htTotal = $ht->count();

        $ft1x2       = ['home' => 0, 'draw' => 0, 'away' => 0];
        $ftBttsYes   = 0;
        $homeScored  = 0;
        $awayScored  = 0;
        $atLeastOneFailed = 0;
        $ftGoalsOver = array_fill_keys(self::FT_GOAL_LINES, 0);

        foreach ($ft as $match) {
            $h = (int) $match->home_score_ft;
            $a = (int) $match->away_score_ft;

            if ($h > $a) {
                $ft1x2['home']++;
            } elseif ($h === $a) {
                $ft1x2['draw']++;
            } else {
                $ft1x2['away']++;
            }

            if ($h > 0 && $a > 0) {
                $ftBttsYes++;
            }
            if ($h > 0) {
                $homeScored++;
            }
            if ($a > 0) {
                $awayScored++;
            }
            if ($h === 0 || $a === 0) {
                $atLeastOneFailed++;
            }

            $totalGoals = $h + $a;
            foreach (self::FT_GOAL_LINES as $line) {
                if ($totalGoals >= $line + 1) {
                    $ftGoalsOver[$line]++;
                }
            }
        }

        $htBttsYes   = 0;
        $htGoalsOver = array_fill_keys(self::HT_GOAL_LINES, 0);

        foreach ($ht as $match) {
            $h = (int) $match->home_score_ht;
            $a = (int) $match->away_score_ht;

            if ($h > 0 && $a > 0) {
                $htBttsYes++;
            }

            $totalGoals = $h + $a;
            foreach (self::HT_GOAL_LINES as $line) {
                if ($totalGoals >= $line + 1) {
                    $htGoalsOver[$line]++;
                }
            }
        }

        $fullTimeGoals = [];
        foreach ($ftGoalsOver as $line => $overCount) {
            $fullTimeGoals["over_{$line}_5"]  = self::outcome($overCount, $ftTotal);
            $fullTimeGoals["under_{$line}_5"] = self::outcome($ftTotal - $overCount, $ftTotal);
        }

        $halfTimeGoals = [];
        foreach ($htGoalsOver as $line => $overCount) {
            $halfTimeGoals["over_{$line}_5_ht"]  = self::outcome($overCount, $htTotal);
            $halfTimeGoals["under_{$line}_5_ht"] = self::outcome($htTotal - $overCount, $htTotal);
        }

        return [
            'coverage' => [
                'full_time' => $ftTotal,
                'half_time' => $htTotal,
            ],
            'result_1x2' => [
                'home' => self::outcome($ft1x2['home'], $ftTotal),
                'draw' => self::outcome($ft1x2['draw'], $ftTotal),
                'away' => self::outcome($ft1x2['away'], $ftTotal),
            ],
            'btts' => [
                'yes' => self::outcome($ftBttsYes, $ftTotal),
                'no'  => self::outcome($ftTotal - $ftBttsYes, $ftTotal),
            ],
            'full_time_goals' => $fullTimeGoals,
            'half_time_goals' => $halfTimeGoals,
            'btts_half_time'  => [
                'yes' => self::outcome($htBttsYes, $htTotal),
                'no'  => self::outcome($htTotal - $htBttsYes, $htTotal),
            ],
            'team_scoring' => [
                'home_scored'                  => self::outcome($homeScored, $ftTotal),
                'away_scored'                  => self::outcome($awayScored, $ftTotal),
                'at_least_one_failed_to_score' => self::outcome($atLeastOneFailed, $ftTotal),
            ],
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
            'percentage' => $total > 0 ? round($count / $total * 100, 4) : null,
        ];
    }
}
