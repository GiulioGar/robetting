<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballMatchUpdateService;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFootballAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
        // Disable CSRF for all tests in this class (admin-only, no CSRF needed in tests)
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    // -------------------------------------------------------------------------
    // 404 outside local environment
    // -------------------------------------------------------------------------

    public function test_admin_get_returns_404_in_non_local_env(): void
    {
        // Default test env is 'testing', not 'local'
        $this->assertFalse(app()->isLocal());

        $this->get(route('admin.api-football.teams'))
            ->assertNotFound();
    }

    public function test_admin_post_returns_404_in_non_local_env(): void
    {
        // CSRF is disabled in setUp; env is 'testing' (not local)
        $this->assertFalse(app()->isLocal());

        $this->post(route('admin.api-football.teams.sync'), ['season' => '2026'])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // POST admin delegates to ApiFootballTeamSyncService (no duplicate logic)
    // -------------------------------------------------------------------------

    public function test_admin_sync_post_delegates_to_team_sync_service(): void
    {
        $this->app['env'] = 'local';

        $fakeResult = [
            'season'        => 2026,
            'teams_created' => 3,
            'teams_updated' => 1,
            'results'       => [],
        ];

        $mock = $this->mock(ApiFootballTeamSyncService::class);
        $mock->shouldReceive('syncAllCompetitions')
            ->once()
            ->with(2026)
            ->andReturn($fakeResult);

        $this->post(route('admin.api-football.teams.sync'), ['season' => '2026'])
            ->assertRedirect(route('admin.api-football.teams'));
    }

    public function test_admin_sync_passes_season_param_to_service(): void
    {
        $this->app['env'] = 'local';

        $mock = $this->mock(ApiFootballTeamSyncService::class);
        $mock->shouldReceive('syncAllCompetitions')
            ->once()
            ->with(2025)
            ->andReturn(['season' => 2025, 'teams_created' => 0, 'teams_updated' => 0, 'results' => []]);

        $this->post(route('admin.api-football.teams.sync'), ['season' => '2025'])
            ->assertRedirect();
    }

    public function test_admin_get_renders_view_in_local_env(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('admin.api-football.teams'))
            ->assertOk()
            ->assertViewIs('admin.api-football.teams');
    }

    public function test_report_is_flashed_to_session_after_sync(): void
    {
        $this->app['env'] = 'local';

        $fakeResult = [
            'season'        => 2026,
            'teams_created' => 2,
            'teams_updated' => 0,
            'results'       => [],
        ];

        $mock = $this->mock(ApiFootballTeamSyncService::class);
        $mock->shouldReceive('syncAllCompetitions')->andReturn($fakeResult);

        $this->post(route('admin.api-football.teams.sync'), ['season' => '2026'])
            ->assertSessionHas('team_sync_report', $fakeResult);
    }

    // -------------------------------------------------------------------------
    // Dashboard contains "Aggiorna partita" section
    // -------------------------------------------------------------------------

    public function test_dashboard_contains_aggiorna_partita_section_in_local_env(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->assertSee('Aggiorna partita')
            ->assertSee('Aggiorna tutti i dati');
    }

    public function test_dashboard_match_select_shows_recent_matches(): void
    {
        $this->app['env'] = 'local';

        $match = $this->makeRecentMatch();

        $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->assertSee($match->homeTeam->name)
            ->assertSee($match->awayTeam->name);
    }

    // -------------------------------------------------------------------------
    // POST match-update delegates to ApiFootballMatchUpdateService
    // -------------------------------------------------------------------------

    public function test_match_update_post_delegates_to_match_update_service(): void
    {
        $this->app['env'] = 'local';

        $match     = $this->makeRecentMatch();
        $fakeResult = $this->fakeUpdateResult($match->id);

        $this->mock(ApiFootballMatchUpdateService::class, fn($m) =>
            $m->shouldReceive('update')
              ->once()
              ->with(\Mockery::on(fn($arg) => $arg->id === $match->id))
              ->andReturn($fakeResult)
        );

        $this->post(route('admin.api-football.match-update'), ['match_id' => $match->id])
            ->assertRedirect(route('admin.api-football.dashboard'));
    }

    public function test_match_update_post_flashes_report_to_session(): void
    {
        $this->app['env'] = 'local';

        $match      = $this->makeRecentMatch();
        $fakeResult = $this->fakeUpdateResult($match->id);

        $this->mock(ApiFootballMatchUpdateService::class, fn($m) =>
            $m->shouldReceive('update')->andReturn($fakeResult)
        );

        $this->post(route('admin.api-football.match-update'), ['match_id' => $match->id])
            ->assertSessionHas('match_update_report');
    }

    public function test_match_update_report_visible_in_dashboard_after_submit(): void
    {
        $this->app['env'] = 'local';

        $match      = $this->makeRecentMatch();
        $fakeResult = $this->fakeUpdateResult($match->id);

        $this->mock(ApiFootballMatchUpdateService::class, fn($m) =>
            $m->shouldReceive('update')->andReturn($fakeResult)
        );

        // Follow redirect to dashboard and verify report is rendered
        $this->post(route('admin.api-football.match-update'), ['match_id' => $match->id])
            ->assertRedirect();

        $this->get(route('admin.api-football.dashboard'))
            ->assertSee('Match #' . $match->id);
    }

    // -------------------------------------------------------------------------
    // Invalid match_id → readable error
    // -------------------------------------------------------------------------

    public function test_match_update_post_with_missing_match_id_flashes_error(): void
    {
        $this->app['env'] = 'local';

        $this->mock(ApiFootballMatchUpdateService::class,
            fn($m) => $m->shouldNotReceive('update'));

        $this->post(route('admin.api-football.match-update'), ['match_id' => 99999])
            ->assertRedirect(route('admin.api-football.dashboard'))
            ->assertSessionHas('match_update_error');
    }

    public function test_match_update_error_message_visible_in_dashboard(): void
    {
        $this->app['env'] = 'local';

        $this->mock(ApiFootballMatchUpdateService::class,
            fn($m) => $m->shouldNotReceive('update'));

        $this->post(route('admin.api-football.match-update'), ['match_id' => 99999]);

        $this->get(route('admin.api-football.dashboard'))
            ->assertSee('non trovato');
    }

    // -------------------------------------------------------------------------
    // Match-update POST returns 404 outside local env
    // -------------------------------------------------------------------------

    public function test_match_update_post_returns_404_in_non_local_env(): void
    {
        $this->assertFalse(app()->isLocal());

        $this->post(route('admin.api-football.match-update'), ['match_id' => 1])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRecentMatch(): FootballMatch
    {
        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp    = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season  = Season::create([
            'competition_id' => $comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $home    = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        return FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
            'definitive_at'  => now()->subMinutes(30),
        ]);
    }

    private function fakeUpdateResult(int $matchId): array
    {
        return [
            'status'     => 'ok',
            'api_calls'  => 4,
            'match_id'   => $matchId,
            'result'     => ['outcome' => 'updated', 'api_calls' => 1],
            'lineup'     => ['outcome' => 'synced',  'api_calls' => 1],
            'events'     => ['outcome' => 'synced',  'api_calls' => 1, 'events_count' => 5],
            'statistics' => ['outcome' => 'synced',  'api_calls' => 1],
            'warnings'   => [],
        ];
    }
}
