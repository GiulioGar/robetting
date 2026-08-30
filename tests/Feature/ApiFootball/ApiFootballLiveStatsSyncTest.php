<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live statistics sync via ApiFootballMatchStatisticsSyncService::syncLiveSingle / syncLive.
 *
 * Rules under test:
 *  - syncLiveSingle always fetches (no fetched_at guard). Never sets fetched_at.
 *  - upsert is idempotent — UNIQUE(match_id, data_source_id) guarantees one row.
 *  - Empty API response [] → no fetched_at, no stat values.
 *  - HTTP failures are caught by syncLive; returned as failed count, never thrown.
 *  - syncLive only considers status='live'; finished matches are ignored.
 *  - syncSingle (post-match) continues to set fetched_at.
 */
class ApiFootballLiveStatsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_EXT_ID = '505';
    private const AWAY_EXT_ID = '489';

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->competition = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);

        $this->season = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);

        TeamExternalId::create([
            'team_id'        => $this->homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::HOME_EXT_ID,
            'external_name'  => 'Inter',
        ]);

        TeamExternalId::create([
            'team_id'        => $this->awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::AWAY_EXT_ID,
            'external_name'  => 'Milan',
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Live stats created — fetched_at null
    // -------------------------------------------------------------------------

    public function test_live_stats_created_fetched_at_null(): void
    {
        $match = $this->makeLiveMatch('8001');

        Http::fake([
            '*fixtures/statistics*' => Http::response($this->statsResponse(7, 3), 200),
        ]);

        $result = $this->service()->syncLiveSingle($match, '8001');

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();

        $this->assertNotNull($stat);
        $this->assertNull($stat->fetched_at, 'syncLiveSingle non deve mai impostare fetched_at');
        $this->assertSame(7, $stat->home_shots);
        $this->assertSame(3, $stat->away_shots);
    }

    // -------------------------------------------------------------------------
    // 2. Secondo refresh con valori diversi → stessa riga aggiornata
    // -------------------------------------------------------------------------

    public function test_live_stats_second_refresh_updates_values(): void
    {
        $match = $this->makeLiveMatch('8002');

        Http::fake([
            '*fixtures/statistics*' => Http::sequence()
                ->push($this->statsResponse(4, 2), 200)
                ->push($this->statsResponse(9, 5), 200),
        ]);

        $service = $this->service();

        $service->syncLiveSingle($match, '8002');
        $this->assertSame(1, MatchStatistic::count());
        $this->assertSame(4, MatchStatistic::first()->home_shots);

        $service->syncLiveSingle($match, '8002');
        $this->assertSame(1, MatchStatistic::count(), 'Non devono esserci righe duplicate');
        $this->assertSame(9, MatchStatistic::first()->home_shots);
    }

    // -------------------------------------------------------------------------
    // 3. Stessi valori → nessun duplicato (UNIQUE garantisce una sola riga)
    // -------------------------------------------------------------------------

    public function test_live_stats_same_values_no_duplicate_row(): void
    {
        $match = $this->makeLiveMatch('8003');

        Http::fake([
            '*fixtures/statistics*' => Http::response($this->statsResponse(6, 4), 200),
        ]);

        $service = $this->service();
        $service->syncLiveSingle($match, '8003');
        $service->syncLiveSingle($match, '8003');

        $this->assertSame(1, MatchStatistic::count());
        Http::assertSentCount(2);
    }

    // -------------------------------------------------------------------------
    // 4. Live senza external ID → zero API call, warning loggato
    // -------------------------------------------------------------------------

    public function test_live_match_without_external_id_zero_api_call(): void
    {
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'live',
        ]);

        Http::fake();

        $result = $this->service()->syncLive();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 5. HTTP failure → syncLive non lancia, failed++, ServeLocal non si blocca
    // -------------------------------------------------------------------------

    public function test_http_failure_live_stats_does_not_throw(): void
    {
        $this->makeLiveMatch('8005');

        Http::fake([
            '*fixtures/statistics*' => Http::response([], 500),
        ]);

        $result = $this->service()->syncLive();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, MatchStatistic::count());
    }

    // -------------------------------------------------------------------------
    // 6. Finished → escluso da syncLive
    // -------------------------------------------------------------------------

    public function test_finished_match_not_in_sync_live_stats(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'definitive_at'  => now()->subMinutes(15),
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '8006',
            'external_name'  => null,
        ]);

        Http::fake();

        $result = $this->service()->syncLive();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 7. Risposta [] durante live → fetched_at resta null, nessun dato statistico
    // -------------------------------------------------------------------------

    public function test_live_empty_response_fetched_at_null(): void
    {
        $match = $this->makeLiveMatch('8007');

        Http::fake([
            '*fixtures/statistics*' => Http::response([
                'errors' => [], 'results' => 0, 'response' => [],
            ], 200),
        ]);

        $result = $this->service()->syncLiveSingle($match, '8007');

        $this->assertSame('empty', $result['outcome']);
        $this->assertSame(0, MatchStatistic::count(), 'Risposta vuota non deve creare righe in live');

        $match->refresh();
        $this->assertNull(
            MatchStatistic::where('match_id', $match->id)->value('fetched_at'),
            'fetched_at deve restare null per risposta vuota in live'
        );
    }

    // -------------------------------------------------------------------------
    // 8. Regressione: syncSingle post-match continua a valorizzare fetched_at
    // -------------------------------------------------------------------------

    public function test_post_match_sync_single_still_sets_fetched_at(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'definitive_at'  => now()->subMinutes(15),
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '8008',
            'external_name'  => null,
        ]);

        Http::fake([
            '*fixtures/statistics*' => Http::response($this->statsResponse(12, 9), 200),
        ]);

        $result = $this->service()->syncSingle($match, '8008');

        $this->assertSame('synced', $result['outcome']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();

        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at, 'syncSingle (post-match) deve impostare fetched_at');
        $this->assertSame(12, $stat->home_shots);
        $this->assertSame(9, $stat->away_shots);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): ApiFootballMatchStatisticsSyncService
    {
        return app(ApiFootballMatchStatisticsSyncService::class);
    }

    private function makeLiveMatch(string $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'live',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function statsResponse(int $homeShots, int $awayShots): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'       => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => $homeShots],
                        ['type' => 'Shots on Goal', 'value' => (int) round($homeShots * 0.4)],
                        ['type' => 'Fouls',         'value' => 11],
                        ['type' => 'Corner Kicks',  'value' => 6],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
                [
                    'team'       => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => $awayShots],
                        ['type' => 'Shots on Goal', 'value' => (int) round($awayShots * 0.33)],
                        ['type' => 'Fouls',         'value' => 14],
                        ['type' => 'Corner Kicks',  'value' => 4],
                        ['type' => 'Yellow Cards',  'value' => 2],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
            ],
        ];
    }
}
