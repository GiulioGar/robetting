<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes calendar-load and rest metrics for a single team relative to a
 * target match kickoff. Purely computational: no query, no Request, no Blade.
 *
 * Expected caller pattern (controller / feature engineering layer):
 *
 *   $previousMatches = FootballMatch::whereIn('status', ['finished', 'awarded', 'walkover'])
 *       ->where('kickoff_at', '<', $targetMatch->kickoff_at)   // strict: no leakage
 *       ->where(function ($q) use ($teamId) {
 *           $q->where('home_team_id', $teamId)
 *             ->orWhere('away_team_id', $teamId);
 *       })
 *       ->get();
 *
 *   $load = TeamScheduleLoadCalculator::calculate($previousMatches, $targetMatch->kickoff_at);
 *
 * No-leakage guarantee: the caller MUST pass kickoff_at < target (strict <).
 * The calculator ALSO enforces this internally as a second line of defence,
 * discarding any item whose kickoff_at >= targetKickoff before any computation.
 */
class TeamScheduleLoadCalculator
{
    /**
     * @param  Collection  $previousMatches  Definitive matches involving the team.
     *                                       Caller must pre-filter to kickoff_at < $targetKickoff,
     *                                       but the calculator re-applies the cutoff internally
     *                                       as a second anti-leakage guard.
     *                                       Items must expose kickoff_at as Carbon
     *                                       (Eloquent 'datetime' cast applied).
     * @param  Carbon  $targetKickoff  Kickoff of the match being analysed.
     *
     * @return array{
     *     rest_days:            int|null,
     *     matches_last_7_days:  int,
     *     matches_last_14_days: int,
     *     matches_last_30_days: int,
     * }
     */
    public static function calculate(Collection $previousMatches, Carbon $targetKickoff): array
    {
        // Internal anti-leakage guard: discard anything at or after the target kickoff,
        // regardless of what the caller passed in. Timestamp comparison avoids any
        // Carbon version differences with gte/lt operators.
        $targetTs = $targetKickoff->getTimestamp();
        $safeMatches = $previousMatches->filter(
            fn($m) => $m->kickoff_at->getTimestamp() < $targetTs
        );

        if ($safeMatches->isEmpty()) {
            return [
                'rest_days'            => null,
                'matches_last_7_days'  => 0,
                'matches_last_14_days' => 0,
                'matches_last_30_days' => 0,
            ];
        }

        // Most-recent previous match. Sort by Unix timestamp to avoid any
        // ambiguity with string/object comparison on Carbon values.
        $lastKickoff = $safeMatches
            ->sortByDesc(fn($m) => $m->kickoff_at->getTimestamp())
            ->first()
            ->kickoff_at;

        // rest_days: truncated whole-day difference (floor, not round).
        // When kickoff times differ the result is floored:
        //   target 21:00 – last 18:00 → 3d 3h  → 3
        //   target 18:00 – last 21:00 → 2d 21h → 2
        // Carbon v3 diffInDays() is signed; we use raw timestamp arithmetic to
        // guarantee a positive, deterministic floor regardless of Carbon version.
        $restDays = (int) floor(($targetTs - $lastKickoff->getTimestamp()) / 86400);

        // Window boundaries: left-inclusive, right-exclusive (right enforced by
        // both the caller's strict < filter and the internal guard above).
        $window7  = $targetKickoff->copy()->subDays(7);
        $window14 = $targetKickoff->copy()->subDays(14);
        $window30 = $targetKickoff->copy()->subDays(30);

        $count7  = 0;
        $count14 = 0;
        $count30 = 0;

        foreach ($safeMatches as $m) {
            $ko = $m->kickoff_at;
            if ($ko->gte($window7))  {
                $count7++;
            }
            if ($ko->gte($window14)) {
                $count14++;
            }
            if ($ko->gte($window30)) {
                $count30++;
            }
        }

        return [
            'rest_days'            => $restDays,
            'matches_last_7_days'  => $count7,
            'matches_last_14_days' => $count14,
            'matches_last_30_days' => $count30,
        ];
    }
}
