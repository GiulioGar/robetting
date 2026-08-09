<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\CompetitionSeasonZone;
use App\Models\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompetitionSeasonZoneController extends Controller
{
    public function index(Competition $competition, string $season): View
    {
        $seasonModel = Season::where('competition_id', $competition->id)
            ->where('year_start', $season)
            ->firstOrFail();

        $zones = CompetitionSeasonZone::where('season_id', $seasonModel->id)
            ->orderBy('sort_order')
            ->orderBy('from_position')
            ->get();

        $teamCount = $this->resolveTeamCount($seasonModel->id);

        return view('competition-zones.index', [
            'competition' => $competition,
            'season'      => $seasonModel,
            'zones'       => $zones,
            'teamCount'   => $teamCount,
        ]);
    }

    public function store(Competition $competition, string $season, Request $request): RedirectResponse
    {
        $seasonModel = Season::where('competition_id', $competition->id)
            ->where('year_start', $season)
            ->firstOrFail();

        $validated = $request->validate($this->rules($seasonModel->id));
        $validated['season_id']  = $seasonModel->id;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        CompetitionSeasonZone::create($validated);

        return redirect()
            ->route('competitions.seasons.zones.index', [
                'competition' => $competition->slug,
                'season'      => $season,
            ])
            ->with('success', 'Fascia aggiunta.');
    }

    public function update(Competition $competition, string $season, string $zone, Request $request): RedirectResponse
    {
        $seasonModel = Season::where('competition_id', $competition->id)
            ->where('year_start', $season)
            ->firstOrFail();

        $zoneModel = CompetitionSeasonZone::where('id', $zone)
            ->where('season_id', $seasonModel->id)
            ->firstOrFail();

        $validated = $request->validate($this->rules($seasonModel->id));
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $zoneModel->update($validated);

        return redirect()
            ->route('competitions.seasons.zones.index', [
                'competition' => $competition->slug,
                'season'      => $season,
            ])
            ->with('success', 'Fascia aggiornata.');
    }

    public function destroy(Competition $competition, string $season, string $zone): RedirectResponse
    {
        $seasonModel = Season::where('competition_id', $competition->id)
            ->where('year_start', $season)
            ->firstOrFail();

        CompetitionSeasonZone::where('id', $zone)
            ->where('season_id', $seasonModel->id)
            ->firstOrFail()
            ->delete();

        return redirect()
            ->route('competitions.seasons.zones.index', [
                'competition' => $competition->slug,
                'season'      => $season,
            ])
            ->with('success', 'Fascia eliminata.');
    }

    private function rules(int $seasonId): array
    {
        $teamCount  = $this->resolveTeamCount($seasonId);
        $maxRule    = $teamCount > 0 ? "max:{$teamCount}" : null;
        $toPosRules = array_values(array_filter(
            ['required', 'integer', 'min:1', 'gte:from_position', $maxRule]
        ));

        return [
            'from_position' => ['required', 'integer', 'min:1'],
            'to_position'   => $toPosRules,
            'type'          => ['required', 'string', 'max:50'],
            'label'         => ['required', 'string', 'max:100'],
            'css_class'     => ['nullable', 'string', 'max:50'],
            'color'         => ['nullable', 'string', 'max:20'],
            'status'        => ['required', 'in:confirmed,provisional'],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }

    private function resolveTeamCount(int $seasonId): int
    {
        $result = DB::select(
            'SELECT COUNT(*) AS cnt FROM (
                SELECT home_team_id AS team_id FROM matches WHERE season_id = ?
                UNION
                SELECT away_team_id FROM matches WHERE season_id = ?
             ) t',
            [$seasonId, $seasonId]
        );

        return (int) ($result[0]->cnt ?? 0);
    }
}
