<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Services\Analytics\HeadToHeadCalculator;
use App\Services\Analytics\PlayerRecentLoadCalculator;
use App\Services\Analytics\TeamAgeProfileCalculator;
use App\Services\Analytics\TeamAnalyticsCalculator;
use App\Services\Analytics\TeamScheduleLoadCalculator;
use App\Services\Analytics\TeamStarterContinuityCalculator;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Serves both finished and future/scheduled matches on the same page (Fase 1
 * + Fase 2 of the Match Page): the header adapts to whether an FT score
 * exists, while every pre-match analytics block always applies the same
 * as-of-kickoff cutoff regardless of the target match's own status — so
 * reopening a finished match still shows the stats as they stood right
 * before that match's kickoff, not the final season totals.
 */
class MatchController extends Controller
{
    private const H2H_LIMIT = 5;

    public function show(FootballMatch $match): View
    {
        $match->load(['competition.country', 'season', 'homeTeam', 'awayTeam']);

        $lineupDsSlug = config('imports.lineups_source_slug', 'api-football');
        $lineupDs     = DataSource::where('slug', $lineupDsSlug)->first();
        $lineups      = $lineupDs
            ? $match->lineups()
                ->with('players')
                ->where('data_source_id', $lineupDs->id)
                ->get()
                ->keyBy('team_id')
            : collect();

        $homePrevious = $this->previousMatches($match, $match->home_team_id);
        $awayPrevious = $this->previousMatches($match, $match->away_team_id);
        $h2hMatches   = $this->headToHead($match);

        $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(
            collect([$match->id])
                ->merge($homePrevious->pluck('id'))
                ->merge($awayPrevious->pluck('id'))
        );

        $matchStatistic = $matchStatistics->get($match->id);

        $matchEvents = MatchEvent::where('match_id', $match->id)
            ->orderBy('minute')
            ->orderBy('id')
            ->get();

        $homeHomeOnly = $homePrevious->where('home_team_id', $match->home_team_id)->values();
        $awayAwayOnly = $awayPrevious->where('away_team_id', $match->away_team_id)->values();

        $homeSeasonAnalytics = TeamAnalyticsCalculator::calculate($homePrevious, $match->home_team_id, $matchStatistics);
        $homeLast5Analytics  = TeamAnalyticsCalculator::calculate($this->lastN($homePrevious, 5), $match->home_team_id, $matchStatistics);
        $homeLast10Analytics = TeamAnalyticsCalculator::calculate($this->lastN($homePrevious, 10), $match->home_team_id, $matchStatistics);
        $homeHomeAnalytics   = TeamAnalyticsCalculator::calculate($homeHomeOnly, $match->home_team_id, $matchStatistics);

        $awaySeasonAnalytics = TeamAnalyticsCalculator::calculate($awayPrevious, $match->away_team_id, $matchStatistics);
        $awayLast5Analytics  = TeamAnalyticsCalculator::calculate($this->lastN($awayPrevious, 5), $match->away_team_id, $matchStatistics);
        $awayLast10Analytics = TeamAnalyticsCalculator::calculate($this->lastN($awayPrevious, 10), $match->away_team_id, $matchStatistics);
        $awayAwayAnalytics   = TeamAnalyticsCalculator::calculate($awayAwayOnly, $match->away_team_id, $matchStatistics);

        $headToHead = HeadToHeadCalculator::calculate($h2hMatches, $match->home_team_id, $match->away_team_id);

        $homeScheduleLoad = TeamScheduleLoadCalculator::calculate(
            $this->scheduleHistory($match, $match->home_team_id),
            $match->kickoff_at ?? now(),
        );
        $awayScheduleLoad = TeamScheduleLoadCalculator::calculate(
            $this->scheduleHistory($match, $match->away_team_id),
            $match->kickoff_at ?? now(),
        );

        $homePlayerLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->home_team_id);
        $awayPlayerLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->away_team_id);

        $allPlayerIds = array_unique(array_merge(array_keys($homePlayerLoad), array_keys($awayPlayerLoad)));
        $playerNames  = $allPlayerIds
            ? Player::whereIn('id', $allPlayerIds)->get(['id', 'name'])->keyBy('id')
            : collect();

        $homeTopPlayers = $this->buildTopPlayers($homePlayerLoad, $playerNames, 8);
        $awayTopPlayers = $this->buildTopPlayers($awayPlayerLoad, $playerNames, 8);

        $homeAgeProfile = TeamAgeProfileCalculator::calculateForMatch($match, $match->home_team_id);
        $awayAgeProfile = TeamAgeProfileCalculator::calculateForMatch($match, $match->away_team_id);

        $homeStarterContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, $match->home_team_id);
        $awayStarterContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, $match->away_team_id);

        return view('matches.show', [
            'match'               => $match,
            'matchStatistic'      => $matchStatistic,
            'matchEvents'         => $matchEvents,
            'lineups'             => $lineups,
            'homeSeasonAnalytics' => $homeSeasonAnalytics,
            'homeLast5Analytics'  => $homeLast5Analytics,
            'homeLast10Analytics' => $homeLast10Analytics,
            'homeHomeAnalytics'   => $homeHomeAnalytics,
            'awaySeasonAnalytics' => $awaySeasonAnalytics,
            'awayLast5Analytics'  => $awayLast5Analytics,
            'awayLast10Analytics' => $awayLast10Analytics,
            'awayAwayAnalytics'   => $awayAwayAnalytics,
            'headToHead'          => $headToHead,
            'h2hMatches'          => $h2hMatches,
            'homeScheduleLoad'    => $homeScheduleLoad,
            'awayScheduleLoad'    => $awayScheduleLoad,
            'homeTopPlayers'      => $homeTopPlayers,
            'awayTopPlayers'      => $awayTopPlayers,
            'homeAgeProfile'           => $homeAgeProfile,
            'awayAgeProfile'           => $awayAgeProfile,
            'homeStarterContinuity'    => $homeStarterContinuity,
            'awayStarterContinuity'    => $awayStarterContinuity,
        ]);
    }

    /**
     * Every finished match of the same competition/season for $teamId strictly
     * before this match's kickoff — the as-of-kickoff cutoff that keeps every
     * pre-match analytics block free of data leakage. Never includes the
     * match itself (kickoff_at is never "<" its own value) or any later match.
     * Applies identically whether $match itself is finished or still scheduled.
     */
    private function previousMatches(FootballMatch $match, int $teamId): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where(function ($query) use ($teamId) {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Last N matches chronologically. $matches is already ordered ascending
     * by kickoff_at (see previousMatches()), so this is just a tail slice.
     */
    private function lastN(Collection $matches, int $n): Collection
    {
        return $matches->slice(-$n)->values();
    }

    /**
     * Sort enriched player-load rows by minutes_last_30_days DESC (null last),
     * then by player_id ASC as a stable tiebreaker. Returns the top $limit rows.
     */
    private function buildTopPlayers(array $load, Collection $playerNames, int $limit): array
    {
        $enriched = [];
        foreach ($load as $playerId => $metrics) {
            $enriched[] = array_merge(
                ['player_id' => $playerId, 'name' => $playerNames->get($playerId)?->name ?? "Player #{$playerId}"],
                $metrics,
            );
        }

        usort($enriched, function (array $a, array $b): int {
            $diff = ($b['minutes_last_30_days'] ?? -1) <=> ($a['minutes_last_30_days'] ?? -1);
            return $diff !== 0 ? $diff : $a['player_id'] <=> $b['player_id'];
        });

        return array_slice($enriched, 0, $limit);
    }

    /**
     * All definitive matches (finished/awarded/walkover) for $teamId across ALL
     * competitions, strictly before this match's kickoff. Used only for the
     * schedule load calculator — no competition/season filter, no score filter.
     */
    private function scheduleHistory(FootballMatch $match, int $teamId): Collection
    {
        if ($match->kickoff_at === null) {
            return collect();
        }

        return FootballMatch::whereIn('status', ['finished', 'awarded', 'walkover'])
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->get(['id', 'kickoff_at']);
    }

    /**
     * Previous meetings between the two teams, either side, within the same
     * competition (never crossing competitions), strictly before this match's
     * kickoff — may span earlier seasons of that competition. Bounded by
     * ORDER BY kickoff_at DESC + LIMIT, so this is one dedicated, capped
     * query rather than a scan of the full match history.
     */
    private function headToHead(FootballMatch $match): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'season:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where(function ($query) use ($match) {
                $query->where(function ($q) use ($match) {
                    $q->where('home_team_id', $match->home_team_id)->where('away_team_id', $match->away_team_id);
                })->orWhere(function ($q) use ($match) {
                    $q->where('home_team_id', $match->away_team_id)->where('away_team_id', $match->home_team_id);
                });
            })
            ->orderByDesc('kickoff_at')
            ->limit(self::H2H_LIMIT)
            ->get();
    }
}
