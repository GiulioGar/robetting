<?php

namespace Tests\Feature;

use App\Console\Commands\PredictionSnapshotsStatus;
use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
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
use Tests\TestCase;

/**
 * `robetting:serve` startup refreshes the Latent snapshot (existing
 * `robetting:refresh-prediction-snapshots --only=latent`) only when the DB
 * holds eligible finished matches the snapshot does not include, BEFORE the
 * official predictions capture; a failed refresh skips that capture.
 *
 * The snapshots directory is redirected to a temp dir and the refresh's
 * python subprocess is faked (Process::fake): no real fit, no API.
 */
class ServeLocalLatentRefreshTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->tempDir = sys_get_temp_dir() . '/serve_latent_test_' . uniqid();
        mkdir($this->tempDir);
        PredictionSnapshotsStatus::setSnapshotsDir($this->tempDir);

        $this->seedFreshCalendar();
        $this->mockQuietSyncServices();
        $this->mockQuietPublicPredictionsRefresh();
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

    // 1. snapshot already covers the DB → no refresh, predictions capture runs
    public function test_current_snapshot_skips_refresh_and_captures_predictions(): void
    {
        $this->finishedMatch('2026-01-01 18:00:00');
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        Process::fake();
        $this->expectPredictionsCapture(1);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[latent] snapshot current')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();

        Process::assertNothingRan();
    }

    // 2. new eligible match → refresh runs with py, then predictions capture runs
    public function test_new_eligible_match_triggers_latent_refresh_then_capture(): void
    {
        $this->finishedMatch('2026-01-01 18:00:00');
        $this->finishedMatch('2026-02-01 18:00:00'); // not in the snapshot
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        Process::fake([
            '*generate_latent_strength_snapshot.py*' => Process::result(output: "Saved\n", exitCode: 0),
        ]);
        $this->expectPredictionsCapture(1);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('1 new eligible match(es)')
            ->expectsOutputToContain('[latent] latent snapshot refresh completed')
            ->expectsOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();

        Process::assertRan(fn ($p) => ($p->command[0] ?? null) === 'py'
            && str_contains($p->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 3. missing snapshot is treated as stale
    public function test_missing_snapshot_triggers_refresh(): void
    {
        Process::fake([
            '*generate_latent_strength_snapshot.py*' => Process::result(output: "Saved\n", exitCode: 0),
        ]);
        $this->expectPredictionsCapture(1);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('snapshot missing or invalid')
            ->assertSuccessful();

        Process::assertRan(fn ($p) => str_contains($p->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 4. failed refresh → clear error, official predictions capture NOT run
    public function test_failed_refresh_skips_predictions_capture(): void
    {
        $this->finishedMatch('2026-01-01 18:00:00');
        $this->finishedMatch('2026-02-01 18:00:00');
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        Process::fake([
            '*generate_latent_strength_snapshot.py*' => Process::result(errorOutput: "boom\n", exitCode: 1),
        ]);
        $this->expectPredictionsCapture(0);

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])
            ->expectsOutputToContain('[latent] latent snapshot refresh FAILED')
            ->expectsOutputToContain('official predictions capture SKIPPED')
            ->doesntExpectOutputToContain('[learning] official predictions capture completed')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function expectPredictionsCapture(int $times): void
    {
        $this->mock(OfficialPredictionCaptureService::class, function ($mock) use ($times) {
            $mock->shouldReceive('findEligibleMatches')->times($times)->andReturn(new EloquentCollection());
        });
    }

    private function finishedMatch(string $kickoff): FootballMatch
    {
        static $teams = null;
        if ($teams === null || ! Team::whereKey($teams[0]->id)->exists()) {
            $country = Country::firstOrCreate(['name' => 'Italy']);
            $teams   = [
                Team::create(['name' => 'Napoli', 'country_id' => $country->id]),
                Team::create(['name' => 'Frosinone', 'country_id' => $country->id]),
            ];
        }
        $competition = Competition::firstOrCreate(
            ['slug' => 'serie-a'],
            ['name' => 'serie-a', 'country_id' => $teams[0]->country_id],
        );
        $season = Season::firstOrCreate(
            ['competition_id' => $competition->id, 'year_start' => 2025],
            ['name' => '2025/26', 'year_end' => 2026],
        );

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $teams[0]->id,
            'away_team_id'   => $teams[1]->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    private function writeLatentSnapshot(int $matchCount, string $lastMatchIncludedAt): void
    {
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at'           => now()->toIso8601String(),
            'last_match_included_at' => $lastMatchIncludedAt,
            'match_count'            => $matchCount,
            'teams'                  => [],
        ]));
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

    private function mockQuietPublicPredictionsRefresh(): void
    {
        $this->mock(PublicPredictionRefreshService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andReturn([
                'eligible' => 0, 'created' => 0, 'updated' => 0, 'skipped_today' => 0, 'unavailable' => 0, 'errors' => 0,
            ]);
        });
    }

    private function mockQuietSyncServices(): void
    {
        $this->mock(ApiFootballResultRefreshService::class, function ($mock) {
            $r = ['status' => 'ok', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            $mock->shouldReceive('catchUp')->andReturn($r + ['sync_type' => 'catch_up']);
            $mock->shouldReceive('refresh')->andReturn($r + ['sync_type' => 'result_refresh']);
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
