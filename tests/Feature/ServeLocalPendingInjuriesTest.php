<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Availability V1 Step 5D — verifies ServeLocal integrates
 * ApiFootballInjurySyncService::syncPending(3) into both the startup phase
 * and the --once refresh loop, with a fixed 72h window, no throttle
 * duplicated here, and fail-safe behaviour on error.
 */
class ServeLocalPendingInjuriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    // -------------------------------------------------------------------------
    // 1. startup chiama syncPending(3) per injuries (almeno una volta)
    // -------------------------------------------------------------------------

    public function test_startup_calls_syncPending_for_injuries_with_72h_window(): void
    {
        $this->seedFreshCalendar();

        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $mock->shouldReceive('catchUp')->once()->andReturn($this->emptyCatchUpResult());
            $mock->shouldReceive('refresh')->once()->andReturn($this->emptyRefreshResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn($this->emptyStatsResult());
            $mock->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveStatsResult());
        });
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->atLeast()->once()->andReturn($this->emptyLineupsResult());
        });
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->atLeast()->once()->andReturn($this->emptyEventsResult());
            $mock->shouldReceive('syncLive')->once()->andReturn($this->emptyEventsResult());
        });
        $this->mock(ApiFootballInjurySyncService::class, function ($mock) {
            // A5.5: ServeLocal now also passes core_leagues slugs to scope injuries to CORE only.
            $mock->shouldReceive('syncPending')->with(3, \Mockery::any())->atLeast()->once()->andReturn($this->emptyInjuriesResult());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // 2. --once chiama syncPending(3) per injuries 2 volte: startup + loop
    // -------------------------------------------------------------------------

    public function test_once_mode_calls_syncPending_for_injuries_twice(): void
    {
        $this->seedFreshCalendar();

        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $mock->shouldReceive('catchUp')->once()->andReturn($this->emptyCatchUpResult());
            $mock->shouldReceive('refresh')->once()->andReturn($this->emptyRefreshResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn($this->emptyStatsResult());
            $mock->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveStatsResult());
        });
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->twice()->andReturn($this->emptyLineupsResult());
        });
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->twice()->andReturn($this->emptyEventsResult());
            $mock->shouldReceive('syncLive')->once()->andReturn($this->emptyEventsResult());
        });
        $this->mock(ApiFootballInjurySyncService::class, function ($mock) {
            // once in runStartup(), once in the --once refresh cycle.
            // A5.5: ServeLocal now also passes core_leagues slugs to scope injuries to CORE only.
            $mock->shouldReceive('syncPending')->with(3, \Mockery::any())->twice()->andReturn($this->emptyInjuriesResult());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // 3. Fixture reale entro 72h senza throttle pregresso → 1 API call reale
    // -------------------------------------------------------------------------

    public function test_real_fixture_within_72h_triggers_one_injuries_api_call(): void
    {
        $this->seedFreshCalendar();
        $match = $this->makeUpcomingMatch('7001', now()->addHours(20));

        Http::fake([
            '*injuries*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200),
        ]);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        Http::assertSentCount(1);
        $match->refresh();
        $this->assertNotNull($match->injuries_fetched_at);
    }

    // -------------------------------------------------------------------------
    // 4. Fixture fuori dalla finestra 72h → zero API call injuries
    // -------------------------------------------------------------------------

    public function test_fixture_beyond_72h_window_makes_no_injuries_api_call(): void
    {
        $this->seedFreshCalendar();
        $this->makeUpcomingMatch('7002', now()->addDays(5));

        Http::fake();

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 5. Throttle già soddisfatto (last_attempt recente) → skip, zero call
    // -------------------------------------------------------------------------

    public function test_fixture_already_recently_attempted_is_throttled(): void
    {
        $this->seedFreshCalendar();
        $match = $this->makeUpcomingMatch('7003', now()->addHours(20));
        $match->update(['injuries_last_attempt_at' => now()->subMinutes(30)]);

        Http::fake();

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 6. Fail-safe: eccezione nel sync injuries non blocca robetting:serve
    // -------------------------------------------------------------------------

    public function test_injuries_sync_exception_does_not_stop_serve_local(): void
    {
        $this->seedFreshCalendar();

        $this->mock(ApiFootballInjurySyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andThrow(new \RuntimeException('boom'));
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedFreshCalendar(): void
    {
        $ds = DataSource::where('slug', 'api-football')->firstOrFail();
        DataSyncRun::create([
            'data_source_id'  => $ds->id,
            'sync_type'       => 'fixture_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => null,
            'started_at'      => now()->subMinutes(30),
            'finished_at'     => now()->subMinutes(30),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 0,
            'daily_remaining' => null,
            'details'         => null,
        ]);
    }

    private function makeUpcomingMatch(string $extId, \Illuminate\Support\Carbon $kickoffAt): FootballMatch
    {
        $ds       = DataSource::where('slug', 'api-football')->firstOrFail();
        $country  = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp     = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $season   = Season::create(['competition_id' => $comp->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $homeTeam = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $awayTeam = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);

        TeamExternalId::create(['team_id' => $homeTeam->id, 'data_source_id' => $ds->id, 'external_id' => '9001', 'external_name' => 'Home FC']);
        TeamExternalId::create(['team_id' => $awayTeam->id, 'data_source_id' => $ds->id, 'external_id' => '9002', 'external_name' => 'Away FC']);

        $match = FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $homeTeam->id,
            'away_team_id'   => $awayTeam->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => 'scheduled',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $ds->id,
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function emptyCatchUpResult(): array
    {
        return ['status' => 'ok', 'sync_type' => 'catch_up', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null];
    }

    private function emptyRefreshResult(): array
    {
        return ['status' => 'ok', 'sync_type' => 'result_refresh', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null];
    }

    private function emptyStatsResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0];
    }

    private function emptyEventsResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
    }

    private function emptyLiveStatsResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
    }

    private function emptyLineupsResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'empty' => 0, 'api_calls' => 0];
    }

    private function emptyInjuriesResult(): array
    {
        return [
            'status' => 'ok', 'candidates' => 0, 'synced' => 0, 'empty' => 0, 'skipped_throttle' => 0,
            'failed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0,
            'warnings' => 0, 'api_calls' => 0, 'daily_remaining' => null,
        ];
    }
}
