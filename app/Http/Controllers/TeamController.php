<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamAnalyticsCalculator;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Carbon\Carbon;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function show(Team $team): View
    {
        $team->load('country');

        $context = $this->resolveAnalyticsContext($team);

        if (! $context) {
            abort(404);
        }

        [$competition, $season] = $context;

        $matches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id);
            })
            ->orderBy('kickoff_at')
            ->get();

        $homeMatches = $matches->where('home_team_id', $team->id)->values();
        $awayMatches = $matches->where('away_team_id', $team->id)->values();

        $finished = $matches->filter(static function (FootballMatch $match): bool {
            return $match->status === 'finished'
                && $match->home_score_ft !== null
                && $match->away_score_ft !== null;
        })->sortBy('kickoff_at')->values();

        $last5  = $finished->slice(-5)->values();
        $last10 = $finished->slice(-10)->values();

        $nextMatch = $this->resolveNextMatch($team);

        $matchStatistics = PreferredMatchStatisticResolver::forMatchIds($finished->pluck('id'));

        $seasonAnalytics = TeamAnalyticsCalculator::calculate($matches, $team->id, $matchStatistics);
        $homeAnalytics   = TeamAnalyticsCalculator::calculate($homeMatches, $team->id, $matchStatistics);
        $awayAnalytics   = TeamAnalyticsCalculator::calculate($awayMatches, $team->id, $matchStatistics);
        $last5Analytics  = TeamAnalyticsCalculator::calculate($last5, $team->id, $matchStatistics);
        $last10Analytics = TeamAnalyticsCalculator::calculate($last10, $team->id, $matchStatistics);

        // Pair each of the last 5 finished matches with the W/D/L the calculator
        // already derived for it (same order it was computed in) — avoids
        // recomputing the win/draw/loss orientation a second time here.
        $recentMatches = $last5->values()
            ->map(static fn (FootballMatch $match, int $i) => [
                'match'  => $match,
                'result' => $last5Analytics['form'][$i] ?? null,
            ])
            ->reverse()
            ->values();

        return view('teams.show', [
            'team'            => $team,
            'competition'     => $competition,
            'season'          => $season,
            'nextMatch'       => $nextMatch,
            'recentMatches'   => $recentMatches,
            'seasonAnalytics' => $seasonAnalytics,
            'homeAnalytics'   => $homeAnalytics,
            'awayAnalytics'   => $awayAnalytics,
            'last5Analytics'  => $last5Analytics,
            'last10Analytics' => $last10Analytics,
        ]);
    }

    /**
     * Picks the single (competition, season) context the analytics blocks
     * are computed against. A team can play in more than one competition —
     * for now we pick the most relevant one automatically; the code is split
     * out here so a future selector can override it without touching the
     * rest of the controller.
     *
     * The most statistically useful context is the most recent season with
     * actual finished results, not necessarily the "current" one — a team in
     * pre-season has an empty current season and a fully-played previous one.
     *
     * 1. Competition/season of the team's most recent finished match (FT score available).
     * 2. Fallback (no finished history at all): nearest upcoming scheduled fixture,
     *    else the team's most recent match overall — same as before this policy existed.
     *
     * @return array{0: Competition, 1: Season}|null
     */
    private function resolveAnalyticsContext(Team $team): ?array
    {
        $teamMatches = static fn () => FootballMatch::where(function ($query) use ($team) {
            $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id);
        });

        $reference = $teamMatches()
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->orderByDesc('kickoff_at')
            ->first(['competition_id', 'season_id']);

        if (! $reference) {
            $reference = $teamMatches()
                ->where('status', 'scheduled')
                ->where('kickoff_at', '>', Carbon::now('UTC'))
                ->orderBy('kickoff_at')
                ->first(['competition_id', 'season_id']);

            $reference ??= $teamMatches()
                ->orderByDesc('kickoff_at')
                ->first(['competition_id', 'season_id']);
        }

        if (! $reference) {
            return null;
        }

        $competition = Competition::with('country')->find($reference->competition_id);
        $season      = Season::find($reference->season_id);

        if (! $competition || ! $season) {
            return null;
        }

        return [$competition, $season];
    }

    /**
     * Nearest upcoming fixture for this team, independent of the analytics
     * context above — it may belong to a different (future) season and must
     * not force the analytics blocks to switch to it.
     */
    private function resolveNextMatch(Team $team): ?FootballMatch
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id);
            })
            ->where('kickoff_at', '>', Carbon::now('UTC'))
            ->orderBy('kickoff_at')
            ->first();
    }
}
