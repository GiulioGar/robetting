<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\Analytics\TeamAnalyticsCalculator;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MatchController extends Controller
{
    public function show(FootballMatch $match): View
    {
        $match->load(['competition.country', 'season', 'homeTeam', 'awayTeam']);

        $homePrevious = $this->previousMatches($match, $match->home_team_id);
        $awayPrevious = $this->previousMatches($match, $match->away_team_id);

        $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(
            collect([$match->id])
                ->merge($homePrevious->pluck('id'))
                ->merge($awayPrevious->pluck('id'))
        );

        $matchStatistic = $matchStatistics->get($match->id);

        $homeOnly = $homePrevious->where('home_team_id', $match->home_team_id)->values();
        $awayOnly = $awayPrevious->where('away_team_id', $match->away_team_id)->values();

        $homeAnalytics = [
            'season'    => TeamAnalyticsCalculator::calculate($homePrevious, $match->home_team_id, $matchStatistics),
            'last5'     => TeamAnalyticsCalculator::calculate($this->lastN($homePrevious, 5), $match->home_team_id, $matchStatistics),
            'last10'    => TeamAnalyticsCalculator::calculate($this->lastN($homePrevious, 10), $match->home_team_id, $matchStatistics),
            'home_only' => TeamAnalyticsCalculator::calculate($homeOnly, $match->home_team_id, $matchStatistics),
        ];

        $awayAnalytics = [
            'season'    => TeamAnalyticsCalculator::calculate($awayPrevious, $match->away_team_id, $matchStatistics),
            'last5'     => TeamAnalyticsCalculator::calculate($this->lastN($awayPrevious, 5), $match->away_team_id, $matchStatistics),
            'last10'    => TeamAnalyticsCalculator::calculate($this->lastN($awayPrevious, 10), $match->away_team_id, $matchStatistics),
            'away_only' => TeamAnalyticsCalculator::calculate($awayOnly, $match->away_team_id, $matchStatistics),
        ];

        return view('matches.show', [
            'match'           => $match,
            'matchStatistic'  => $matchStatistic,
            'homeAnalytics'   => $homeAnalytics,
            'awayAnalytics'   => $awayAnalytics,
        ]);
    }

    /**
     * Every finished match of the same competition/season for $teamId strictly
     * before this match's kickoff — the as-of-kickoff cutoff that keeps every
     * pre-match analytics block free of data leakage. Never includes the
     * match itself (kickoff_at is never "<" its own value) or any later match.
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
}
