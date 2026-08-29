<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballSyncMonitorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private CompetitionExternalId $cei;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds    = DataSource::where('slug', 'api-football')->firstOrFail();
        $country     = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

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

        $this->homeTeam = Team::create(['name' => 'Internazionale', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create([
            'team_id'        => $this->homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '505',
            'external_name'  => 'Internazionale',
        ]);

        $this->awayTeam = Team::create(['name' => 'AC Milan', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create([
            'team_id'        => $this->awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '489',
            'external_name'  => 'AC Milan',
        ]);
    }

    // -------------------------------------------------------------------------
    // Dashboard makes zero API calls
    // -------------------------------------------------------------------------

    public function test_dashboard_get_makes_zero_api_calls(): void
    {
        $this->app['env'] = 'local';
        Http::fake();

        $this->get(route('admin.api-football.dashboard'))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_dashboard_returns_404_outside_local(): void
    {
        $this->assertFalse(app()->isLocal());

        $this->get(route('admin.api-football.dashboard'))
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // DataSyncRun recorded after team sync
    // -------------------------------------------------------------------------

    public function test_sync_run_created_after_team_sync(): void
    {
        Http::fake(['v3.football.api-sports.io/teams*' => Http::response(
            $this->teamsResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7490', 'X-RateLimit-Remaining' => '298'],
        )]);

        $this->assertSame(0, DataSyncRun::count());

        app(ApiFootballTeamSyncService::class)->syncAllCompetitions(2026);

        $this->assertSame(1, DataSyncRun::count());

        $run = DataSyncRun::first();
        $this->assertSame($this->ds->id,          $run->data_source_id);
        $this->assertSame('team_sync',            $run->sync_type);
        $this->assertSame($this->competition->id, $run->competition_id);
        $this->assertNull($run->mode);
        $this->assertSame('ok',                   $run->status);
        // Both teams already exist in setUp (needed by fixture sync tests), so
        // they're updated (dirty code/country_id), not created — total = 2.
        $this->assertSame(2, $run->created_count + $run->updated_count + $run->unchanged_count);
        $this->assertSame(0,                      $run->warnings_count);
        $this->assertSame(1,                      $run->api_calls);
        $this->assertSame(7490,                   $run->daily_remaining);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_sync_run_status_skipped_when_no_season_external_id(): void
    {
        // Remove the SeasonExternalId so syncCompetition returns 'skipped'
        SeasonExternalId::where('competition_id', $this->competition->id)->delete();

        Http::fake();

        app(ApiFootballTeamSyncService::class)->syncAllCompetitions(2026);

        $run = DataSyncRun::first();
        $this->assertSame('skipped', $run->status);
        $this->assertSame(0,         $run->api_calls);
    }

    // -------------------------------------------------------------------------
    // DataSyncRun recorded after fixture sync
    // -------------------------------------------------------------------------

    public function test_sync_run_created_after_fixture_full(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7480', 'X-RateLimit-Remaining' => '295'],
        )]);

        app(ApiFootballFixtureSyncService::class)->syncAllCompetitions(2026, ApiFootballFixtureSyncService::MODE_FULL);

        $this->assertSame(1, DataSyncRun::count());

        $run = DataSyncRun::first();
        $this->assertSame('fixture_sync',             $run->sync_type);
        $this->assertSame('full',                     $run->mode);
        $this->assertSame($this->competition->id,     $run->competition_id);
        $this->assertSame('ok',                       $run->status);
        $this->assertSame(1,                          $run->created_count);
        $this->assertSame(0,                          $run->skipped_count);
        $this->assertSame(1,                          $run->api_calls);
        $this->assertSame(7480,                       $run->daily_remaining);
    }

    public function test_sync_run_created_after_fixture_refresh(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(),
            200,
        )]);

        app(ApiFootballFixtureSyncService::class)->syncAllCompetitions(2026, ApiFootballFixtureSyncService::MODE_REFRESH);

        $run = DataSyncRun::first();
        $this->assertSame('fixture_sync', $run->sync_type);
        $this->assertSame('refresh',      $run->mode);
        $this->assertSame('ok',           $run->status);
    }

    // -------------------------------------------------------------------------
    // Dashboard counts
    // -------------------------------------------------------------------------

    public function test_dashboard_counts_teams_and_matches(): void
    {
        $this->app['env'] = 'local';

        // Register teams in season_team (source of truth for team counts)
        DB::table('season_team')->insert([
            ['season_id' => $this->season->id, 'team_id' => $this->homeTeam->id, 'created_at' => now(), 'updated_at' => now()],
            ['season_id' => $this->season->id, 'team_id' => $this->awayTeam->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Matches for match-count assertions
        FootballMatch::create(['competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->homeTeam->id, 'away_team_id' => $this->awayTeam->id, 'status' => 'finished']);
        FootballMatch::create(['competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->homeTeam->id, 'away_team_id' => $this->awayTeam->id, 'status' => 'scheduled']);
        FootballMatch::create(['competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->homeTeam->id, 'away_team_id' => $this->awayTeam->id, 'status' => 'postponed']);

        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))->assertOk()->viewData('stats')->first();

        $this->assertSame(2,  $s['total_teams']);
        $this->assertSame(2,  $s['team_external_ids']);
        $this->assertSame(0,  $s['teams_without_mapping']);
        $this->assertSame(3,  $s['total_matches']);
        $this->assertSame(1,  $s['definitive_matches']);
        $this->assertSame(2,  $s['non_definitive_matches']);
        $this->assertSame(1,  $s['postponed']);
        $this->assertSame(0,  $s['suspended']);
        $this->assertSame(0,  $s['tbd']);
    }

    public function test_dashboard_status_is_attenzione_without_syncs(): void
    {
        $this->app['env'] = 'local';
        Http::fake();

        $response = $this->get(route('admin.api-football.dashboard'))->assertOk();
        $s        = $response->viewData('stats')->first();

        $this->assertSame('attenzione', $s['status']);
        $this->assertNull($s['last_team_sync']);
        $this->assertNull($s['last_fixture_full']);
    }

    public function test_dashboard_shows_last_sync_info(): void
    {
        $this->app['env'] = 'local';

        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'team_sync',
            'competition_id'  => $this->competition->id,
            'mode'            => null,
            'started_at'      => now()->subHour(),
            'finished_at'     => now()->subHour()->addSeconds(5),
            'status'          => 'ok',
            'created_count'   => 20,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 1,
            'daily_remaining' => 7490,
        ]);
        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'fixture_sync',
            'competition_id'  => $this->competition->id,
            'mode'            => 'full',
            'started_at'      => now()->subMinutes(30),
            'finished_at'     => now()->subMinutes(30)->addSeconds(10),
            'status'          => 'ok',
            'created_count'   => 380,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 4,
            'daily_remaining' => 7486,
        ]);

        Http::fake();
        $response = $this->get(route('admin.api-football.dashboard'))->assertOk();
        $s        = $response->viewData('stats')->first();

        $this->assertNotNull($s['last_team_sync']);
        $this->assertSame(20,  $s['last_team_sync']->created_count);
        $this->assertSame(7490, $s['last_team_sync']->daily_remaining);

        $this->assertNotNull($s['last_fixture_full']);
        $this->assertSame(380, $s['last_fixture_full']->created_count);
        $this->assertSame(4,   $s['last_fixture_full']->api_calls);

        $this->assertNull($s['last_fixture_refresh']);
    }

    public function test_dashboard_status_ok_when_both_syncs_done_and_no_mapping_gaps(): void
    {
        $this->app['env'] = 'local';

        foreach (['team_sync' => null, 'fixture_sync' => 'full'] as $type => $mode) {
            DataSyncRun::create([
                'data_source_id'  => $this->ds->id,
                'sync_type'       => $type,
                'competition_id'  => $this->competition->id,
                'mode'            => $mode,
                'started_at'      => now(),
                'finished_at'     => now(),
                'status'          => 'ok',
                'created_count'   => 0,
                'updated_count'   => 0,
                'unchanged_count' => 0,
                'skipped_count'   => 0,
                'warnings_count'  => 0,
                'api_calls'       => 1,
            ]);
        }

        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->viewData('stats')
            ->first();

        // No matches and no mapping gaps — both syncs done — status is ok
        $this->assertSame('ok', $s['status']);
    }

    // -------------------------------------------------------------------------
    // Missing mappings highlighted
    // -------------------------------------------------------------------------

    public function test_missing_team_mapping_counted(): void
    {
        $this->app['env'] = 'local';

        // A third team with NO api-football external id
        $unmappedTeam = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        // Register both in season_team — source of truth for team counts
        DB::table('season_team')->insert([
            ['season_id' => $this->season->id, 'team_id' => $this->homeTeam->id,  'created_at' => now(), 'updated_at' => now()],
            ['season_id' => $this->season->id, 'team_id' => $unmappedTeam->id,    'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->viewData('stats')
            ->first();

        $this->assertSame(2,  $s['total_teams']);         // homeTeam + unmappedTeam
        $this->assertSame(1,  $s['team_external_ids']);   // only homeTeam has api-football mapping
        $this->assertSame(1,  $s['teams_without_mapping']);
        $this->assertSame('attenzione', $s['status']);
    }

    public function test_missing_match_mapping_counted(): void
    {
        $this->app['env'] = 'local';

        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'status'         => 'scheduled',
        ]);

        // No MatchExternalId created — match has no api-football mapping
        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->viewData('stats')
            ->first();

        $this->assertSame(1, $s['total_matches']);
        $this->assertSame(0, $s['match_external_ids']);
        $this->assertSame(1, $s['matches_without_mapping']);
        $this->assertSame('attenzione', $s['status']);

        // Now add the mapping
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9001',
            'external_name'  => null,
        ]);

        $s2 = $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->viewData('stats')
            ->first();

        $this->assertSame(1, $s2['match_external_ids']);
        $this->assertSame(0, $s2['matches_without_mapping']);
    }

    public function test_dashboard_status_errore_when_last_sync_failed(): void
    {
        $this->app['env'] = 'local';

        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'fixture_sync',
            'competition_id'  => $this->competition->id,
            'mode'            => 'full',
            'started_at'      => now(),
            'finished_at'     => now(),
            'status'          => 'failed',
            'created_count'   => 0,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 0,
        ]);

        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->viewData('stats')
            ->first();

        $this->assertSame('errore', $s['status']);
    }

    // -------------------------------------------------------------------------
    // season_team as source of truth (no matches needed)
    // -------------------------------------------------------------------------

    public function test_monitor_shows_teams_with_zero_matches(): void
    {
        $this->app['env'] = 'local';

        // Register teams via season_team — no matches in DB
        DB::table('season_team')->insert([
            ['season_id' => $this->season->id, 'team_id' => $this->homeTeam->id, 'created_at' => now(), 'updated_at' => now()],
            ['season_id' => $this->season->id, 'team_id' => $this->awayTeam->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake();
        $s = $this->get(route('admin.api-football.dashboard'))->assertOk()->viewData('stats')->first();

        $this->assertSame(2, $s['total_teams']);
        $this->assertSame(2, $s['team_external_ids']);
        $this->assertSame(0, $s['teams_without_mapping']);
        $this->assertSame(0, $s['total_matches']); // no fixtures imported yet
    }

    // -------------------------------------------------------------------------
    // Fake response helpers
    // -------------------------------------------------------------------------

    private function teamsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team' => ['id' => 505, 'name' => 'Internazionale', 'code' => 'INT',
                               'country' => 'Italy', 'founded' => 1908, 'national' => false],
                    'venue' => [],
                ],
                [
                    'team' => ['id' => 489, 'name' => 'AC Milan', 'code' => 'MIL',
                               'country' => 'Italy', 'founded' => 1899, 'national' => false],
                    'venue' => [],
                ],
            ],
        ];
    }

    private function fixtureResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => [
                    'id'       => 9001,
                    'timezone' => 'UTC',
                    'date'     => '2026-08-22T20:45:00+00:00',
                    'status'   => ['long' => 'Not Started', 'short' => 'NS', 'elapsed' => null],
                    'venue'    => ['id' => 907, 'name' => 'San Siro', 'city' => 'Milano'],
                ],
                'league' => ['id' => 135, 'name' => 'Serie A', 'season' => 2026, 'round' => 'Regular Season - 1'],
                'teams'  => [
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
            ]],
        ];
    }
}
