<?php

namespace Tests\Feature\Console;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\OfficialLearningDatasetExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P27E1 — `robetting:export-learning-dataset` command-level behavior:
 * zero-rows exit code, overwrite protection, no-partial-file-on-error
 * safety, fail-closed on a missing/invalid feature schema artifact, and
 * multi-version ambiguity refusal. The core eligibility/dedup/schema logic
 * is covered by OfficialLearningDatasetExporterTest; this file only covers
 * what lives in the command (CLI options + CSV file writing).
 *
 * Artifact dir is redirected to an isolated temp dir (same pattern as
 * PredictionSnapshotsStatusCommandTest), pre-loaded with a minimal 2-feature
 * artifact matching createPrediction()'s default features_json — so tests
 * never touch the real tools/models/*.json artifacts, and --output always
 * points into a temp dir so the real tools/datasets/ is never touched
 * either. Tests that specifically exercise the fail-closed path delete this
 * default artifact first.
 */
class ExportLearningDatasetCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $artifactDir;
    private string $outputDir;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artifactDir = sys_get_temp_dir() . '/robetting_p27e1_cmd_artifact_' . uniqid();
        mkdir($this->artifactDir, 0755, true);
        OfficialLearningDatasetExporter::setArtifactDir($this->artifactDir);
        $this->writeDefaultArtifact();

        $this->outputDir = sys_get_temp_dir() . '/robetting_p27e1_cmd_output_' . uniqid();
        mkdir($this->outputDir, 0755, true);

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
    }

    protected function tearDown(): void
    {
        OfficialLearningDatasetExporter::resetArtifactDir();
        @array_map('unlink', glob($this->artifactDir . '/*') ?: []);
        @rmdir($this->artifactDir);
        @array_map('unlink', glob($this->outputDir . '/*') ?: []);
        @rmdir($this->outputDir);

        parent::tearDown();
    }

    /** Matches createPrediction()'s default features_json ('feat_a','feat_b'). */
    private function writeDefaultArtifact(): void
    {
        file_put_contents($this->artifactDir . '/prediction_engine_candidate47_structural_log.json', json_encode([
            'feature_set_version' => 'core_v1_candidate47_structural_log',
            'features' => ['feat_a', 'feat_b'],
        ]));
    }

    private function deleteDefaultArtifact(): void
    {
        @unlink($this->artifactDir . '/prediction_engine_candidate47_structural_log.json');
    }

    private function createMatch(string $status, ?int $hg, ?int $ag, string $kickoffAt = '2026-01-01 18:00:00'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function createPrediction(FootballMatch $match, array $overrides = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id'             => $match->id,
            'model_key'            => 'candidate47_structural_log',
            'model_version'        => '1.0.0',
            'feature_set_version'  => 'core_v1_candidate47_structural_log',
            'artifact_sha256'      => str_repeat('a', 64),
            'generated_at'         => now()->subDays(2),
            'kickoff_at'           => $match->kickoff_at,
            'lambda_home'          => 1.5,
            'lambda_away'          => 1.1,
            'lambda3'              => 0.15,
            'probability_home'     => 0.5,
            'probability_draw'     => 0.3,
            'probability_away'     => 0.2,
            'features_json'        => ['feat_a' => 1.0, 'feat_b' => 2.0],
        ], $overrides));
    }

    private function resolve(Prediction $prediction, int $hg, int $ag, string $status = 'finished'): Prediction
    {
        $outcome = $hg > $ag ? '1' : ($hg === $ag ? 'X' : '2');
        $prediction->update([
            'home_goals'             => $hg,
            'away_goals'             => $ag,
            'outcome'                => $outcome,
            'match_status_at_result' => $status,
            'result_recorded_at'     => now(),
        ]);

        return $prediction->refresh();
    }

    // 13. zero evaluable rows -> exit 0, no error, no CSV file written
    public function test_zero_rows_exits_successfully_without_writing_csv(): void
    {
        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $this->outputDir . '/nonexistent_case.csv',
        ])
            ->expectsOutputToContain('NO EVALUABLE OFFICIAL LEARNING ROWS YET')
            ->assertExitCode(0);

        $this->assertSame([], glob($this->outputDir . '/*') ?: []);
    }

    // 14. existing output file is never overwritten
    public function test_existing_output_file_is_not_overwritten(): void
    {
        $targetPath = $this->outputDir . '/existing.csv';
        file_put_contents($targetPath, "ORIGINAL CONTENT\n");

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $targetPath,
        ])->assertExitCode(1);

        $this->assertSame("ORIGINAL CONTENT\n", file_get_contents($targetPath));
    }

    // 15. no partial final file is left behind on a write error (invalid target directory)
    public function test_no_partial_file_left_on_write_error(): void
    {
        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        // A path whose parent cannot be created (file exists where a directory is expected).
        $blockerFile = $this->outputDir . '/blocker';
        file_put_contents($blockerFile, 'x');
        $impossibleTarget = $blockerFile . '/subdir/out.csv';

        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $impossibleTarget,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($impossibleTarget);
        // No stray temp file left behind in outputDir either — only the pre-existing blocker file.
        $this->assertSame([$blockerFile], glob($this->outputDir . '/*') ?: []);
    }

    // Successful export writes a real CSV with a header and the expected row.
    public function test_successful_export_writes_csv_with_header_and_row(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 2, 0);

        $targetPath = $this->outputDir . '/export.csv';

        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $targetPath,
        ])->assertExitCode(0);

        $this->assertFileExists($targetPath);
        $lines = array_filter(explode("\n", file_get_contents($targetPath)));
        $this->assertCount(2, $lines); // header + 1 row
        $this->assertStringContainsString('prediction_id', $lines[0]);
        $this->assertStringContainsString((string) $pred->id, $lines[1]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P27E1-FIX — fail-closed + multi-version refusal (command-level)
    // ─────────────────────────────────────────────────────────────────────

    // missing feature-schema artifact -> command fails cleanly, no CSV
    public function test_missing_artifact_fails_command_and_writes_no_csv(): void
    {
        $this->deleteDefaultArtifact();

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $targetPath = $this->outputDir . '/should_not_exist.csv';

        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $targetPath,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($targetPath);
        $this->assertSame([], glob($this->outputDir . '/*') ?: []);
    }

    // multiple model_version values without --model-version -> refuses, never mixes, no CSV
    public function test_multiple_model_versions_without_option_refuses_to_mix(): void
    {
        $match1 = $this->createMatch('finished', 1, 0, '2026-01-01 18:00:00');
        $pred1 = $this->createPrediction($match1, ['model_version' => '1.0.0']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_version' => '2.0.0']);
        $this->resolve($pred2, 1, 0);

        $this->artisan('robetting:export-learning-dataset', [
            '--output' => $this->outputDir . '/should_not_exist.csv',
        ])
            ->expectsOutputToContain('Please specify --model-version')
            ->assertExitCode(1);

        $this->assertSame([], glob($this->outputDir . '/*') ?: []);
    }

    // ...but specifying --model-version explicitly works fine even with multiple versions present.
    public function test_multiple_model_versions_with_explicit_option_succeeds(): void
    {
        $match1 = $this->createMatch('finished', 1, 0, '2026-01-01 18:00:00');
        $pred1 = $this->createPrediction($match1, ['model_version' => '1.0.0']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_version' => '2.0.0']);
        $this->resolve($pred2, 1, 0);

        $targetPath = $this->outputDir . '/v1_only.csv';

        $this->artisan('robetting:export-learning-dataset', [
            '--model-version' => '1.0.0',
            '--output' => $targetPath,
        ])->assertExitCode(0);

        $this->assertFileExists($targetPath);
        $lines = array_filter(explode("\n", file_get_contents($targetPath)));
        $this->assertCount(2, $lines); // header + exactly 1 row (version 2.0.0 never mixed in)
        $this->assertStringContainsString((string) $pred1->id, $lines[1]);
    }
}
