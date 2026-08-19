<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use Carbon\Carbon;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $competitions = Competition::with('country')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $cards = $competitions->map(function (Competition $competition) {
            $season    = $this->resolveRelevantSeason($competition);
            $testMatch = $season ? $this->resolveTestMatch($competition, $season) : null;

            return [
                'competition' => $competition,
                'season'      => $season,
                'testMatch'   => $testMatch,
                'testTeam'    => $testMatch?->homeTeam,
            ];
        });

        return view('home', ['cards' => $cards]);
    }

    /**
     * Same concept as CompetitionOverviewController::resolveCurrentSeason():
     * season of the nearest upcoming scheduled fixture, else the most recent
     * season by year_start. Duplicated here in minimal form rather than
     * extracted into a shared service — that method is private and only a
     * handful of lines; pulling it out now would be a wider refactor than
     * this homepage needs.
     */
    private function resolveRelevantSeason(Competition $competition): ?Season
    {
        $seasonId = FootballMatch::join('seasons', 'seasons.id', '=', 'matches.season_id')
            ->where('seasons.competition_id', $competition->id)
            ->where('matches.status', 'scheduled')
            ->where('matches.kickoff_at', '>', Carbon::now('UTC'))
            ->orderBy('matches.kickoff_at', 'asc')
            ->value('matches.season_id');

        if ($seasonId) {
            return Season::find($seasonId);
        }

        return Season::where('competition_id', $competition->id)
            ->orderBy('year_start', 'desc')
            ->first();
    }

    /**
     * One representative match for the dev/test quick links: the nearest
     * upcoming fixture in the resolved season, else the most recently
     * finished one. Its home team doubles as the representative team link.
     */
    private function resolveTestMatch(Competition $competition, Season $season): ?FootballMatch
    {
        $upcoming = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where('status', 'scheduled')
            ->where('kickoff_at', '>', Carbon::now('UTC'))
            ->orderBy('kickoff_at')
            ->first();

        return $upcoming ?? FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where('status', 'finished')
            ->orderByDesc('kickoff_at')
            ->first();
    }
}
