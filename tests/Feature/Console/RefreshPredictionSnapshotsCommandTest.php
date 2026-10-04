<?php

namespace Tests\Feature\Console;

use App\Services\Prediction\CandidateModelService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Targeted tests for `robetting:refresh-prediction-snapshots` (P22A + P22B
 * --only selective refresh).
 *
 * Process::fake() intercepts both python subprocess calls, so the real
 * generators (live Transfermarkt scrape, DB-backed latent fit) never run
 * here — these tests only verify the command's own orchestration/reporting
 * logic: --only routing, success/failure propagation, that a failed step
 * never touches the snapshot files on disk, and that the metadata report
 * reads what is actually there.
 */
class RefreshPredictionSnapshotsCommandTest extends TestCase
{
    private function fakeBothSuccess(): void
    {
        Process::fake([
            '*p16d_generate_structural_snapshot.py*' => Process::result(output: "Snapshot written atomically\n", exitCode: 0),
            '*generate_latent_strength_snapshot.py*' => Process::result(output: "Saved: latent_strength_current.json\n", exitCode: 0),
        ]);
    }

    // 1. default (no --only) → Structural + Latent
    public function test_default_runs_both_components(): void
    {
        $this->fakeBothSuccess();

        $this->artisan('robetting:refresh-prediction-snapshots')
            ->expectsOutputToContain('STRUCTURAL: SUCCESS')
            ->expectsOutputToContain('LATENT: SUCCESS')
            ->expectsOutputToContain('RESULT: SUCCESS')
            ->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'p16d_generate_structural_snapshot.py'));
        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 2. --only=all → Structural + Latent
    public function test_only_all_runs_both_components(): void
    {
        $this->fakeBothSuccess();

        $this->artisan('robetting:refresh-prediction-snapshots', ['--only' => 'all'])
            ->expectsOutputToContain('STRUCTURAL: SUCCESS')
            ->expectsOutputToContain('LATENT: SUCCESS')
            ->expectsOutputToContain('RESULT: SUCCESS')
            ->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'p16d_generate_structural_snapshot.py'));
        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 3. --only=latent → solo Latent (Structural MAI invocato)
    public function test_only_latent_runs_only_latent(): void
    {
        $this->fakeBothSuccess();

        $this->artisan('robetting:refresh-prediction-snapshots', ['--only' => 'latent'])
            ->doesntExpectOutputToContain('STRUCTURAL')
            ->expectsOutputToContain('LATENT: SUCCESS')
            ->expectsOutputToContain('RESULT: SUCCESS')
            ->assertExitCode(0);

        Process::assertNotRan(fn ($process) => str_contains($process->command[1] ?? '', 'p16d_generate_structural_snapshot.py'));
        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 4. --only=structural → solo Structural (Latent MAI invocato)
    public function test_only_structural_runs_only_structural(): void
    {
        $this->fakeBothSuccess();

        $this->artisan('robetting:refresh-prediction-snapshots', ['--only' => 'structural'])
            ->expectsOutputToContain('STRUCTURAL: SUCCESS')
            ->doesntExpectOutputToContain('LATENT')
            ->expectsOutputToContain('RESULT: SUCCESS')
            ->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command[1] ?? '', 'p16d_generate_structural_snapshot.py'));
        Process::assertNotRan(fn ($process) => str_contains($process->command[1] ?? '', 'generate_latent_strength_snapshot.py'));
    }

    // 5. --only invalido → errore, exit != 0, nessun processo eseguito
    public function test_invalid_only_value_fails_without_running_any_process(): void
    {
        Process::fake(); // any call here would be a bug — fail loudly if one happens

        $this->artisan('robetting:refresh-prediction-snapshots', ['--only' => 'bogus'])
            ->expectsOutputToContain('Invalid --only value')
            ->assertExitCode(2);

        Process::assertNothingRan();
    }

    // 6. structural failure → exit non-zero, snapshot precedente preservato
    public function test_structural_failure_is_reported_and_does_not_corrupt_snapshot(): void
    {
        $path = base_path('tools/models/structural_strength_current.json');
        $before = file_exists($path) ? file_get_contents($path) : null;

        Process::fake([
            '*p16d_generate_structural_snapshot.py*' => Process::result(
                output: "[1/96] ... HTTP 403\n",
                errorOutput: "ABORTING: 3 consecutive failures\n",
                exitCode: 1
            ),
            '*generate_latent_strength_snapshot.py*' => Process::result(output: "Saved: latent_strength_current.json\n", exitCode: 0),
        ]);

        $this->artisan('robetting:refresh-prediction-snapshots')
            ->expectsOutputToContain('STRUCTURAL: FAILED')
            ->expectsOutputToContain('LATENT: SUCCESS')
            ->expectsOutputToContain('RESULT: FAILED')
            ->assertExitCode(1);

        // The command never writes these files itself (that is the Python
        // generators' job) — a failed/faked run must leave them bit-for-bit
        // identical to whatever was on disk before.
        $after = file_exists($path) ? file_get_contents($path) : null;
        $this->assertSame($before, $after);
    }

    // 7. latent failure → exit non-zero, snapshot precedente preservato
    public function test_latent_failure_is_reported_and_does_not_corrupt_snapshot(): void
    {
        $path = base_path('tools/models/latent_strength_current.json');
        $before = file_exists($path) ? file_get_contents($path) : null;

        Process::fake([
            '*p16d_generate_structural_snapshot.py*' => Process::result(output: "Snapshot written atomically\n", exitCode: 0),
            '*generate_latent_strength_snapshot.py*' => Process::result(
                output: '',
                errorOutput: "Fit NOT successful — previous snapshot left untouched.\n",
                exitCode: 1
            ),
        ]);

        $this->artisan('robetting:refresh-prediction-snapshots')
            ->expectsOutputToContain('STRUCTURAL: SUCCESS')
            ->expectsOutputToContain('LATENT: FAILED')
            ->expectsOutputToContain('RESULT: FAILED')
            ->assertExitCode(1);

        $after = file_exists($path) ? file_get_contents($path) : null;
        $this->assertSame($before, $after);
    }

    public function test_metadata_report_reads_actual_snapshot_files(): void
    {
        $this->fakeBothSuccess();

        $structural = json_decode(
            (string) file_get_contents(base_path('tools/models/structural_strength_current.json')),
            true
        );
        $latent = json_decode(
            (string) file_get_contents(base_path('tools/models/latent_strength_current.json')),
            true
        );

        $this->artisan('robetting:refresh-prediction-snapshots')
            ->expectsOutputToContain('generated_at: ' . $structural['generated_at'])
            ->expectsOutputToContain('teams:        ' . count($structural['teams']))
            ->expectsOutputToContain('generated_at:           ' . $latent['generated_at'])
            ->expectsOutputToContain('last_match_included_at: ' . $latent['last_match_included_at'])
            ->expectsOutputToContain('match_count:            ' . $latent['match_count'])
            ->assertExitCode(0);
    }

    // 8. Candidate V2 LOG continua a caricare gli snapshot dopo l'esistenza del comando
    public function test_candidate_v2_log_still_loads_snapshots_after_command_exists(): void
    {
        // Does not invoke the command (no need to touch real files) — this
        // only verifies that adding the command did not disturb the
        // existing CandidateModelService snapshot-loading contract.
        CandidateModelService::reset();

        $structural = CandidateModelService::structuralLogFeatures(100.0, 50.0);
        $this->assertNotNull($structural);
        $this->assertArrayHasKey('structural_log_ratio', $structural);

        $this->assertFileExists(base_path('tools/models/structural_strength_current.json'));
        $this->assertFileExists(base_path('tools/models/latent_strength_current.json'));
    }
}
