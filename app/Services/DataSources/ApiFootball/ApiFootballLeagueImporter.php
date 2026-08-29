<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\Season;
use App\Models\SeasonExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballLeagueImporter
{
    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Import one league + season from API-Football and persist idempotently.
     *
     * @throws ApiFootballException  on HTTP failure or API-level error
     * @return array{league_id: int, status: string, message?: string, slug?: string, ...}
     */
    public function importLeague(int $leagueId, int $season): array
    {
        $response = $this->client->get('leagues', ['id' => $leagueId, 'season' => $season]);

        $baseReport = [
            'league_id'          => $leagueId,
            'requests_remaining' => $response->requestsRemaining,
            'minute_remaining'   => $response->rateLimitRemaining,
        ];

        if (($response->paging['total'] ?? 1) > 1) {
            Log::warning("api-football-league-importer: unexpected pagination for league {$leagueId}");
        }

        if ($response->results === 0 || empty($response->response)) {
            $msg = "league {$leagueId}: empty response — skipped";
            Log::warning("api-football-league-importer: {$msg}");
            return array_merge($baseReport, ['status' => 'skipped', 'message' => $msg]);
        }

        if (count($response->response) !== 1) {
            $msg = "league {$leagueId}: ambiguous response ({$response->results} items) — skipped";
            Log::warning("api-football-league-importer: {$msg}");
            return array_merge($baseReport, ['status' => 'skipped', 'message' => $msg]);
        }

        $item        = $response->response[0];
        $leagueData  = $item['league']  ?? [];
        $countryData = $item['country'] ?? [];
        $seasons     = $item['seasons'] ?? [];

        // Find the requested season year in the seasons array.
        $seasonData = null;
        foreach ($seasons as $s) {
            if ((int) ($s['year'] ?? -1) === $season) {
                $seasonData = $s;
                break;
            }
        }

        if ($seasonData === null) {
            $msg = "league {$leagueId}: season {$season} not in response — skipped";
            Log::warning("api-football-league-importer: {$msg}");
            return array_merge($baseReport, ['status' => 'skipped', 'message' => $msg]);
        }

        $countryName = $countryData['name'] ?? null;
        if (empty($countryName)) {
            $msg = "league {$leagueId}: missing country name — skipped";
            Log::warning("api-football-league-importer: {$msg}");
            return array_merge($baseReport, ['status' => 'skipped', 'message' => $msg]);
        }

        $slug = ((array) config('api-football.core_leagues', []))[$leagueId] ?? null;
        if ($slug === null) {
            $msg = "league {$leagueId}: no canonical slug configured — skipped";
            Log::warning("api-football-league-importer: {$msg}");
            return array_merge($baseReport, ['status' => 'skipped', 'message' => $msg]);
        }

        $ds = $this->dataSource();

        // 1. Country — API-Football country.code is NOT ISO alpha-2; it can be
        // an extended regional code (e.g. "GB-ENG"). Store it only in football_code.
        // iso_code_alpha2/alpha3 are never auto-populated from API data.
        $footballCode = ($countryData['code'] ?? null) ?: null;

        $country = Country::updateOrCreate(
            ['name' => $countryName],
            ['football_code' => $footballCode],
        );

        // 2. Competition
        $format = strtolower($leagueData['type'] ?? '') === 'cup' ? 'cup' : 'league';

        $competition = Competition::updateOrCreate(
            ['slug' => $slug],
            [
                'country_id' => $country->id,
                'name'       => $leagueData['name'],
                'format'     => $format,
                'is_active'  => true,
            ],
        );

        // 3. CompetitionExternalId
        CompetitionExternalId::updateOrCreate(
            ['data_source_id' => $ds->id, 'external_id' => (string) $leagueId],
            ['competition_id' => $competition->id, 'external_name' => $leagueData['name']],
        );

        // 4. Season  (name convention: "2026/27")
        $yearEnd    = $season + 1;
        $seasonName = $season . '/' . substr((string) $yearEnd, -2);

        $dbSeason = Season::updateOrCreate(
            ['competition_id' => $competition->id, 'name' => $seasonName],
            [
                'year_start' => $season,
                'year_end'   => $yearEnd,
                'start_date' => $seasonData['start'] ?? null,
                'end_date'   => $seasonData['end']   ?? null,
                'is_current' => (bool) ($seasonData['current'] ?? false),
            ],
        );

        // 5. SeasonExternalId with full coverage blob
        SeasonExternalId::updateOrCreate(
            [
                'data_source_id' => $ds->id,
                'competition_id' => $competition->id,
                'external_id'    => (string) $season,
            ],
            [
                'season_id' => $dbSeason->id,
                'coverage'  => $seasonData['coverage'] ?? null,
            ],
        );

        return array_merge($baseReport, [
            'status'      => 'ok',
            'slug'        => $slug,
            'country'     => $countryName,
            'competition' => $leagueData['name'],
            'season'      => $seasonName,
            'is_current'  => $dbSeason->is_current,
        ]);
    }

    /**
     * Import all 5 core leagues for the given season. Catches HTTP/API errors
     * per league so one failure never aborts the rest.
     *
     * @return array{season: int, results: list<array>, requests_remaining: int|null}
     */
    public function importCoreLeagues(int $season): array
    {
        $leagueIds = array_keys((array) config('api-football.core_leagues', []));
        $results   = [];
        $lastRemaining = null;

        foreach ($leagueIds as $leagueId) {
            try {
                $report = $this->importLeague($leagueId, $season);
            } catch (ApiFootballException $e) {
                Log::error("api-football-league-importer: league {$leagueId} failed — {$e->getMessage()}");
                $report = [
                    'league_id' => $leagueId,
                    'status'    => 'failed',
                    'message'   => $e->getMessage(),
                ];
            }

            if (isset($report['requests_remaining'])) {
                $lastRemaining = $report['requests_remaining'];
            }

            $results[] = $report;
        }

        return [
            'season'             => $season,
            'results'            => $results,
            'requests_remaining' => $lastRemaining,
        ];
    }
}
