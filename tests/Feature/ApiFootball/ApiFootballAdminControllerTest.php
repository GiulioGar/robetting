<?php

namespace Tests\Feature\ApiFootball;

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
}
