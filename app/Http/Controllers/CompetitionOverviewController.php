<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Services\Analytics\LeagueStandingsCalculator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompetitionOverviewController extends Controller
{
    public function index(Competition $competition): RedirectResponse
    {
        $season = $this->resolveCurrentSeason($competition);

        if (! $season) {
            abort(404);
        }

        return redirect()->route('competitions.seasons.show', [
            'competition' => $competition->slug,
            'season'      => $season->year_start,
        ]);
    }

    public function show(Competition $competition, string $season, Request $request): View
    {
        $competition->load('country');

        $seasonModel = Season::where('competition_id', $competition->id)
            ->where('year_start', $season)
            ->firstOrFail();

        $allSeasons = Season::where('competition_id', $competition->id)
            ->orderBy('year_start', 'desc')
            ->get();

        $mdRange = DB::table('matches')
            ->where('season_id', $seasonModel->id)
            ->whereNotNull('matchday')
            ->selectRaw('MIN(matchday) AS min_md, MAX(matchday) AS max_md')
            ->first();

        $minMatchday = (int) ($mdRange?->min_md ?? 1);
        $maxMatchday = (int) ($mdRange?->max_md ?? 1);

        $autoMatchday = $this->resolveCurrentMatchday($seasonModel->id);
        $matchday     = (int) $request->input('matchday', $autoMatchday);
        $matchday     = max($minMatchday, min($maxMatchday, $matchday));

        $window  = $this->resolveMatchdayWindow($seasonModel->id, $matchday);
        $matches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $competition->id)
            ->where('season_id', $seasonModel->id)
            ->where('matchday', $matchday)
            ->orderBy('kickoff_at', 'asc')
            ->get();

        $standings = null;
        if ($competition->format === 'league') {
            $allSeasonMatches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
                ->where('competition_id', $competition->id)
                ->where('season_id', $seasonModel->id)
                ->select(['id', 'home_team_id', 'away_team_id', 'status', 'home_score_ft', 'away_score_ft'])
                ->get();
            $standings = LeagueStandingsCalculator::calculate($allSeasonMatches);
        }

        return view('competitions.show', [
            'competition'     => $competition,
            'season'          => $seasonModel,
            'allSeasons'      => $allSeasons,
            'currentMatchday' => $matchday,
            'minMatchday'     => $minMatchday,
            'maxMatchday'     => $maxMatchday,
            'window'          => $window,
            'matches'         => $matches,
            'standings'       => $standings,
        ]);
    }

    // -------------------------------------------------------------------------

    private function resolveCurrentSeason(Competition $competition): ?Season
    {
        // Season with the nearest upcoming scheduled fixture
        $seasonId = FootballMatch::join('seasons', 'seasons.id', '=', 'matches.season_id')
            ->where('seasons.competition_id', $competition->id)
            ->where('matches.status', 'scheduled')
            ->where('matches.kickoff_at', '>', Carbon::now('UTC'))
            ->orderBy('matches.kickoff_at', 'asc')
            ->value('matches.season_id');

        if ($seasonId) {
            return Season::find($seasonId);
        }

        // Fallback: most recent season
        return Season::where('competition_id', $competition->id)
            ->orderBy('year_start', 'desc')
            ->first();
    }

    private function resolveCurrentMatchday(int $seasonId): int
    {
        $now = Carbon::now('UTC');

        $rows = DB::select(
            'SELECT matchday,
                    MIN(kickoff_at) AS first_kickoff,
                    MAX(kickoff_at) AS last_kickoff,
                    SUM(status IN ("scheduled","live")) AS open_count
             FROM matches
             WHERE season_id = ? AND matchday IS NOT NULL
             GROUP BY matchday
             ORDER BY matchday ASC',
            [$seasonId]
        );

        if (empty($rows)) {
            return 1;
        }

        $matchdays = collect($rows)->map(fn($r) => [
            'matchday' => (int) $r->matchday,
            'first'    => Carbon::createFromFormat('Y-m-d H:i:s', $r->first_kickoff, 'UTC'),
            'last'     => Carbon::createFromFormat('Y-m-d H:i:s', $r->last_kickoff, 'UTC'),
            'open'     => (int) $r->open_count,
        ]);

        // Season not started: every first kickoff is still in the future
        if ($matchdays->first()['first']->isAfter($now)) {
            return $matchdays->first()['matchday'];
        }

        // Season finished: last kickoff of last round is past
        if ($matchdays->last()['last']->isBefore($now)) {
            return $matchdays->last()['matchday'];
        }

        // Find the most recent round that has started
        $started = $matchdays->filter(fn($r) => $r['first']->lte($now));
        $current = $started->last();

        // If last kickoff is past and no match is still open → advance to next round
        if ($current['last']->isBefore($now) && $current['open'] === 0) {
            $next = $matchdays->first(fn($r) => $r['matchday'] > $current['matchday']);
            if ($next) {
                return $next['matchday'];
            }
        }

        return $current['matchday'];
    }

    private function resolveMatchdayWindow(int $seasonId, int $matchday): array
    {
        $row = DB::table('matches')
            ->where('season_id', $seasonId)
            ->where('matchday', $matchday)
            ->selectRaw('MIN(kickoff_at) AS first_kickoff, MAX(kickoff_at) AS last_kickoff')
            ->first();

        if (! $row || ! $row->first_kickoff) {
            return ['first' => null, 'last' => null];
        }

        return [
            'first' => Carbon::createFromFormat('Y-m-d H:i:s', $row->first_kickoff, 'UTC'),
            'last'  => Carbon::createFromFormat('Y-m-d H:i:s', $row->last_kickoff, 'UTC'),
        ];
    }
}
