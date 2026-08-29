<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballTeamSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private CompetitionExternalId $cei;

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

        $season = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->cei = CompetitionExternalId::create([
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '135',
            'external_name'  => 'Serie A',
        ]);

        SeasonExternalId::create([
            'season_id'      => $season->id,
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2026',
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path — create
    // -------------------------------------------------------------------------

    public function test_sync_creates_teams_and_external_ids(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response(
            $this->serieATeamsResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7490', 'X-RateLimit-Remaining' => '298'],
        )]);

        $report = app(ApiFootballTeamSyncService::class)->syncCompetition($this->cei, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, $report['unchanged']);
        $this->assertSame(1, $report['api_calls']);
        $this->assertSame(7490, $report['requests_remaining']);
        $this->assertSame(298,  $report['minute_remaining']);

        $this->assertSame(2, Team::count());
        $this->assertSame(2, TeamExternalId::count());

        $this->assertDatabaseHas('teams', ['name' => 'Internazionale', 'code' => 'INT', 'type' => 'club']);
        $this->assertDatabaseHas('teams', ['name' => 'AC Milan', 'code' => 'MIL', 'type' => 'club']);
        $this->assertDatabaseHas('team_external_ids', ['external_id' => '505', 'external_name' => 'Internazionale']);
        $this->assertDatabaseHas('team_external_ids', ['external_id' => '489', 'external_name' => 'AC Milan']);
    }

    public function test_country_resolved_by_name(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response(
            $this->serieATeamsResponse(),
            200,
        )]);

        app(ApiFootballTeamSyncService::class)->syncCompetition($this->cei, 2026);

        $italy = Country::where('name', 'Italy')->firstOrFail();
        $team  = Team::where('name', 'Internazionale')->firstOrFail();
        $this->assertSame($italy->id, $team->country_id);
    }

    public function test_national_team_type(): void
    {
        $body = $this->serieATeamsResponse();
        $body['response'][0]['team']['national'] = true;

        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($body, 200)]);

        app(ApiFootballTeamSyncService::class)->syncCompetition($this->cei, 2026);

        $this->assertDatabaseHas('teams', ['name' => 'Internazionale', 'type' => 'national']);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_sync_is_idempotent(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $service = app(ApiFootballTeamSyncService::class);
        $service->syncCompetition($this->cei, 2026);
        $report2 = $service->syncCompetition($this->cei, 2026);

        $this->assertSame(2, Team::count());
        $this->assertSame(2, TeamExternalId::count());
        $this->assertSame(0, $report2['created']);
        $this->assertSame(0, $report2['updated']);
        $this->assertSame(2, $report2['unchanged']);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_sync_updates_existing_team_when_data_changes(): void
    {
        $original = $this->serieATeamsResponse();
        $modified = $this->serieATeamsResponse();
        $modified['response'][0]['team']['name'] = 'FC Internazionale';

        Http::fake(['v3.football.api-sports.io/teams*' => Http::sequence()
            ->push($original, 200)
            ->push($modified, 200),
        ]);

        $service = app(ApiFootballTeamSyncService::class);
        $service->syncCompetition($this->cei, 2026);
        $report2 = $service->syncCompetition($this->cei, 2026);

        $this->assertSame(0, $report2['created']);
        $this->assertSame(1, $report2['updated']);
        $this->assertSame(1, $report2['unchanged']);
        $this->assertDatabaseHas('teams', ['name' => 'FC Internazionale']);
        $this->assertDatabaseMissing('teams', ['name' => 'Internazionale']);
    }

    // -------------------------------------------------------------------------
    // Missing season_external_id — skip without API call
    // -------------------------------------------------------------------------

    public function test_missing_season_external_id_skips_without_api_call(): void
    {
        Http::fake();

        // Create a second competition with no season_external_id
        $comp2 = Competition::create([
            'name'      => 'Premier League',
            'slug'      => 'premier-league',
            'format'    => 'league',
            'is_active' => true,
        ]);
        $cei2 = CompetitionExternalId::create([
            'competition_id' => $comp2->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '39',
            'external_name'  => 'Premier League',
        ]);
        // Intentionally NO SeasonExternalId for comp2

        $report = app(ApiFootballTeamSyncService::class)->syncCompetition($cei2, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, $report['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // API error handled gracefully
    // -------------------------------------------------------------------------

    public function test_api_error_is_caught_in_sync_all(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response([], 500)]);

        $result = app(ApiFootballTeamSyncService::class)->syncAllCompetitions(2026);

        $this->assertSame(1, count($result['results']));
        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertSame(0, $result['teams_created']);
    }

    // -------------------------------------------------------------------------
    // Empty API response
    // -------------------------------------------------------------------------

    public function test_empty_api_response_returns_ok_with_warning(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response([
            'errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => [],
        ], 200)]);

        $report = app(ApiFootballTeamSyncService::class)->syncCompetition($this->cei, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['created']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertSame(0, Team::count());
    }

    // -------------------------------------------------------------------------
    // Command uses same service
    // -------------------------------------------------------------------------

    public function test_artisan_command_uses_team_sync_service(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $this->artisan('robetting:sync-api-football-teams --season=2026')
            ->assertSuccessful();

        $this->assertSame(2, Team::count());
    }

    public function test_artisan_command_single_league_option(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $this->artisan('robetting:sync-api-football-teams --season=2026 --league=135')
            ->assertSuccessful();

        $this->assertSame(2, Team::count());
    }

    // -------------------------------------------------------------------------
    // season_team membership
    // -------------------------------------------------------------------------

    public function test_season_team_membership_is_created_on_sync(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $this->assertSame(0, DB::table('season_team')->count());

        app(ApiFootballTeamSyncService::class)->syncCompetition($this->cei, 2026);

        $this->assertSame(2, DB::table('season_team')->count());

        $season = Season::where('competition_id', $this->competition->id)->firstOrFail();
        $this->assertSame(2, DB::table('season_team')->where('season_id', $season->id)->count());
    }

    public function test_season_team_membership_is_idempotent(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $service = app(ApiFootballTeamSyncService::class);
        $service->syncCompetition($this->cei, 2026);
        $service->syncCompetition($this->cei, 2026); // second run — must not duplicate

        $this->assertSame(2, DB::table('season_team')->count());
    }

    public function test_team_can_belong_to_multiple_seasons(): void
    {
        // Second season for the same competition
        $season2 = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2027/28',
            'year_start'     => 2027,
            'year_end'       => 2028,
            'is_current'     => false,
        ]);
        SeasonExternalId::create([
            'season_id'      => $season2->id,
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2027',
        ]);

        Http::fake(['v3.football.api-sports.io/teams*' => Http::response($this->serieATeamsResponse(), 200)]);

        $service = app(ApiFootballTeamSyncService::class);
        $service->syncCompetition($this->cei, 2026); // season 2026/27
        $service->syncCompetition($this->cei, 2027); // season 2027/28

        // Each of the 2 teams appears in both seasons → 4 rows total
        $this->assertSame(4, DB::table('season_team')->count());

        $inter = Team::where('name', 'Internazionale')->firstOrFail();
        $this->assertSame(2, DB::table('season_team')->where('team_id', $inter->id)->count());
    }

    // -------------------------------------------------------------------------
    // Fake response helper
    // -------------------------------------------------------------------------

    private function serieATeamsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team' => [
                        'id'       => 505,
                        'name'     => 'Internazionale',
                        'code'     => 'INT',
                        'country'  => 'Italy',
                        'founded'  => 1908,
                        'national' => false,
                        'logo'     => 'https://example.com/inter.png',
                    ],
                    'venue' => [],
                ],
                [
                    'team' => [
                        'id'       => 489,
                        'name'     => 'AC Milan',
                        'code'     => 'MIL',
                        'country'  => 'Italy',
                        'founded'  => 1899,
                        'national' => false,
                        'logo'     => 'https://example.com/milan.png',
                    ],
                    'venue' => [],
                ],
            ],
        ];
    }
}
