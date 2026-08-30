<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\SeasonExternalId;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchUpdateService;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ApiFootballAdminController extends Controller
{
    public function __construct()
    {
        abort_if(!app()->isLocal(), 404);
    }

    // -------------------------------------------------------------------------
    // Dashboard / Monitor
    // -------------------------------------------------------------------------

    public function dashboard(): View
    {
        $ds   = DataSource::where('slug', 'api-football')->first();
        $dsId = $ds?->id;

        $ceis = $ds
            ? CompetitionExternalId::where('data_source_id', $dsId)
                ->with('competition')
                ->get()
            : collect();

        $compIds = $ceis->pluck('competition_id')->filter()->all();

        $definitiveStatuses = ['finished', 'awarded', 'walkover'];

        // Load all sync runs for these competitions in one query, sorted desc by started_at
        $allSyncRuns = $dsId && $compIds
            ? DataSyncRun::where('data_source_id', $dsId)
                ->whereIn('competition_id', $compIds)
                ->orderBy('started_at', 'desc')
                ->get()
                ->groupBy('competition_id')
            : collect();

        $stats = $ceis->map(function (CompetitionExternalId $cei) use (
            $dsId, $definitiveStatuses, $allSyncRuns
        ) {
            $compId = $cei->competition_id;

            // Teams from season_team for seasons with an api-football SeasonExternalId
            $apiSeasonIds = SeasonExternalId::where('data_source_id', $dsId)
                ->where('competition_id', $compId)
                ->pluck('season_id');

            $teamIdsInSeasons = $apiSeasonIds->isNotEmpty()
                ? DB::table('season_team')
                    ->whereIn('season_id', $apiSeasonIds)
                    ->distinct()
                    ->pluck('team_id')
                : collect();

            $totalTeams          = $teamIdsInSeasons->count();
            $teamExternalIds     = $dsId && $teamIdsInSeasons->isNotEmpty()
                ? TeamExternalId::where('data_source_id', $dsId)->whereIn('team_id', $teamIdsInSeasons)->count()
                : 0;
            $teamsWithoutMapping = $totalTeams - $teamExternalIds;

            // Match counts
            $totalMatches    = FootballMatch::where('competition_id', $compId)->count();
            $definitiveCount = FootballMatch::where('competition_id', $compId)
                ->whereIn('status', $definitiveStatuses)->count();
            $postponedCount  = FootballMatch::where('competition_id', $compId)->where('status', 'postponed')->count();
            $suspendedCount  = FootballMatch::where('competition_id', $compId)->where('status', 'suspended')->count();
            $tbdCount        = FootballMatch::where('competition_id', $compId)->where('status', 'tbd')->count();

            // Matches without api-football mapping
            $matchIds              = FootballMatch::where('competition_id', $compId)->pluck('id');
            $matchExternalIdsCount = $dsId && $matchIds->isNotEmpty()
                ? MatchExternalId::where('data_source_id', $dsId)->whereIn('match_id', $matchIds)->count()
                : 0;
            $matchesWithoutMapping = $totalMatches - $matchExternalIdsCount;

            // Last sync runs (already sorted desc by started_at)
            $compRuns          = $allSyncRuns->get($compId, collect());
            $lastTeamSync      = $compRuns->where('sync_type', 'team_sync')->first();
            $lastFixtureFull   = $compRuns->where('sync_type', 'fixture_sync')->where('mode', 'full')->first();
            $lastFixtureRefresh = $compRuns->where('sync_type', 'fixture_sync')->where('mode', 'refresh')->first();
            $lastAnySync       = $compRuns->first();

            // Synthetic status
            $status = 'ok';
            if ($lastTeamSync === null || $lastFixtureFull === null) {
                $status = 'attenzione';
            }
            if ($teamsWithoutMapping > 0 || $matchesWithoutMapping > 0) {
                $status = 'attenzione';
            }
            if ($lastAnySync && $lastAnySync->status === 'failed') {
                $status = 'errore';
            }

            return [
                'competition'             => $cei->competition,
                'league_id'               => $cei->external_id,
                'total_teams'             => $totalTeams,
                'team_external_ids'       => $teamExternalIds,
                'teams_without_mapping'   => $teamsWithoutMapping,
                'total_matches'           => $totalMatches,
                'definitive_matches'      => $definitiveCount,
                'non_definitive_matches'  => $totalMatches - $definitiveCount,
                'postponed'               => $postponedCount,
                'suspended'               => $suspendedCount,
                'tbd'                     => $tbdCount,
                'match_external_ids'      => $matchExternalIdsCount,
                'matches_without_mapping' => $matchesWithoutMapping,
                'last_team_sync'          => $lastTeamSync,
                'last_fixture_full'       => $lastFixtureFull,
                'last_fixture_refresh'    => $lastFixtureRefresh,
                'last_any_sync'           => $lastAnySync,
                'status'                  => $status,
            ];
        });

        $lastResultRefresh = $dsId
            ? DataSyncRun::where('data_source_id', $dsId)
                ->where('sync_type', 'result_refresh')
                ->orderBy('started_at', 'desc')
                ->first()
            : null;

        $lastCatchUp = $dsId
            ? DataSyncRun::where('data_source_id', $dsId)
                ->where('sync_type', 'catch_up')
                ->orderBy('started_at', 'desc')
                ->first()
            : null;

        // Recent/upcoming matches for the manual update tool — window ±14 days.
        // Ordered desc so the most recent/soonest appear first in the select.
        $recentMatches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->whereBetween('kickoff_at', [now()->subDays(14), now()->addDays(14)])
            ->orderByDesc('kickoff_at')
            ->limit(60)
            ->get();

        return view('admin.api-football.dashboard', [
            'stats'             => $stats,
            'lastResultRefresh' => $lastResultRefresh,
            'lastCatchUp'       => $lastCatchUp,
            'recentMatches'     => $recentMatches,
            'matchUpdateReport' => session('match_update_report'),
            'matchUpdateError'  => session('match_update_error'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Match Update (manual orchestrator)
    // -------------------------------------------------------------------------

    public function matchUpdate(Request $request, ApiFootballMatchUpdateService $service): RedirectResponse
    {
        $matchId = (int) $request->input('match_id');
        $match   = FootballMatch::find($matchId);

        if (!$match) {
            return redirect()
                ->route('admin.api-football.dashboard')
                ->with('match_update_error', "Match ID {$matchId} non trovato nel database.");
        }

        $result = $service->update($match);

        return redirect()
            ->route('admin.api-football.dashboard')
            ->with('match_update_report', array_merge($result, ['match_id' => $matchId]));
    }

    // -------------------------------------------------------------------------
    // Teams
    // -------------------------------------------------------------------------

    public function teams(): View
    {
        return view('admin.api-football.teams', [
            'report' => session('team_sync_report'),
        ]);
    }

    public function syncTeams(Request $request, ApiFootballTeamSyncService $service): RedirectResponse
    {
        $season = (int) $request->input('season', 2026);
        $result = $service->syncAllCompetitions($season);

        return redirect()
            ->route('admin.api-football.teams')
            ->with('team_sync_report', $result);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    public function fixtures(): View
    {
        return view('admin.api-football.fixtures', [
            'report' => session('fixture_sync_report'),
            'modes'  => [
                ApiFootballFixtureSyncService::MODE_FULL    => 'FULL — carica tutte le fixture',
                ApiFootballFixtureSyncService::MODE_REFRESH => 'REFRESH — aggiorna solo non definitive',
            ],
        ]);
    }

    public function syncFixtures(Request $request, ApiFootballFixtureSyncService $service): RedirectResponse
    {
        $season = (int) $request->input('season', 2026);
        $mode   = $request->input('mode', ApiFootballFixtureSyncService::MODE_FULL);

        if (!in_array($mode, [ApiFootballFixtureSyncService::MODE_FULL, ApiFootballFixtureSyncService::MODE_REFRESH], true)) {
            $mode = ApiFootballFixtureSyncService::MODE_FULL;
        }

        $result = $service->syncAllCompetitions($season, $mode);

        return redirect()
            ->route('admin.api-football.fixtures')
            ->with('fixture_sync_report', $result);
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    public function statistics(): View
    {
        return view('admin.api-football.statistics', [
            'report' => session('statistics_sync_report'),
        ]);
    }

    public function syncStatistics(ApiFootballMatchStatisticsSyncService $service): RedirectResponse
    {
        set_time_limit(900);

        $result = $service->syncAll();

        return redirect()
            ->route('admin.api-football.statistics')
            ->with('statistics_sync_report', $result);
    }
}
