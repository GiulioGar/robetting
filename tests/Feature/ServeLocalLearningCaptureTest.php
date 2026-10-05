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
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * P27G — `robetting:serve` startup automatically runs
 * `robetting:capture-official-results` then
 * `robetting:capture-official-predictions --within-hours=72` (P27B/P27C),
 * exactly once per startup, never blocking server start on failure.
 *
 * Mocks the capture commands' own injected services (same pattern as
 * ServeLocalPendingEventsTest) rather than the commands themselves, since
 * ServeLocal invokes them via $this->callSilently() — the container still
 * resolves CaptureOfficialResults/CaptureOfficialPredictions normally, and
 * they receive the mocked service.
 */
class ServeLocalLearningCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    // 1-3. startup invokes both capture commands, exactly once each, with within-hours=72
    public function test_startup_invokes_both_capture_commands_once_with_72h_window(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();

        $this->mock(OfficialPredictionResultCaptureService::class, function ($mock) {
            $mock->shouldReceive('matchIdsWithPredictions')->once()->andReturn([]);
        });

        $this->mock(OfficialPredictionCaptureService::class, function ($mock) {
            $mock->shouldReceive('findEligibleMatches')
                ->once()
                ->with(Mockery::any(), 72)
                ->andReturn(new EloquentCollection());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[learning] official results capture completed')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();
    }

    // 4. output stays a one-line synthetic status, no per-row payload leaks into ServeLocal's own output
    //
    // Uses the real Artisan::call() facade (not the $this->artisan() testing
    // helper) so the assertion is made against what actually prints in a real
    // run. $this->artisan()'s PendingCommand rebinds OutputStyle to a shared
    // test-buffer mock whenever any expectsOutput*/doesntExpectOutputToContain
    // assertion is used elsewhere in the suite, which — as an artifact of that
    // test harness only — causes nested callSilently() calls to ALSO write
    // into the shared buffer (Illuminate\Console\Command::run() resolves
    // OutputStyle via the container when the given $output isn't already an
    // OutputStyle, and the container override does not honor NullOutput).
    // That never happens outside tests, where callSilently() always suppresses
    // nested output as the Laravel API guarantees — this test avoids the
    // harness quirk to assert that real-world behavior directly.
    public function test_startup_output_does_not_leak_capture_command_payload(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();

        $this->mock(OfficialPredictionResultCaptureService::class, function ($mock) {
            $mock->shouldReceive('matchIdsWithPredictions')->once()->andReturn([123]);
            $mock->shouldReceive('processMatch')->once()->andReturn(['outcome' => 'WAITING_RESULT', 'predictions_affected' => 1]);
        });

        $this->mock(OfficialPredictionCaptureService::class, function ($mock) {
            $mock->shouldReceive('findEligibleMatches')->once()->andReturn(new EloquentCollection());
        });

        \Illuminate\Support\Facades\Artisan::call('robetting:serve', ['--once' => true, '--skip-server' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('[learning] official results capture completed', $output);
        $this->assertStringNotContainsString('WAITING_RESULT', $output);
    }

    // 5. a failure in the results capture does not prevent the predictions capture
    //    or the rest of the startup/serve flow from completing.
    public function test_results_capture_failure_does_not_block_predictions_capture_or_startup(): void
    {
        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();

        $this->mock(OfficialPredictionResultCaptureService::class, function ($mock) {
            $mock->shouldReceive('matchIdsWithPredictions')->once()->andThrow(new \RuntimeException('DB exploded'));
        });

        $this->mock(OfficialPredictionCaptureService::class, function ($mock) {
            $mock->shouldReceive('findEligibleMatches')->once()->with(Mockery::any(), 72)->andReturn(new EloquentCollection());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[learning] official results capture failed')
            ->expectsOutputToContain('[learning] official predictions capture completed')
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

    /** Mocks every pre-existing startup/refresh service to no-op quietly, isolating the P27G additions. */
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
}
