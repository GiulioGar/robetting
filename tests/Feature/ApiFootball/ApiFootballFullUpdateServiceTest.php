<?php

namespace Tests\Feature\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballFullUpdateService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies ApiFootballFullUpdateService orchestration.
 *
 * Rules under test:
 *  - Each of the 7 blocks delegates to the correct existing service method.
 *  - Calendar block skips when a recent fixture_sync DataSyncRun exists (< 36h).
 *  - Calendar block runs when no DataSyncRun exists (stale).
 *  - A Throwable in one block does not prevent subsequent blocks from running.
 *  - api_calls total is the sum of all block api_calls.
 *  - status='partial' when any block returns status='error'.
 *  - status='ok' when all blocks succeed.
 *  - ApiFootballTeamSyncService is NEVER called (not injected, not involved).
 *  - No real API calls — all services are mocked.
 */
class ApiFootballFullUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // 1. All 7 service methods called (calendar stale → syncAllCompetitions runs)
    // -------------------------------------------------------------------------

    public function test_all_service_methods_called_correctly(): void
    {
        // No DataSyncRun → calendar is stale → syncAllCompetitions will be called.

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->once()->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class, fn($m) =>
            $m->shouldReceive('syncAllCompetitions')
              ->once()
              ->with((int) date('Y'), ApiFootballFixtureSyncService::MODE_REFRESH)
              ->andReturn($this->calendarResult())
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->once()->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('result_catchup',  $result['blocks']);
        $this->assertArrayHasKey('calendar',        $result['blocks']);
        $this->assertArrayHasKey('lineups',         $result['blocks']);
        $this->assertArrayHasKey('events_live',     $result['blocks']);
        $this->assertArrayHasKey('stats_live',      $result['blocks']);
        $this->assertArrayHasKey('stats_pending',   $result['blocks']);
        $this->assertArrayHasKey('events_pending',  $result['blocks']);
    }

    // -------------------------------------------------------------------------
    // 2. Calendar skipped when last fixture_sync is recent (< 36h)
    // -------------------------------------------------------------------------

    public function test_calendar_skipped_when_fixture_sync_is_fresh(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->once()->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->once()->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('skipped_fresh', $result['blocks']['calendar']['status']);
        $this->assertSame(0, $result['blocks']['calendar']['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 3. Calendar runs when no previous fixture_sync exists (stale)
    // -------------------------------------------------------------------------

    public function test_calendar_runs_when_no_previous_sync_exists(): void
    {
        // No DataSyncRun created → calendar is stale.

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->once()->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldReceive('syncAllCompetitions')->once()->andReturn($this->calendarResult())
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->once()->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('ok', $result['blocks']['calendar']['status']);
    }

    // -------------------------------------------------------------------------
    // 4. Error in result catch-up does not block subsequent blocks
    // -------------------------------------------------------------------------

    public function test_error_in_result_catchup_does_not_block_other_blocks(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->once()->andThrow(new \RuntimeException('HTTP 503'))
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->once()->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('partial', $result['status']);
        $this->assertSame('error', $result['blocks']['result_catchup']['status']);
        // All subsequent blocks still ran:
        $this->assertNotSame('error', $result['blocks']['lineups']['status']     ?? 'ok');
        $this->assertNotSame('error', $result['blocks']['events_live']['status'] ?? 'ok');
        $this->assertNotSame('error', $result['blocks']['stats_live']['status']  ?? 'ok');
    }

    // -------------------------------------------------------------------------
    // 5. Error in lineup sync does not block events/stats
    // -------------------------------------------------------------------------

    public function test_error_in_lineup_sync_does_not_block_events_and_stats(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->once()->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->once()->andThrow(new \RuntimeException('DB error'))
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            // Both event methods must still be called despite lineup failure.
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->once()->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->once()->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('partial', $result['status']);
        $this->assertSame('error',   $result['blocks']['lineups']['status']);
        $this->assertNotSame('error', $result['blocks']['events_live']['status']    ?? 'ok');
        $this->assertNotSame('error', $result['blocks']['events_pending']['status'] ?? 'ok');
        $this->assertNotSame('error', $result['blocks']['stats_live']['status']     ?? 'ok');
        $this->assertNotSame('error', $result['blocks']['stats_pending']['status']  ?? 'ok');
    }

    // -------------------------------------------------------------------------
    // 6. status='ok' when all blocks succeed
    // -------------------------------------------------------------------------

    public function test_status_ok_when_all_blocks_succeed(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('ok', $result['status']);
    }

    // -------------------------------------------------------------------------
    // 7. api_calls is the sum across all blocks
    // -------------------------------------------------------------------------

    public function test_api_calls_summed_across_all_blocks(): void
    {
        $this->createFreshCalendarRun(); // calendar → 0 api_calls

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->andReturn(array_merge($this->catchUpResult(), ['api_calls' => 2]))
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->andReturn(array_merge($this->lineupsResult(), ['api_calls' => 3]))
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn(array_merge($this->emptyLiveResult(), ['api_calls' => 1]));
            $m->shouldReceive('syncPending')->andReturn(array_merge($this->emptyPendingResult(), ['api_calls' => 1]));
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn(array_merge($this->emptyLiveResult(), ['api_calls' => 1]));
            $m->shouldReceive('syncPending')->andReturn(array_merge($this->emptyStatsPendingResult(), ['api_calls' => 1]));
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        // catchUp(2) + calendar(0) + lineups(3) + events_live(1) + stats_live(1) + stats_pending(1) + events_pending(1) = 9
        $this->assertSame(9, $result['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 8. ApiFootballTeamSyncService is never called
    // -------------------------------------------------------------------------

    public function test_team_sync_service_never_called(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballTeamSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->andReturn($this->catchUpResult())
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame('ok', $result['status']); // Team sync was never triggered.
    }

    // -------------------------------------------------------------------------
    // 9. daily_remaining extracted from result_catchup block
    // -------------------------------------------------------------------------

    public function test_daily_remaining_extracted_from_blocks(): void
    {
        $this->createFreshCalendarRun();

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('catchUp')->andReturn(
                array_merge($this->catchUpResult(), ['daily_remaining' => 150])
            )
        );
        $this->mock(ApiFootballFixtureSyncService::class,
            fn($m) => $m->shouldNotReceive('syncAllCompetitions')
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncPending')->andReturn($this->lineupsResult())
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyPendingResult());
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncLive')->andReturn($this->emptyLiveResult());
            $m->shouldReceive('syncPending')->andReturn($this->emptyStatsPendingResult());
        });

        $result = app(ApiFootballFullUpdateService::class)->updateAll();

        $this->assertSame(150, $result['daily_remaining']);
    }

    // -------------------------------------------------------------------------
    // Admin controller: POST sync-all delegates to ApiFootballFullUpdateService
    // -------------------------------------------------------------------------

    public function test_admin_sync_all_post_delegates_to_full_update_service(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $fakeResult = [
            'status'          => 'ok',
            'api_calls'       => 7,
            'daily_remaining' => null,
            'blocks'          => [],
        ];

        $this->mock(ApiFootballFullUpdateService::class,
            fn($m) => $m->shouldReceive('updateAll')->once()->andReturn($fakeResult)
        );

        $this->post(route('admin.api-football.sync-all'))
            ->assertRedirect(route('admin.api-football.dashboard'))
            ->assertSessionHas('full_update_report');
    }

    public function test_admin_sync_all_post_returns_404_in_non_local_env(): void
    {
        $this->assertFalse(app()->isLocal());
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->post(route('admin.api-football.sync-all'))
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createFreshCalendarRun(): void
    {
        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'fixture_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => 'refresh',
            'started_at'      => now()->subMinutes(30),
            'finished_at'     => now()->subMinutes(29),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => 5,
            'unchanged_count' => 10,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 5,
            'daily_remaining' => null,
            'details'         => null,
        ]);
    }

    private function catchUpResult(): array
    {
        return [
            'status'          => 'ok',
            'sync_type'       => 'catch_up',
            'candidates'      => 0,
            'updated'         => 0,
            'unchanged'       => 0,
            'api_calls'       => 0,
            'daily_remaining' => null,
        ];
    }

    private function calendarResult(): array
    {
        return [
            'season'           => (int) date('Y'),
            'mode'             => 'refresh',
            'results'          => [['api_calls' => 2, 'updated' => 1, 'created' => 0]],
            'fixtures_created' => 0,
            'fixtures_updated' => 1,
        ];
    }

    private function lineupsResult(): array
    {
        return [
            'status'     => 'ok',
            'candidates' => 0,
            'synced'     => 0,
            'failed'     => 0,
            'empty'      => 0,
            'api_calls'  => 0,
        ];
    }

    private function emptyLiveResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
    }

    private function emptyPendingResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
    }

    private function emptyStatsPendingResult(): array
    {
        return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0];
    }
}
