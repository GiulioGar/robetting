<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Offline xG experiment export — NOT a production feature.
 *
 * For each target match and each side, over the team's Team History Recent
 * window (last WINDOW eligible official matches strictly before the target,
 * CORE + TEAM_HISTORY, cross-competition, cross-season — never extended
 * beyond WINDOW to fill missing xG):
 *
 *   - only window matches with kickoff_at <= target.kickoff_at - CUTOFF_HOURS
 *     may contribute xG (conservative availability cutoff: the real first-seen
 *     time of historical xG is not reconstructible);
 *   - xg_for / xg_against are oriented from the team's side
 *     (home: home_expected_goals / away_expected_goals, away: swapped);
 *   - null xG values are excluded from the average (each side independently);
 *     no usable observation => null. No imputation, no minimum threshold.
 *
 * Diagnostics (window size, observation counts, coverage) are exported but
 * are NOT model features. Reads only; never writes to the DB.
 */
class RecentXgFeatureExporter
{
    public const WINDOW       = 10;
    public const CUTOFF_HOURS = 120;

    public const COLUMNS = [
        'match_id',
        'home_recent_avg_xg_for',
        'home_recent_avg_xg_against',
        'away_recent_avg_xg_for',
        'away_recent_avg_xg_against',
        // diagnostics only
        'home_recent_window_matches',
        'home_recent_xg_for_obs',
        'home_recent_xg_against_obs',
        'home_recent_xg_coverage',
        'away_recent_window_matches',
        'away_recent_xg_for_obs',
        'away_recent_xg_against_obs',
        'away_recent_xg_coverage',
    ];

    /**
     * Pure per-team computation.
     *
     * @param  Collection<int, FootballMatch>  $teamMatchesAsc  eligible Team History matches of the team, kickoff ASC (any date)
     * @param  Collection<int, object>         $statsByMatchId  match_id => row exposing home_expected_goals / away_expected_goals
     * @return array{window_matches:int, for_obs:int, against_obs:int, avg_for:?float, avg_against:?float, coverage:?float}
     */
    public static function teamWindow(
        Collection $teamMatchesAsc,
        int $teamId,
        CarbonInterface $targetKickoff,
        Collection $statsByMatchId,
    ): array {
        $window = self::windowMatches($teamMatchesAsc, $targetKickoff);
        $cutoff = $targetKickoff->copy()->subHours(self::CUTOFF_HOURS);

        $for = [];
        $against = [];
        foreach ($window as $m) {
            if ($m->kickoff_at->gt($cutoff)) {
                continue;
            }
            $stat = $statsByMatchId->get($m->id);
            if ($stat === null) {
                continue;
            }
            $isHome  = (int) $m->home_team_id === $teamId;
            $xgFor   = $isHome ? $stat->home_expected_goals : $stat->away_expected_goals;
            $xgAgst  = $isHome ? $stat->away_expected_goals : $stat->home_expected_goals;
            if ($xgFor !== null) {
                $for[] = (float) $xgFor;
            }
            if ($xgAgst !== null) {
                $against[] = (float) $xgAgst;
            }
        }

        $n = $window->count();

        return [
            'window_matches' => $n,
            'for_obs'        => count($for),
            'against_obs'    => count($against),
            'avg_for'        => $for === [] ? null : array_sum($for) / count($for),
            'avg_against'    => $against === [] ? null : array_sum($against) / count($against),
            'coverage'       => $n === 0 ? null : count($for) / $n,
        ];
    }

    /**
     * The Recent window itself: last WINDOW matches strictly before the target.
     *
     * @param  Collection<int, FootballMatch>  $teamMatchesAsc
     * @return Collection<int, FootballMatch>
     */
    public static function windowMatches(Collection $teamMatchesAsc, CarbonInterface $targetKickoff): Collection
    {
        return $teamMatchesAsc
            ->filter(fn (FootballMatch $m) => $m->kickoff_at->lt($targetKickoff))
            ->slice(-self::WINDOW)
            ->values();
    }

    /**
     * @param  array<int, int>  $matchIds  target match ids, order preserved in the output
     * @return array<int, array<string, mixed>>  rows keyed positionally, one per input id, same order
     */
    public function export(array $matchIds): array
    {
        if (count($matchIds) !== count(array_unique($matchIds))) {
            throw new InvalidArgumentException('Duplicate match_id in target list.');
        }

        $targets = collect();
        foreach (array_chunk($matchIds, 1000) as $chunk) {
            $targets = $targets->merge(
                FootballMatch::whereIn('id', $chunk)->get(['id', 'home_team_id', 'away_team_id', 'kickoff_at'])
            );
        }
        $targets = $targets->keyBy('id');

        $missing = array_values(array_diff($matchIds, $targets->keys()->all()));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Target match_id(s) not found in DB: ' . implode(',', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : '')
            );
        }

        $teamIds = $targets->flatMap(fn ($m) => [(int) $m->home_team_id, (int) $m->away_team_id])->unique()->values()->all();

        // One batch query (+ scope classification) for all teams; per-team lists in memory.
        $eligible = TeamHistoryMatchEligibility::eligibleForTeams($teamIds);
        $byTeam  = [];
        $teamSet = array_flip($teamIds);
        foreach ($eligible as $m) {
            if (isset($teamSet[(int) $m->home_team_id])) {
                $byTeam[(int) $m->home_team_id][] = $m;
            }
            if (isset($teamSet[(int) $m->away_team_id])) {
                $byTeam[(int) $m->away_team_id][] = $m;
            }
        }
        $byTeam = array_map(fn (array $list) => collect($list), $byTeam);

        // Phase 1: windows (to know exactly which matches' stats are needed).
        $needed = [];
        foreach ($targets as $t) {
            foreach ([(int) $t->home_team_id, (int) $t->away_team_id] as $teamId) {
                foreach (self::windowMatches($byTeam[$teamId] ?? collect(), $t->kickoff_at) as $m) {
                    $needed[$m->id] = true;
                }
            }
        }

        // Phase 2: preferred statistics only for those matches.
        $stats = collect();
        foreach (array_chunk(array_keys($needed), 2000) as $chunk) {
            $stats = $stats->union(PreferredMatchStatisticResolver::forMatchIds($chunk));
        }

        $rows = [];
        foreach ($matchIds as $id) {
            $t    = $targets->get($id);
            $home = self::teamWindow($byTeam[(int) $t->home_team_id] ?? collect(), (int) $t->home_team_id, $t->kickoff_at, $stats);
            $away = self::teamWindow($byTeam[(int) $t->away_team_id] ?? collect(), (int) $t->away_team_id, $t->kickoff_at, $stats);

            $rows[] = [
                'match_id'                   => $id,
                'home_recent_avg_xg_for'     => $home['avg_for'],
                'home_recent_avg_xg_against' => $home['avg_against'],
                'away_recent_avg_xg_for'     => $away['avg_for'],
                'away_recent_avg_xg_against' => $away['avg_against'],
                'home_recent_window_matches' => $home['window_matches'],
                'home_recent_xg_for_obs'     => $home['for_obs'],
                'home_recent_xg_against_obs' => $home['against_obs'],
                'home_recent_xg_coverage'    => $home['coverage'],
                'away_recent_window_matches' => $away['window_matches'],
                'away_recent_xg_for_obs'     => $away['for_obs'],
                'away_recent_xg_against_obs' => $away['against_obs'],
                'away_recent_xg_coverage'    => $away['coverage'],
            ];
        }

        return $rows;
    }
}
