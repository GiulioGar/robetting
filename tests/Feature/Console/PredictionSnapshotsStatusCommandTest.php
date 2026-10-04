<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PredictionSnapshotsStatus;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Targeted tests for `robetting:prediction-snapshots-status` (P23A).
 *
 * Pure read-only status: no snapshot is regenerated, no match prediction is
 * computed. The snapshots directory is redirected to an isolated temp dir
 * (PredictionSnapshotsStatus::setSnapshotsDir, same pattern as
 * CandidateModelService::setArtifactDir) so these tests never touch the
 * real tools/models/*.json files.
 */
class PredictionSnapshotsStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/snapshot_status_test_' . uniqid();
        mkdir($this->tempDir);
        PredictionSnapshotsStatus::setSnapshotsDir($this->tempDir);

        $country    = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);
    }

    protected function tearDown(): void
    {
        PredictionSnapshotsStatus::resetSnapshotsDir();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function season(string $slug, int $yearStart): Season
    {
        $competition = Competition::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'country_id' => $this->home->country_id],
        );

        return Season::create([
            'competition_id' => $competition->id,
            'name'           => $yearStart . '/' . substr((string) ($yearStart + 1), 2),
            'year_start'     => $yearStart,
            'year_end'       => $yearStart + 1,
        ]);
    }

    private function match(Season $season, string $kickoff, string $status, ?int $hg, ?int $ag): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $season->competition_id,
            'season_id'      => $season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function writeLatentSnapshot(int $matchCount, string $lastMatchIncludedAt): void
    {
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at'            => now()->toIso8601String(),
            'last_match_included_at'  => $lastMatchIncludedAt,
            'match_count'             => $matchCount,
            'source'                  => 'database',
            'model_version'           => 'latent_ad_v1_hardsumzero_analyticjac',
            'teams'                   => [['team_id' => $this->home->id, 'attack' => 0.1, 'defence' => -0.1]],
        ]));
    }

    private function writeStructuralSnapshot(): void
    {
        file_put_contents($this->tempDir . '/structural_strength_current.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'definition'   => 'top25_market_value',
            'teams'        => [(string) $this->home->id => ['top25_market_value' => 500000000]],
        ]));
    }

    // 1. Latent CURRENT
    public function test_latent_current_when_snapshot_matches_db(): void
    {
        $season = $this->season('serie-a', 2024);
        $this->match($season, '2026-01-01 18:00:00', 'finished', 2, 1);

        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('DB eligible matches now: 1')
            ->expectsOutputToContain('STATUS: CURRENT')
            ->assertExitCode(0);
    }

    // 2. Latent STALE perché DB ha un nuovo match
    public function test_latent_stale_when_db_has_new_eligible_match(): void
    {
        $season = $this->season('serie-a', 2024);
        $this->match($season, '2026-01-01 18:00:00', 'finished', 2, 1);
        $this->match($season, '2026-02-01 18:00:00', 'finished', 0, 0); // not yet in snapshot

        // Snapshot only knows about the first match.
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('DB eligible matches now: 2')
            ->expectsOutputToContain('STATUS: STALE')
            ->expectsOutputToContain('missing=1')
            ->expectsOutputToContain('robetting:refresh-prediction-snapshots --only=latent')
            ->assertExitCode(0); // the STATUS command itself always exits 0 (report succeeded)
    }

    // 3. match non eleggibile non rende STALE
    public function test_ineligible_match_does_not_cause_stale(): void
    {
        $season2425 = $this->season('serie-a', 2024);
        $this->match($season2425, '2026-01-01 18:00:00', 'finished', 2, 1);

        // Ineligible companions: not core league, pre-2024/25, not finished, missing score.
        $serieB = $this->season('serie-b', 2024);
        $this->match($serieB, '2026-01-02 18:00:00', 'finished', 1, 0);
        $season2324 = $this->season('serie-a', 2023);
        $this->match($season2324, '2024-01-01 18:00:00', 'finished', 1, 1);
        $this->match($season2425, '2026-01-03 18:00:00', 'scheduled', null, null);
        $this->match($season2425, '2026-01-04 18:00:00', 'finished', null, 1);

        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('DB eligible matches now: 1')
            ->expectsOutputToContain('STATUS: CURRENT')
            ->assertExitCode(0);
    }

    // 4. Structural disponibile
    public function test_structural_available(): void
    {
        $season = $this->season('serie-a', 2024);
        $this->match($season, '2026-01-01 18:00:00', 'finished', 2, 1);
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('definition:   top25_market_value')
            ->expectsOutputToContain('STATUS: AVAILABLE')
            ->assertExitCode(0);
    }

    // 5. snapshot missing/invalid gestito chiaramente
    public function test_missing_snapshots_are_reported_clearly(): void
    {
        // Neither snapshot file written at all.
        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('STATUS: MISSING')
            ->expectsOutputToContain('CANDIDATE V2 LOG: NOT READY')
            ->assertExitCode(0);
    }

    public function test_invalid_json_snapshot_is_reported_clearly(): void
    {
        file_put_contents($this->tempDir . '/latent_strength_current.json', '{not valid json');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('STATUS: INVALID')
            ->expectsOutputToContain('CANDIDATE V2 LOG: NOT READY')
            ->assertExitCode(0);
    }

    // 6. Candidate V2 LOG READY quando entrambi validi
    public function test_candidate_v2_log_ready_when_both_snapshots_valid(): void
    {
        $season = $this->season('serie-a', 2024);
        $this->match($season, '2026-01-01 18:00:00', 'finished', 2, 1);
        $this->writeLatentSnapshot(1, '2026-01-01T18:00:00Z');
        $this->writeStructuralSnapshot();

        $this->artisan('robetting:prediction-snapshots-status')
            ->expectsOutputToContain('CANDIDATE V2 LOG: READY')
            ->assertExitCode(0);
    }
}
