<?php

namespace Tests\Feature;

use App\Console\Commands\PredictionSnapshotsStatus;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use App\Services\Prediction\OfficialPredictionCaptureService;
use App\Services\Prediction\OfficialPredictionResultCaptureService;
use App\Services\Prediction\PublicPredictionRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `robetting:serve` startup: a FAILED result/calendar sync must prevent the
 * (append-only) official predictions capture and the latent refresh, while a
 * sync that is legitimately skipped (data already fresh) must not. The startup
 * itself (and so the portal) always completes. All API services are mocked.
 */
class ServeLocalSyncFailureTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        // Current latent snapshot (empty DB → nothing to refresh) so the latent
        // step never interferes; Process::fake guards against any real run.
        $this->tempDir = sys_get_temp_dir() . '/serve_sync_test_' . uniqid();
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at' => now()->toIso8601String(), 'last_match_included_at' => '2026-01-01T00:00:00Z',
            'match_count' => 0, 'teams' => [],
        ]));
        PredictionSnapshotsStatus::setSnapshotsDir($this->tempDir);
        Process::fake();

        $this->mockQuietSyncServices();
        $this->mock(PublicPredictionRefreshService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andReturn([
                'eligible' => 0, 'created' => 0, 'updated' => 0, 'skipped_today' => 0, 'unavailable' => 0, 'errors' => 0,
            ]);
        });
        $this->mock(OfficialPredictionResultCaptureService::class, function ($mock) {
            $mock->shouldReceive('matchIdsWithPredictions')->andReturn([]);
        });
    }

    protected function tearDown(): void
    {
        PredictionSnapshotsStatus::resetSnapshotsDir();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    // 1. catch-up reports failed API batches → predictions capture + latent refresh skipped
    public function test_catch_up_with_failed_batches_skips_predictions_capture(): void
    {
        $this->seedFreshCalendar();
        $this->catchUp(fn () => $this->catchUpResult(['failed_batches' => 2]));
        $this->expectPredictionsCapture(0);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('catch-up: 2 API batch(es) FAILED')
            ->expectsOutputToContain('official predictions capture SKIPPED')
            ->doesntExpectOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();

        Process::assertNothingRan();
    }

    // 2. catch-up throws → startup still completes, capture skipped
    public function test_catch_up_exception_skips_capture_but_startup_completes(): void
    {
        $this->seedFreshCalendar();
        $this->catchUp(fn () => throw new \RuntimeException('API down'));
        $this->expectPredictionsCapture(0);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('catch-up failed: API down')
            ->expectsOutputToContain('official predictions capture SKIPPED')
            ->assertSuccessful();
    }

    // 3. stale calendar whose sync FAILED for a competition → capture skipped
    public function test_failed_calendar_sync_skips_predictions_capture(): void
    {
        $this->catchUp(fn () => $this->catchUpResult());
        $this->fixtureSync([['status' => 'ok'], ['status' => 'failed', 'message' => 'x']]);
        $this->expectPredictionsCapture(0);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('calendar refresh: 1 competition(s) FAILED')
            ->expectsOutputToContain('official predictions capture SKIPPED')
            ->assertSuccessful();
    }

    // 4. calendar sync legitimately 'skipped' + clean catch-up → capture proceeds
    public function test_skipped_calendar_sync_does_not_block_capture(): void
    {
        $this->catchUp(fn () => $this->catchUpResult());
        $this->fixtureSync([['status' => 'skipped'], ['status' => 'ok']]);
        $this->expectPredictionsCapture(1);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->doesntExpectOutputToContain('SKIPPED')
            ->assertSuccessful();
    }

    // 5. fresh calendar (sync not run) + clean catch-up → capture proceeds
    public function test_fresh_calendar_does_not_block_capture(): void
    {
        $this->seedFreshCalendar();
        $this->catchUp(fn () => $this->catchUpResult());
        $this->expectPredictionsCapture(1);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('Calendar fresh')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function catchUpResult(array $extra = []): array
    {
        return $extra + ['status' => 'ok', 'sync_type' => 'catch_up', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null];
    }

    private function catchUp(callable $fn): void
    {
        $this->mock(ApiFootballResultRefreshService::class, function (MockInterface $mock) use ($fn) {
            $mock->shouldReceive('catchUp')->andReturnUsing($fn);
            $mock->shouldReceive('refresh')->andReturn($this->catchUpResult(['sync_type' => 'result_refresh']));
        });
    }

    private function fixtureSync(array $results): void
    {
        $this->mock(ApiFootballFixtureSyncService::class, function (MockInterface $mock) use ($results) {
            $mock->shouldReceive('syncAllCompetitions')->once()->andReturn([
                'season' => 2026, 'mode' => 'refresh', 'results' => $results, 'fixtures_created' => 0, 'fixtures_updated' => 0,
            ]);
        });
    }

    private function expectPredictionsCapture(int $times): void
    {
        $this->mock(OfficialPredictionCaptureService::class, function ($mock) use ($times) {
            $mock->shouldReceive('findEligibleMatches')->times($times)->andReturn(new EloquentCollection());
        });
    }

    private function seedFreshCalendar(): void
    {
        $ds = DataSource::where('slug', 'api-football')->firstOrFail();
        DataSyncRun::create([
            'data_source_id' => $ds->id, 'sync_type' => 'fixture_sync', 'competition_id' => null,
            'season_id' => null, 'mode' => null, 'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30), 'status' => 'ok', 'created_count' => 0,
            'updated_count' => 0, 'unchanged_count' => 0, 'skipped_count' => 0, 'warnings_count' => 0,
            'api_calls' => 0, 'daily_remaining' => null, 'details' => null,
        ]);
    }

    private function mockQuietSyncServices(): void
    {
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
}
