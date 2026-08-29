<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiFootballAdminController extends Controller
{
    public function __construct()
    {
        abort_if(!app()->isLocal(), 404);
    }

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
}
