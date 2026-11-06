<?php

namespace App\Services\Prediction\Corner;

use App\Models\FootballMatch;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Illuminate\Support\Collection;

/**
 * Pre-match CORNER features, defined exactly as in tools/scripts/corner_experiment.py (Step 83):
 *  - last N (5, 10) finished matches of each team in ANY competition, strictly before the kickoff; averages over the window matches
 *    that HAVE corner data (null when none), plus that count (a real 0 when none);
 *  - venue form: last 10 home matches (home team) / last 10 away matches (away team), same rules;
 *  - competition-season mean of home / away corners over earlier matches with both counts, and their number.
 * A match without statistics never contributes (and is never a 0). Values are unrounded floats (training used unrounded values).
 */
final class CornerFeatureBuilder
{
    public const WINDOWS = [5, 10];
    public const VENUE_WINDOW = 10;

    /** @return array<string, float|int|null> */
    public static function forMatch(FootballMatch $match): array
    {
        $kickoff = $match->kickoff_at;
        $f = [];
        foreach (['h' => [(int) $match->home_team_id, 'home'], 'a' => [(int) $match->away_team_id, 'away']] as $side => [$teamId, $venue]) {
            $last = self::lastMatches($teamId, $kickoff, null, max(self::WINDOWS));
            $venueLast = self::lastMatches($teamId, $kickoff, $venue, self::VENUE_WINDOW);
            $stats = PreferredMatchStatisticResolver::forMatchIds($last->pluck('id')->merge($venueLast->pluck('id')));
            foreach (self::WINDOWS as $n) {
                [$for, $ag, $cnt] = self::average($last->take($n), $teamId, $stats);
                $f["{$side}_for{$n}"] = $for;
                $f["{$side}_ag{$n}"] = $ag;
                $f["{$side}_n{$n}"] = $cnt;
            }
            [$f["{$side}_vfor"], $f["{$side}_vag"], $f["{$side}_vn"]] = self::average($venueLast, $teamId, $stats);
        }

        [$f['lg_home'], $f['lg_away'], $f['lg_n']] = self::leagueMeans($match);

        return $f;
    }

    /** @return Collection<int, FootballMatch> newest first (kickoff desc, id desc) */
    private static function lastMatches(int $teamId, $kickoff, ?string $venue, int $limit): Collection
    {
        return FootballMatch::where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $kickoff)
            ->where(function ($q) use ($teamId, $venue): void {
                if ($venue === 'home') {
                    $q->where('home_team_id', $teamId);
                } elseif ($venue === 'away') {
                    $q->where('away_team_id', $teamId);
                } else {
                    $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
                }
            })
            ->orderByDesc('kickoff_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'home_team_id', 'away_team_id']);
    }

    /** @return array{0: float|null, 1: float|null, 2: int} corners for, against, number of window matches with corner data */
    private static function average(Collection $window, int $teamId, Collection $stats): array
    {
        $for = $against = 0;
        $n = 0;
        foreach ($window as $m) {
            $s = $stats->get($m->id);
            if ($s === null || $s->home_corners === null || $s->away_corners === null) {
                continue;
            }
            $isHome = (int) $m->home_team_id === $teamId;
            $for += (int) ($isHome ? $s->home_corners : $s->away_corners);
            $against += (int) ($isHome ? $s->away_corners : $s->home_corners);
            $n++;
        }

        return $n === 0 ? [null, null, 0] : [$for / $n, $against / $n, $n];
    }

    /** @return array{0: float|null, 1: float|null, 2: int} */
    private static function leagueMeans(FootballMatch $match): array
    {
        $ids = FootballMatch::where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->pluck('id');
        $home = $away = 0;
        $n = 0;
        foreach (PreferredMatchStatisticResolver::forMatchIds($ids) as $s) {
            if ($s->home_corners === null || $s->away_corners === null) {
                continue;
            }
            $home += (int) $s->home_corners;
            $away += (int) $s->away_corners;
            $n++;
        }

        return $n === 0 ? [null, null, 0] : [$home / $n, $away / $n, $n];
    }
}
