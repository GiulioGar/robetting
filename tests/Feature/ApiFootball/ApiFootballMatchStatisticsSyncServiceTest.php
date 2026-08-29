<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballMatchStatisticsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    // API-Football external IDs for the test teams
    private const HOME_EXT_ID = '505';
    private const AWAY_EXT_ID = '489';

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

        CompetitionExternalId::create([
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '135',
            'external_name'  => 'Serie A',
        ]);

        SeasonExternalId::create([
            'season_id'      => $this->season->id,
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2026',
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
    // 1. Definitive match without stats → imported via API call
    // -------------------------------------------------------------------------

    public function test_definitive_match_without_stats_is_imported(): void
    {
        $match = $this->makeFinishedMatch(9001);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7490'],
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(7490, $result['daily_remaining']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at, 'fetched_at deve essere impostato dopo un sync riuscito');
        $this->assertSame(12, $stat->home_shots);
        $this->assertSame(9, $stat->away_shots);
        $this->assertSame(5, $stat->home_shots_on_target);
        $this->assertSame(3, $stat->away_shots_on_target);
        $this->assertSame(11, $stat->home_fouls);
        $this->assertSame(14, $stat->away_fouls);
        $this->assertSame(6, $stat->home_corners);
        $this->assertSame(4, $stat->away_corners);
        $this->assertSame(1, $stat->home_yellow_cards);
        $this->assertSame(2, $stat->away_yellow_cards);
        $this->assertSame(0, $stat->home_red_cards);
        $this->assertSame(0, $stat->away_red_cards);
    }

    // -------------------------------------------------------------------------
    // 2. Complete stats → zero API call
    // -------------------------------------------------------------------------

    public function test_complete_stats_skipped_no_api_call(): void
    {
        $match = $this->makeFinishedMatch(9002);
        $this->makeCompleteStats($match->id);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 3. Incomplete stats (home_shots NULL) → API called, row updated
    // -------------------------------------------------------------------------

    public function test_incomplete_stats_refreshed(): void
    {
        $match = $this->makeFinishedMatch(9003);
        $this->makeIncompleteStats($match->id);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertSame(12, $stat->home_shots);
    }

    // -------------------------------------------------------------------------
    // 4. Non-definitive match → excluded, no API call
    // -------------------------------------------------------------------------

    public function test_non_definitive_match_excluded(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'scheduled',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9004',
            'external_name'  => null,
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 5. Definitive match with no external ID → skipped with warning
    // -------------------------------------------------------------------------

    public function test_match_without_external_id_skipped_with_warning(): void
    {
        // Create a finished match but NO MatchExternalId for api-football
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['warnings']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 6. Idempotent: second sync after first creates complete stats → no API call
    // -------------------------------------------------------------------------

    public function test_idempotent_second_sync_no_api_call(): void
    {
        $this->makeFinishedMatch(9005);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        // First sync imports the stats
        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        // Second sync: stats are now complete → no API call
        Http::fake(); // reset so we can assert nothing was sent

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 7. DataSyncRun recorded with sync_type = statistics_sync
    // -------------------------------------------------------------------------

    public function test_data_sync_run_recorded(): void
    {
        Http::fake();

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
            'status'         => 'ok',
        ]);
    }

    // -------------------------------------------------------------------------
    // 8. Admin POST action delegates to service
    // -------------------------------------------------------------------------

    public function test_admin_action_delegates_to_service(): void
    {
        $this->app['env'] = 'local';

        Http::fake();

        $response = $this->withoutMiddleware()
            ->post(route('admin.api-football.statistics.sync'));

        $response->assertRedirect(route('admin.api-football.statistics'));

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
        ]);
    }

    // -------------------------------------------------------------------------
    // 9. Artisan command delegates to service
    // -------------------------------------------------------------------------

    public function test_artisan_command_delegates_to_service(): void
    {
        Http::fake();

        $this->artisan('robetting:sync-api-football-statistics')
            ->assertExitCode(0);

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
        ]);
    }

    // -------------------------------------------------------------------------
    // 10. Partial stats with fetched_at set → NOT a candidate (no API call)
    // -------------------------------------------------------------------------

    public function test_partial_stats_with_fetched_at_skipped_no_api_call(): void
    {
        $match = $this->makeFinishedMatch(9010);
        // Row has home_shots but home_fouls is null — source never had that metric.
        // fetched_at IS NOT NULL → considered complete, must not re-fetch.
        $this->makePartiallyFetchedStats($match->id);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 11. Empty API response → fetched_at set → second sync does not loop
    // -------------------------------------------------------------------------

    public function test_empty_api_response_sets_fetched_at_prevents_loop(): void
    {
        $match = $this->makeFinishedMatch(9011);

        $emptyResponse = [
            'errors'   => [],
            'results'  => 0,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [],   // source has no stats for this fixture
        ];

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($emptyResponse, 200)]);

        // First sync: empty response → fetched_at should be set, skipped count = 1
        $result1 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result1['candidates']);
        $this->assertSame(1, $result1['api_calls']);
        $this->assertSame(1, $result1['skipped']);
        $this->assertNotEmpty($result1['warnings']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat, 'Row deve essere creata anche per risposta vuota');
        $this->assertNotNull($stat->fetched_at, 'fetched_at deve essere impostato anche per risposta vuota');
        $this->assertNull($stat->home_shots, 'Nessun dato statistico deve essere presente');

        // Second sync: fetched_at is set → match is complete → no API call
        Http::fake();

        $result2 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result2['candidates']);
        $this->assertSame(0, $result2['api_calls']);
        $this->assertSame(1, $result2['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 12. Non-empty but unparsable response → fetched_at stays null → retriable
    // -------------------------------------------------------------------------

    public function test_unparsable_response_leaves_fetched_at_null_and_recandidates(): void
    {
        $match = $this->makeFinishedMatch(9013);

        // Response with only 1 team entry — parseResponse requires 2 to identify home/away.
        $oneTeamResponse = [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                ['team' => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'], 'statistics' => [
                    ['type' => 'Total Shots', 'value' => 12],
                ]],
            ],
        ];

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($oneTeamResponse, 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['warnings']);

        // No row created (or row without fetched_at) → must remain retriable
        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNull($stat?->fetched_at, 'fetched_at non deve essere impostato per risposta non parsabile');

        // Second sync: match is still a candidate
        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($oneTeamResponse, 200)]);

        $result2 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result2['candidates'], 'Match deve essere ancora candidato al sync successivo');
        $this->assertSame(1, $result2['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 13. home_shots present but fetched_at = null → still a candidate (old rule would have skipped)
    // -------------------------------------------------------------------------

    public function test_row_with_home_shots_but_no_fetched_at_is_candidate(): void
    {
        $match = $this->makeFinishedMatch(9012);
        // Simulates a legacy row created without going through the sync service
        // (fetched_at = null, but home_shots is set). Old completeness rule
        // would have skipped this; new rule correctly re-fetches it.
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => null,
            'home_shots'     => 5,
            'away_shots'     => 3,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates'], 'fetched_at=null deve rendere il match candidato anche se home_shots è presente');
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['updated']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat->fetched_at);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFinishedMatch(int $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function makeCompleteStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'             => $matchId,
            'data_source_id'       => $this->ds->id,
            'fetched_at'           => now(),
            'home_shots'           => 10,
            'away_shots'           => 8,
            'home_shots_on_target' => 4,
            'away_shots_on_target' => 3,
            'home_fouls'           => 12,
            'away_fouls'           => 14,
            'home_corners'         => 5,
            'away_corners'         => 3,
            'home_yellow_cards'    => 1,
            'away_yellow_cards'    => 2,
            'home_red_cards'       => 0,
            'away_red_cards'       => 0,
        ]);
    }

    /** A row that was previously fetched but some metrics came back null from the source. */
    private function makePartiallyFetchedStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'       => $matchId,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now(),   // already fetched
            'home_shots'     => 10,
            'away_shots'     => 8,
            'home_fouls'     => null,    // source did not provide this metric
            'away_fouls'     => null,
        ]);
    }

    /** A row that was never successfully fetched (fetched_at = null). */
    private function makeIncompleteStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'       => $matchId,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => null,    // not yet fetched → candidate
            'home_shots'     => null,
            'away_shots'     => null,
        ]);
    }

    /** Fake statistics response: home team ext_id=505 (home), ext_id=489 (away). */
    private function statsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'       => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 12],
                        ['type' => 'Shots on Goal', 'value' => 5],
                        ['type' => 'Fouls',         'value' => 11],
                        ['type' => 'Corner Kicks',  'value' => 6],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
                [
                    'team'       => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 9],
                        ['type' => 'Shots on Goal', 'value' => 3],
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
