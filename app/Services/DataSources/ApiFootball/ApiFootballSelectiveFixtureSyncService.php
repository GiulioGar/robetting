<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\DB;

/**
 * Selective fixture import for additional competitions that must NOT be
 * imported in full — today, the 6 national cups — only fixtures where a
 * core-league team (per CORE_TEAMS(year_start), derived live from
 * season_team) is involved.
 *
 * Deliberately separate from SyncAdditionalCompetition (UEFA, full import
 * for the Elo Network — see Step A5.1). Never calls /teams: the /fixtures
 * payload already carries each team's id+name, which is enough to create
 * the few non-core opponents actually needed; core teams are already
 * mapped from the existing domestic sync. No advanced data (match
 * statistics/xG/lineups/player stats/events/injuries) is touched here —
 * robetting:serve enriches TEAM_HISTORY matches afterwards per the
 * MatchDataScope scope (see A5.5/A5.6).
 */
class ApiFootballSelectiveFixtureSyncService
{
    private ?DataSource $ds = null;

    public function __construct(
        private readonly ApiFootballClient $client,
        private readonly ApiFootballFixtureSyncService $fixtureSync,
    ) {
    }

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * @return array{status:string,fetched:int,relevant:int,teams_created:int,created:int,updated:int,unchanged:int,skipped:int,warnings:list<string>,api_calls:int,message?:string}
     */
    public function syncCompetition(CompetitionExternalId $cei, int $season): array
    {
        $ds = $this->dataSource();

        // Require season_external_id to exist — no auto-create (same contract as syncCompetition()).
        $sei = SeasonExternalId::where('data_source_id', $ds->id)
            ->where('competition_id', $cei->competition_id)
            ->where('external_id', (string) $season)
            ->first();

        if (!$sei) {
            return $this->emptyResult('no_season_external_id');
        }

        // 1. Fetch raw fixtures once — the only API calls this service makes.
        [$fixtureItems, $apiCalls] = $this->fetchRawFixtures($cei->external_id, $season);

        if ($fixtureItems === []) {
            return array_merge($this->emptyResult('ok'), ['api_calls' => $apiCalls]);
        }

        // 2. CORE_TEAMS(year_start) — the API "season" param IS the year_start
        //    by this project's convention (see ApiFootballLeagueImporter).
        $coreApiTeamIds = $this->coreApiTeamIdsForYearStart($ds->id, $season);

        // 3. Keep only fixtures where home or away resolves to a core team.
        $relevant = array_values(array_filter($fixtureItems, function (array $item) use ($coreApiTeamIds) {
            $homeId = (string) ($item['teams']['home']['id'] ?? '');
            $awayId = (string) ($item['teams']['away']['id'] ?? '');
            return isset($coreApiTeamIds[$homeId]) || isset($coreApiTeamIds[$awayId]);
        }));

        if ($relevant === []) {
            return array_merge($this->emptyResult('ok'), ['fetched' => count($fixtureItems), 'api_calls' => $apiCalls]);
        }

        // 4/5. Ensure every team in the relevant fixtures is mapped — minimal
        //      creation from the fixture payload itself, zero /teams calls.
        $teamsCreated = $this->ensureTeamsForFixtures($ds->id, $relevant);

        // 6. Persist ONLY the relevant fixtures via the existing, unmodified
        //    normalization/idempotency path.
        $persistResult = $this->fixtureSync->syncFilteredFixtures($cei, $season, $relevant);

        return [
            'status'        => $persistResult['status'],
            'fetched'       => count($fixtureItems),
            'relevant'      => count($relevant),
            'teams_created' => $teamsCreated,
            'created'       => $persistResult['created'],
            'updated'       => $persistResult['updated'],
            'unchanged'     => $persistResult['unchanged'],
            'skipped'       => $persistResult['skipped'],
            'warnings'      => $persistResult['warnings'],
            'api_calls'     => $apiCalls,
        ];
    }

    /** @return array{0: array<int, array>, 1: int}  [fixture items, api_calls] */
    private function fetchRawFixtures(string $leagueExtId, int $season): array
    {
        $allFixtures = [];
        $apiCalls    = 0;
        $page        = 1;

        do {
            $params = ['league' => $leagueExtId, 'season' => $season];
            if ($page > 1) {
                $params['page'] = $page;
            }

            $response    = $this->client->get('fixtures', $params);
            $apiCalls++;
            $allFixtures = array_merge($allFixtures, $response->response);

            $currentPage = (int) ($response->paging['current'] ?? 1);
            $totalPages  = (int) ($response->paging['total']  ?? 1);
            $page++;
        } while ($currentPage < $totalPages && $page <= 50);

        return [$allFixtures, $apiCalls];
    }

    /**
     * CORE_TEAMS(year_start) resolved from real season_team membership of the
     * 5 core leagues for that year_start, mapped to api-football external IDs.
     * Never uses the current/latest core-team list — promotion/relegation-safe.
     *
     * @return array<string, true>  api-football team external_id => true
     */
    private function coreApiTeamIdsForYearStart(int $dataSourceId, int $yearStart): array
    {
        $coreSlugs = array_values((array) config('api-football.core_leagues', []));
        if ($coreSlugs === []) {
            return [];
        }

        $teamIds = DB::table('season_team')
            ->join('seasons', 'seasons.id', '=', 'season_team.season_id')
            ->join('competitions', 'competitions.id', '=', 'seasons.competition_id')
            ->whereIn('competitions.slug', $coreSlugs)
            ->where('seasons.year_start', $yearStart)
            ->pluck('season_team.team_id');

        if ($teamIds->isEmpty()) {
            return [];
        }

        return TeamExternalId::where('data_source_id', $dataSourceId)
            ->whereIn('team_id', $teamIds)
            ->pluck('external_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Create minimal Team + TeamExternalId rows for any team appearing in
     * $fixtureItems that is not already mapped — using only the fixture
     * payload's teams.{home,away}.{id,name}. No /teams API call, and never
     * creates a team outside the already-filtered relevant fixtures.
     */
    private function ensureTeamsForFixtures(int $dataSourceId, array $fixtureItems): int
    {
        $needed = []; // external_id => name
        foreach ($fixtureItems as $item) {
            foreach (['home', 'away'] as $side) {
                $extId = (string) ($item['teams'][$side]['id']   ?? '');
                $name  = $item['teams'][$side]['name'] ?? null;
                if ($extId !== '' && $name) {
                    $needed[$extId] = $name;
                }
            }
        }

        if ($needed === []) {
            return 0;
        }

        $alreadyMapped = TeamExternalId::where('data_source_id', $dataSourceId)
            ->whereIn('external_id', array_keys($needed))
            ->pluck('external_id')
            ->flip()
            ->all();

        $created = 0;
        foreach ($needed as $extId => $name) {
            if (isset($alreadyMapped[$extId])) {
                continue;
            }

            $team = Team::create(['name' => $name]);
            TeamExternalId::create([
                'team_id'        => $team->id,
                'data_source_id' => $dataSourceId,
                'external_id'    => $extId,
                'external_name'  => $name,
            ]);
            $created++;
        }

        return $created;
    }

    private function emptyResult(string $status): array
    {
        return [
            'status'        => $status,
            'fetched'       => 0,
            'relevant'      => 0,
            'teams_created' => 0,
            'created'       => 0,
            'updated'       => 0,
            'unchanged'     => 0,
            'skipped'       => 0,
            'warnings'      => [],
            'api_calls'     => 0,
        ];
    }
}
