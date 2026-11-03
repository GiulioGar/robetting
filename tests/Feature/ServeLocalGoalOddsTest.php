<?php

namespace Tests\Feature;

use App\Console\Commands\PredictionSnapshotsStatus;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballGoalOddsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballOddsSyncService;
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
 * `robetting:serve` startup runs the GOAL-odds sync once (startup only), reports its failures, and a GOAL-odds
 * failure never stops the startup nor the official predictions capture. All API services are mocked.
 */
class ServeLocalGoalOddsTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key' => 'test-key', 'api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->tempDir = sys_get_temp_dir() . '/serve_goal_odds_test_' . uniqid();
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at' => now()->toIso8601String(), 'last_match_included_at' => '2026-01-01T00:00:00Z',
            'match_count' => 0, 'teams' => [],
        ]));
        PredictionSnapshotsStatus::setSnapshotsDir($this->tempDir);
        Process::fake();

        $ds = DataSource::where('slug', 'api-football')->firstOrFail();
        DataSyncRun::create([
            'data_source_id' => $ds->id, 'sync_type' => 'fixture_sync', 'competition_id' => null,
            'season_id' => null, 'mode' => null, 'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30), 'status' => 'ok', 'created_count' => 0,
            'updated_count' => 0, 'unchanged_count' => 0, 'skipped_count' => 0, 'warnings_count' => 0,
            'api_calls' => 0, 'daily_remaining' => null, 'details' => null,
        ]);

        $this->mock(ApiFootballResultRefreshService::class, function (MockInterface $m) {
            $ok = ['status' => 'ok', 'sync_type' => 'catch_up', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            $m->shouldReceive('catchUp')->andReturn($ok);
            $m->shouldReceive('refresh')->andReturn($ok);
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0]);
            $m->shouldReceive('syncLive')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
        });
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'empty' => 0, 'api_calls' => 0]);
        });
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
            $m->shouldReceive('syncLive')->andReturn(['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0]);
        });
        $this->mock(ApiFootballOddsSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->andReturn(['enabled' => true, 'competitions' => 0, 'api_calls' => 0, 'created' => 0, 'duplicates' => 0, 'skipped' => 0, 'warnings' => 0, 'errors' => 0, 'skipped_throttle' => 0]);
        });
        $this->mock(PublicPredictionRefreshService::class, function ($m) {
            $m->shouldReceive('refresh')->andReturn(['eligible' => 0, 'created' => 0, 'updated' => 0, 'skipped_today' => 0, 'unavailable' => 0, 'errors' => 0]);
        });
        $this->mock(OfficialPredictionResultCaptureService::class, function ($m) {
            $m->shouldReceive('matchIdsWithPredictions')->andReturn([]);
        });
        // The startup must still reach the official predictions capture exactly once.
        $this->mock(OfficialPredictionCaptureService::class, function ($m) {
            $m->shouldReceive('findEligibleMatches')->once()->andReturn(new EloquentCollection());
        });
    }

    protected function tearDown(): void
    {
        PredictionSnapshotsStatus::resetSnapshotsDir();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function goalSummary(array $extra = []): array
    {
        return $extra + ['enabled' => true, 'competitions' => 1, 'api_calls' => 3, 'created' => 10, 'duplicates' => 2, 'skipped' => 0, 'discarded' => 5, 'errors' => 0, 'skipped_throttle' => 0];
    }

    public function test_startup_runs_goal_odds_sync_once_and_reports_it(): void
    {
        $this->mock(ApiFootballGoalOddsSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->once()->andReturn($this->goalSummary());
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[goal-odds] competitions=1  api_calls=3  created=10')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();
    }

    public function test_goal_odds_error_is_reported_but_does_not_block_startup_or_capture(): void
    {
        $this->mock(ApiFootballGoalOddsSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->once()->andReturn($this->goalSummary(['errors' => 1]));
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[goal-odds] 1 competition(s) FAILED (startup continues)')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->doesntExpectOutputToContain('SKIPPED')
            ->assertSuccessful();
    }

    public function test_goal_odds_exception_is_reported_but_does_not_block_startup_or_capture(): void
    {
        $this->mock(ApiFootballGoalOddsSyncService::class, function ($m) {
            $m->shouldReceive('syncPending')->once()->andThrow(new \RuntimeException('boom'));
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[goal-odds] sync failed: boom (startup continues)')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->doesntExpectOutputToContain('SKIPPED')
            ->assertSuccessful();
    }
}
