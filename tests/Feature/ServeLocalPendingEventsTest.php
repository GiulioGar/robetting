<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that ServeLocal integrates ApiFootballMatchEventSyncService::syncPending()
 * into both the startup phase and the --once refresh loop.
 *
 * Tests 1-2 use mocks to count service invocations precisely.
 * Tests 3-5 use real services + Http::fake() to verify observable side-effects.
 */
class ServeLocalPendingEventsTest extends TestCase
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
    // 1. startup chiama syncPending per events (almeno una volta)
    // -------------------------------------------------------------------------

    public function test_startup_calls_syncPending_for_events(): void
    {
        $this->seedFreshCalendar();

        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $mock->shouldReceive('catchUp')->once()->andReturn($this->emptyCatchUpResult());
            $mock->shouldReceive('refresh')->once()->andReturn($this->emptyRefreshResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn($this->emptyStatsResult());
        });
        $eventsService = $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->atLeast()->once()->andReturn($this->emptyEventsResult());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // 2. --once chiama syncPending per events 2 volte: startup + loop
    // -------------------------------------------------------------------------

    public function test_once_mode_calls_syncPending_for_events_twice(): void
    {
        $this->seedFreshCalendar();

        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $mock->shouldReceive('catchUp')->once()->andReturn($this->emptyCatchUpResult());
            $mock->shouldReceive('refresh')->once()->andReturn($this->emptyRefreshResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn($this->emptyStatsResult());
        });
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            // Exactly 2: once in runStartup(), once in the --once refresh cycle.
            $mock->shouldReceive('syncPending')->twice()->andReturn($this->emptyEventsResult());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // 3. Zero candidati → zero API call
    // -------------------------------------------------------------------------

    public function test_zero_event_candidates_makes_no_api_call(): void
    {
        $this->seedFreshCalendar();
        // No matches in DB → catch-up, refresh, stats, events all find 0 candidates.

        Http::fake();

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 4. HTTP failure su events → ServeLocal non si interrompe, match retryabile
    // -------------------------------------------------------------------------

    public function test_http_failure_on_events_does_not_stop_serve_local(): void
    {
        $this->seedFreshCalendar();
        $match = $this->makeDefinitiveMatch('9901', now()->subMinutes(11));
        // Pre-mark stats as fetched so pending-stats doesn't add unexpected API calls.
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => DataSource::where('slug', 'api-football')->value('id'),
            'fetched_at'     => now()->subMinutes(5),
        ]);

        Http::fake([
            '*fixtures/events*' => Http::response([], 500),
        ]);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        $match->refresh();
        $this->assertNull($match->events_fetched_at, 'HTTP failure non deve impostare events_fetched_at');
    }

    // -------------------------------------------------------------------------
    // 5. events_fetched_at già valorizzato → zero call al fixtures/events endpoint
    // -------------------------------------------------------------------------

    public function test_events_already_fetched_makes_no_events_api_call(): void
    {
        $this->seedFreshCalendar();
        $match = $this->makeDefinitiveMatch('9902', now()->subMinutes(11));
        $match->update(['events_fetched_at' => now()->subMinutes(5)]);

        $ds = DataSource::where('slug', 'api-football')->firstOrFail();
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $ds->id,
            'fetched_at'     => now()->subMinutes(5),
        ]);

        Http::fake();

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
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

    private function makeDefinitiveMatch(string $extId, mixed $definitiveAt): FootballMatch
    {
        $ds        = DataSource::where('slug', 'api-football')->firstOrFail();
        $country   = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp      = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $season    = Season::create(['competition_id' => $comp->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $homeTeam  = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $awayTeam  = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);

        $match = FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $homeTeam->id,
            'away_team_id'   => $awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'definitive_at'  => $definitiveAt,
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
}
