<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballTeamSyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Sync teams for one competition from API-Football.
     * Skips without API call if no season_external_id exists for this competition+season.
     *
     * @return array{competition_slug:string, league_id:string, status:string, created:int, updated:int, unchanged:int, warnings:list<string>, api_calls:int, requests_remaining:int|null, minute_remaining:int|null, message?:string}
     */
    public function syncCompetition(CompetitionExternalId $cei, int $season): array
    {
        $ds          = $this->dataSource();
        $competition = Competition::find($cei->competition_id);
        $slug        = $competition?->slug ?? "competition-{$cei->competition_id}";

        $base = [
            'competition_slug'  => $slug,
            'league_id'         => $cei->external_id,
            'created'           => 0,
            'updated'           => 0,
            'unchanged'         => 0,
            'warnings'          => [],
            'api_calls'         => 0,
            'requests_remaining'=> null,
            'minute_remaining'  => null,
        ];

        // Verify season_external_id exists — no auto-create
        $seiExists = SeasonExternalId::where('data_source_id', $ds->id)
            ->where('competition_id', $cei->competition_id)
            ->where('external_id', (string) $season)
            ->exists();

        if (!$seiExists) {
            $msg = "no season_external_id for {$slug} season {$season} — skipped";
            Log::warning("api-football-team-sync: {$msg}");
            return array_merge($base, ['status' => 'skipped', 'message' => $msg]);
        }

        $response = $this->client->get('teams', [
            'league' => $cei->external_id,
            'season' => $season,
        ]);

        $base['api_calls']          = 1;
        $base['requests_remaining'] = $response->requestsRemaining;
        $base['minute_remaining']   = $response->rateLimitRemaining;

        if (empty($response->response)) {
            $msg = "empty response for {$slug} season {$season}";
            Log::warning("api-football-team-sync: {$msg}");
            $base['warnings'][] = $msg;
            return array_merge($base, ['status' => 'ok', 'message' => $msg]);
        }

        foreach ($response->response as $item) {
            $outcome = $this->processTeamItem($item, $ds);
            if ($outcome === 'created')   $base['created']++;
            elseif ($outcome === 'updated')   $base['updated']++;
            elseif ($outcome === 'unchanged') $base['unchanged']++;
            else                              $base['warnings'][] = $outcome;
        }

        return array_merge($base, ['status' => 'ok']);
    }

    /**
     * Sync teams for all competitions that have an api-football competition_external_id.
     *
     * @return array{season:int, results:list<array>, teams_created:int, teams_updated:int}
     */
    public function syncAllCompetitions(int $season): array
    {
        $ds   = $this->dataSource();
        $ceis = CompetitionExternalId::where('data_source_id', $ds->id)->get();

        $results       = [];
        $totalCreated  = 0;
        $totalUpdated  = 0;

        foreach ($ceis as $cei) {
            try {
                $report = $this->syncCompetition($cei, $season);
            } catch (ApiFootballException $e) {
                $comp   = Competition::find($cei->competition_id);
                $report = [
                    'competition_slug'  => $comp?->slug ?? "competition-{$cei->competition_id}",
                    'league_id'         => $cei->external_id,
                    'status'            => 'failed',
                    'message'           => $e->getMessage(),
                    'created'           => 0,
                    'updated'           => 0,
                    'unchanged'         => 0,
                    'warnings'          => [],
                    'api_calls'         => 1,
                    'requests_remaining'=> null,
                    'minute_remaining'  => null,
                ];
                Log::error("api-football-team-sync: {$cei->external_id} failed — {$e->getMessage()}");
            }

            $totalCreated += $report['created'];
            $totalUpdated += $report['updated'];
            $results[]     = $report;
        }

        return [
            'season'        => $season,
            'results'       => $results,
            'teams_created' => $totalCreated,
            'teams_updated' => $totalUpdated,
        ];
    }

    /**
     * Process a single team item from the API response.
     * Returns 'created', 'updated', 'unchanged', or a warning string.
     */
    private function processTeamItem(array $item, DataSource $ds): string
    {
        $teamData = $item['team'] ?? [];
        $extId    = (string) ($teamData['id'] ?? '');

        if ($extId === '') {
            return 'warning: team item missing id';
        }

        $name        = $teamData['name']     ?? null;
        $code        = $teamData['code']     ?? null;
        $foundedYear = $teamData['founded']  ?? null;
        $isNational  = (bool) ($teamData['national'] ?? false);
        $type        = $isNational ? 'national' : 'club';
        $countryName = $teamData['country']  ?? null;

        if (empty($name)) {
            return "warning: team {$extId} missing name";
        }

        $countryId = $countryName
            ? Country::where('name', $countryName)->value('id')
            : null;

        // Look up existing mapping
        $tei = TeamExternalId::where('data_source_id', $ds->id)
            ->where('external_id', $extId)
            ->with('team')
            ->first();

        if ($tei) {
            $team   = $tei->team;
            $dirty  = [];

            if ($team->name        !== $name)                     $dirty['name']         = $name;
            if (($team->code       ?? '') !== ($code ?? ''))      $dirty['code']         = $code;
            if ($team->type        !== $type)                     $dirty['type']         = $type;
            if ($team->country_id  !== $countryId)               $dirty['country_id']   = $countryId;
            if ($team->founded_year !== $foundedYear)            $dirty['founded_year'] = $foundedYear;

            if ($tei->external_name !== $name) {
                $tei->update(['external_name' => $name]);
            }

            if (empty($dirty)) {
                return 'unchanged';
            }

            $team->update($dirty);
            return 'updated';
        }

        // Create new team + mapping
        $team = Team::create([
            'country_id'   => $countryId,
            'name'         => $name,
            'code'         => $code,
            'type'         => $type,
            'founded_year' => $foundedYear,
            'is_active'    => true,
        ]);

        TeamExternalId::create([
            'team_id'       => $team->id,
            'data_source_id'=> $ds->id,
            'external_id'   => $extId,
            'external_name' => $name,
        ]);

        return 'created';
    }
}
