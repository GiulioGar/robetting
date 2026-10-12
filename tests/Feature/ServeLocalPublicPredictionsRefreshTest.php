<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use App\Services\Prediction\OfficialPredictionCaptureService;
use App\Services\Prediction\OfficialPredictionResultCaptureService;
use App\Services\Prediction\PublicPredictionRefreshService;
use Carbon\CarbonImmutable;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1A.5 — `robetting:serve` startup automatically runs
 * `robetting:refresh-public-predictions --days=30`, exactly once per
 * startup, never in the periodic refresh loop, never blocking server start
 * on failure. Same mocking pattern as ServeLocalLearningCaptureTest (mock
 * the command's own injected service, not the command itself, since
 * ServeLocal invokes it via $this->callSilently()).
 */
class ServeLocalPublicPredictionsRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    // 7. ServeLocal richiama il refresh public allo startup, con --days=30
    public function test_startup_invokes_public_predictions_refresh_once_with_30_days(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();
        $this->mockQuietLearningServices();

        $this->mock(PublicPredictionRefreshService::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->withArgs(fn ($now, $days) => $now instanceof CarbonImmutable && $days === 30)
                ->andReturn(['eligible' => 0, 'created' => 0, 'updated' => 0, 'skipped_today' => 0, 'unavailable' => 0, 'errors' => 0]);
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[public] public predictions refresh completed')
            ->assertSuccessful();
    }

    // 8. errore nel refresh public non blocca ServeLocal
    public function test_refresh_failure_does_not_block_startup(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();
        $this->mockQuietLearningServices();

        $this->mock(PublicPredictionRefreshService::class, function ($mock) {
            $mock->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('DB exploded'));
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[public] public predictions refresh failed')
            ->assertSuccessful();
    }

    // 9. il refresh NON viene richiamato una seconda volta nel ciclo --once
    //    (che ripete gli altri task periodici subito dopo lo startup) — prova
    //    diretta che non è nel loop periodico: ->once() sopra fallirebbe già
    //    se venisse chiamato due volte, questo test lo rende esplicito.
    public function test_refresh_is_not_invoked_a_second_time_in_the_once_cycle(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();
        $this->mockQuietLearningServices();

        $this->mock(PublicPredictionRefreshService::class, function ($mock) {
            // Mockery's default expectation count (no ->times()) is "at least
            // once", so pin it down explicitly to exactly one call: if
            // ServeLocal's --once cycle (or the periodic loop) called this a
            // second time, this expectation would fail the test.
            $mock->shouldReceive('refresh')->times(1)->andReturn(['eligible' => 0, 'created' => 0, 'updated' => 0, 'skipped_today' => 0, 'unavailable' => 0, 'errors' => 0]);
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers (mirrors ServeLocalLearningCaptureTest)
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

    private function mockQuietSyncServices(): void
    {
        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $mock->shouldReceive('catchUp')->andReturn(['status' => 'ok', 'sync_type' => 'catch_up', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null]);
            $mock->shouldReceive('refresh')->andReturn(['status' => 'ok', 'sync_type' => 'result_refresh', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null]);
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0]);
            $mock->shouldReceive('syncLive')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
        });
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'empty' => 0, 'api_calls' => 0]);
        });
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
            $mock->shouldReceive('syncLive')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
        });
    }

    private function mockQuietLearningServices(): void
    {
        $this->mock(OfficialPredictionResultCaptureService::class, function ($mock) {
            $mock->shouldReceive('matchIdsWithPredictions')->andReturn([]);
        });
        $this->mock(OfficialPredictionCaptureService::class, function ($mock) {
            $mock->shouldReceive('findEligibleMatches')->andReturn(new EloquentCollection());
        });
    }
}
