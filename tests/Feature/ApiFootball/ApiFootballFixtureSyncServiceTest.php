<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballFixtureSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private CompetitionExternalId $cei;
    private int $homeTeamId;
    private int $awayTeamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds      = DataSource::where('slug', 'api-football')->firstOrFail();
        $country       = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

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

        $this->cei = CompetitionExternalId::create([
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

        // Home team — Internazionale
        $homeTeam = Team::create(['name' => 'Internazionale', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create([
            'team_id'        => $homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '505',
            'external_name'  => 'Internazionale',
        ]);
        $this->homeTeamId = $homeTeam->id;

        // Away team — AC Milan
        $awayTeam = Team::create(['name' => 'AC Milan', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create([
            'team_id'        => $awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '489',
            'external_name'  => 'AC Milan',
        ]);
        $this->awayTeamId = $awayTeam->id;
    }

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    public function test_full_sync_creates_match_and_external_id(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7480', 'X-RateLimit-Remaining' => '295'],
        )]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, $report['unchanged']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(1, $report['api_calls']);
        $this->assertSame(7480, $report['requests_remaining']);
        $this->assertSame(295,  $report['minute_remaining']);

        $this->assertSame(1, FootballMatch::count());
        $this->assertSame(1, MatchExternalId::count());

        $match = FootballMatch::first();
        $this->assertSame($this->competition->id, $match->competition_id);
        $this->assertSame($this->season->id, $match->season_id);
        $this->assertSame($this->homeTeamId, $match->home_team_id);
        $this->assertSame($this->awayTeamId, $match->away_team_id);
        $this->assertSame('2026-08-22 20:45:00', $match->kickoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $match->status);
        $this->assertSame('Regular Season - 1', $match->round);
        $this->assertSame(1, $match->matchday);
        $this->assertSame('San Siro', $match->venue_name);

        $this->assertDatabaseHas('match_external_ids', ['external_id' => '9001']);
    }

    public function test_scores_mapped_correctly_for_finished_match(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse([
                'fixture.status.short' => 'FT',
                'goals.home'           => 2,
                'goals.away'           => 1,
                'score.halftime.home'  => 1,
                'score.halftime.away'  => 0,
                'score.fulltime.home'  => 2,
                'score.fulltime.away'  => 1,
            ]),
            200,
        )]);

        app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $match = FootballMatch::first();
        $this->assertSame('finished', $match->status);
        $this->assertSame(1, $match->home_score_ht);
        $this->assertSame(0, $match->away_score_ht);
        $this->assertSame(2, $match->home_score_ft);
        $this->assertSame(1, $match->away_score_ft);
        $this->assertNull($match->home_score_et);
        $this->assertNull($match->home_score_penalties);
    }

    public function test_et_and_penalty_scores_mapped_for_pen_match(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse([
                'fixture.status.short'   => 'PEN',
                'score.halftime.home'    => 0,
                'score.halftime.away'    => 0,
                'score.fulltime.home'    => 1,
                'score.fulltime.away'    => 1,
                'score.extratime.home'   => 0,
                'score.extratime.away'   => 0,
                'score.penalty.home'     => 4,
                'score.penalty.away'     => 3,
            ]),
            200,
        )]);

        app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $match = FootballMatch::first();
        $this->assertSame('finished', $match->status);
        $this->assertSame(1, $match->home_score_ft);
        $this->assertSame(1, $match->away_score_ft);
        $this->assertSame(0, $match->home_score_et);
        $this->assertSame(4, $match->home_score_penalties);
        $this->assertSame(3, $match->away_score_penalties);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_full_sync_is_idempotent(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $service = app(ApiFootballFixtureSyncService::class);
        $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);
        $report2 = $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(1, FootballMatch::count());
        $this->assertSame(1, MatchExternalId::count());
        $this->assertSame(0, $report2['created']);
        $this->assertSame(0, $report2['updated']);
        $this->assertSame(1, $report2['unchanged']);
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    public function test_paging_fetches_all_fixtures_across_multiple_pages(): void
    {
        $item1 = $this->defaultFixtureItem(); // fixture 9001, matchday 1

        $item2                         = $this->defaultFixtureItem();
        $item2['fixture']['id']        = 9002;
        $item2['fixture']['date']      = '2026-09-01T20:45:00+00:00';
        $item2['league']['round']      = 'Regular Season - 2';

        $page1 = ['errors' => [], 'results' => 2, 'paging' => ['current' => 1, 'total' => 2], 'response' => [$item1]];
        $page2 = ['errors' => [], 'results' => 2, 'paging' => ['current' => 2, 'total' => 2], 'response' => [$item2]];

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::sequence()
            ->push($page1, 200)
            ->push($page2, 200),
        ]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(2, $report['api_calls']);

        $this->assertSame(2, FootballMatch::count());
        $this->assertSame(2, MatchExternalId::count());

        $this->assertDatabaseHas('match_external_ids', ['external_id' => '9001']);
        $this->assertDatabaseHas('match_external_ids', ['external_id' => '9002']);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_update_kickoff(): void
    {
        $original = $this->fixtureResponse();
        $updated  = $this->fixtureResponse(['fixture.date' => '2026-08-29T18:00:00+00:00']);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::sequence()
            ->push($original, 200)
            ->push($updated, 200),
        ]);

        $service = app(ApiFootballFixtureSyncService::class);
        $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);
        $report2 = $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(1, $report2['updated']);
        $this->assertSame(0, $report2['unchanged']);

        $match = FootballMatch::first();
        $this->assertSame('2026-08-29 18:00:00', $match->kickoff_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_update_status(): void
    {
        $original = $this->fixtureResponse(['fixture.status.short' => 'NS']);
        $updated  = $this->fixtureResponse([
            'fixture.status.short' => 'FT',
            'score.fulltime.home'  => 2,
            'score.fulltime.away'  => 1,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::sequence()
            ->push($original, 200)
            ->push($updated, 200),
        ]);

        $service = app(ApiFootballFixtureSyncService::class);
        $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);
        $report2 = $service->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(1, $report2['updated']);

        $match = FootballMatch::first();
        $this->assertSame('finished', $match->status);
        $this->assertSame(2, $match->home_score_ft);
        $this->assertSame(1, $match->away_score_ft);
    }

    // -------------------------------------------------------------------------
    // Missing team mapping
    // -------------------------------------------------------------------------

    public function test_missing_home_team_mapping_skips_and_warns(): void
    {
        // Remove home team external id
        TeamExternalId::where('external_id', '505')->delete();

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertStringContainsString('505', $report['warnings'][0]);
        $this->assertSame(0, FootballMatch::count());
    }

    public function test_missing_away_team_mapping_skips_and_warns(): void
    {
        TeamExternalId::where('external_id', '489')->delete();

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertStringContainsString('489', $report['warnings'][0]);
        $this->assertSame(0, FootballMatch::count());
    }

    public function test_missing_team_does_not_create_team(): void
    {
        // Both teams exist from setUp; we only remove the external_id mapping for home
        TeamExternalId::where('external_id', '505')->delete();
        $teamCountBefore = Team::count(); // 2

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        // No opportunistic team creation: count must be unchanged
        $this->assertSame($teamCountBefore, Team::count());
        $this->assertSame(0, FootballMatch::count());
    }

    // -------------------------------------------------------------------------
    // REFRESH mode — definitive matches skipped
    // -------------------------------------------------------------------------

    public function test_refresh_skips_finished_match(): void
    {
        // Pre-create a finished match in the DB
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeamId,
            'away_team_id'   => $this->awayTeamId,
            'kickoff_at'     => '2026-08-22 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9001',
        ]);

        // API returns the same fixture with a different kickoff (should be ignored)
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(['fixture.date' => '2026-09-01T20:00:00+00:00']),
            200,
        )]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_REFRESH);

        $this->assertSame(0, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(1, $report['skipped']);

        // Kickoff must NOT have changed
        $this->assertSame(
            '2026-08-22 20:45:00',
            FootballMatch::first()->kickoff_at->utc()->format('Y-m-d H:i:s')
        );
    }

    public function test_refresh_updates_postponed_match(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeamId,
            'away_team_id'   => $this->awayTeamId,
            'kickoff_at'     => '2026-08-22 20:45:00',
            'status'         => 'postponed',
        ]);
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9001',
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse([
                'fixture.status.short' => 'PST',
                'fixture.date'         => '2026-09-10T20:00:00+00:00',
            ]),
            200,
        )]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_REFRESH);

        $this->assertSame(0, $report['skipped']);
        $this->assertSame(1, $report['updated']);

        $match->refresh();
        $this->assertSame('2026-09-10 20:00:00', $match->kickoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('postponed', $match->status);
    }

    public function test_refresh_updates_tbd_match(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeamId,
            'away_team_id'   => $this->awayTeamId,
            'kickoff_at'     => null,
            'status'         => 'tbd',
        ]);
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9001',
        ]);

        // API now knows the kickoff and status
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse([
                'fixture.status.short' => 'NS',
                'fixture.date'         => '2026-09-15T20:45:00+00:00',
            ]),
            200,
        )]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_REFRESH);

        $this->assertSame(0, $report['skipped']);
        $this->assertSame(1, $report['updated']);

        $match->refresh();
        $this->assertSame('2026-09-15 20:45:00', $match->kickoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $match->status);
    }

    public function test_refresh_updates_suspended_match(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeamId,
            'away_team_id'   => $this->awayTeamId,
            'kickoff_at'     => '2026-08-22 20:45:00',
            'status'         => 'suspended',
        ]);
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9001',
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(['fixture.status.short' => 'FT',
                'score.fulltime.home' => 1, 'score.fulltime.away' => 0]),
            200,
        )]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($this->cei, 2026, ApiFootballFixtureSyncService::MODE_REFRESH);

        $this->assertSame(1, $report['updated']);
        $match->refresh();
        $this->assertSame('finished', $match->status);
    }

    // -------------------------------------------------------------------------
    // Missing season_external_id — skip without API call
    // -------------------------------------------------------------------------

    public function test_missing_season_external_id_skips_without_api_call(): void
    {
        Http::fake();

        $comp2 = Competition::create(['name' => 'PL', 'slug' => 'premier-league', 'format' => 'league', 'is_active' => true]);
        $cei2  = CompetitionExternalId::create([
            'competition_id' => $comp2->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '39',
            'external_name'  => 'Premier League',
        ]);

        $report = app(ApiFootballFixtureSyncService::class)
            ->syncCompetition($cei2, 2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, $report['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Command uses same service
    // -------------------------------------------------------------------------

    public function test_artisan_command_full_mode(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $this->artisan('robetting:sync-api-football-fixtures --season=2026 --mode=full')
            ->assertSuccessful();

        $this->assertSame(1, FootballMatch::count());
    }

    public function test_artisan_command_refresh_mode(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $this->artisan('robetting:sync-api-football-fixtures --season=2026 --mode=refresh')
            ->assertSuccessful();
    }

    public function test_artisan_command_invalid_mode_fails(): void
    {
        $this->artisan('robetting:sync-api-football-fixtures --mode=invalid')
            ->assertFailed();
    }

    public function test_artisan_command_single_league_option(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($this->fixtureResponse(), 200)]);

        $this->artisan('robetting:sync-api-football-fixtures --season=2026 --league=135')
            ->assertSuccessful();

        $this->assertSame(1, FootballMatch::count());
    }

    // -------------------------------------------------------------------------
    // Admin 404 outside local
    // -------------------------------------------------------------------------

    public function test_admin_fixtures_get_returns_404_in_non_local_env(): void
    {
        $this->assertFalse(app()->isLocal());

        $this->get(route('admin.api-football.fixtures'))
            ->assertNotFound();
    }

    public function test_admin_fixtures_post_returns_404_in_non_local_env(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->assertFalse(app()->isLocal());

        $this->post(route('admin.api-football.fixtures.sync'), ['season' => '2026', 'mode' => 'full'])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Admin POST delegates to same service
    // -------------------------------------------------------------------------

    public function test_admin_post_delegates_to_fixture_sync_service(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $fakeResult = [
            'season'           => 2026,
            'mode'             => 'full',
            'fixtures_created' => 5,
            'fixtures_updated' => 0,
            'results'          => [],
        ];

        $mock = $this->mock(ApiFootballFixtureSyncService::class);
        $mock->shouldReceive('syncAllCompetitions')
            ->once()
            ->with(2026, ApiFootballFixtureSyncService::MODE_FULL)
            ->andReturn($fakeResult);

        $this->post(route('admin.api-football.fixtures.sync'), ['season' => '2026', 'mode' => 'full'])
            ->assertRedirect(route('admin.api-football.fixtures'));
    }

    public function test_admin_post_passes_refresh_mode_to_service(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $mock = $this->mock(ApiFootballFixtureSyncService::class);
        $mock->shouldReceive('syncAllCompetitions')
            ->once()
            ->with(2026, ApiFootballFixtureSyncService::MODE_REFRESH)
            ->andReturn(['season' => 2026, 'mode' => 'refresh', 'fixtures_created' => 0, 'fixtures_updated' => 2, 'results' => []]);

        $this->post(route('admin.api-football.fixtures.sync'), ['season' => '2026', 'mode' => 'refresh'])
            ->assertRedirect();
    }

    // -------------------------------------------------------------------------
    // Fake response helpers
    // -------------------------------------------------------------------------

    /**
     * Build a single-fixture API response, with optional dot-path overrides.
     * e.g. ['fixture.status.short' => 'FT', 'score.fulltime.home' => 2]
     */
    private function fixtureResponse(array $overrides = []): array
    {
        $item = $this->defaultFixtureItem();

        foreach ($overrides as $path => $value) {
            $keys = explode('.', $path);
            $node = &$item;
            foreach ($keys as $i => $key) {
                if ($i === count($keys) - 1) {
                    $node[$key] = $value;
                } else {
                    $node = &$node[$key];
                }
            }
            unset($node);
        }

        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [$item],
        ];
    }

    private function defaultFixtureItem(): array
    {
        return [
            'fixture' => [
                'id'        => 9001,
                'referee'   => 'Mario Rossi',
                'timezone'  => 'UTC',
                'date'      => '2026-08-22T20:45:00+00:00',
                'timestamp' => 1756050300,
                'status'    => ['long' => 'Not Started', 'short' => 'NS', 'elapsed' => null],
                'venue'     => ['id' => 907, 'name' => 'San Siro', 'city' => 'Milano'],
            ],
            'league' => [
                'id'     => 135,
                'name'   => 'Serie A',
                'season' => 2026,
                'round'  => 'Regular Season - 1',
            ],
            'teams' => [
                'home' => ['id' => 505, 'name' => 'Internazionale', 'winner' => null],
                'away' => ['id' => 489, 'name' => 'AC Milan',       'winner' => null],
            ],
            'goals' => ['home' => null, 'away' => null],
            'score' => [
                'halftime'  => ['home' => null, 'away' => null],
                'fulltime'  => ['home' => null, 'away' => null],
                'extratime' => ['home' => null, 'away' => null],
                'penalty'   => ['home' => null, 'away' => null],
            ],
        ];
    }
}
