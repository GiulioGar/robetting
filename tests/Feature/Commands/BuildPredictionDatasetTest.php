<?php

namespace Tests\Feature\Commands;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Feature tests for the robetting:build-prediction-dataset Artisan command.
 *
 * Tests:
 *  [A]  command creates a CSV file for a valid dataset
 *  [B]  CSV header is consistent with data row columns
 *  [C]  CSV row count matches the number of built rows
 *  [D]  --force is required to overwrite an existing file
 *  [E]  invalid --mode returns Command::FAILURE
 *
 * year_start = 9999 is used throughout to keep fixtures isolated from real data.
 */
class BuildPredictionDatasetTest extends TestCase
{
    use RefreshDatabase;

    private Competition $comp;
    private Season      $season;
    private Team        $teamHome;
    private Team        $teamAway;

    protected function setUp(): void
    {
        parent::setUp();

        $country        = Country::create(['name' => 'TestCo', 'football_code' => 'TC']);
        $this->comp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Test League',
            'slug'       => 'test-league',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season   = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '9999/00',
            'year_start'     => 9999,
            'year_end'       => 10000,
            'is_current'     => false,
        ]);
        $this->teamHome = Team::create(['name' => 'HomeFC', 'type' => 'club', 'is_active' => true]);
        $this->teamAway = Team::create(['name' => 'AwayFC', 'type' => 'club', 'is_active' => true]);

        $this->season->teams()->attach([$this->teamHome->id, $this->teamAway->id]);
    }

    protected function tearDown(): void
    {
        $dir   = base_path('tools/datasets');
        $files = File::glob($dir . '/dataset_*_9999.csv') ?: [];
        foreach ($files as $file) {
            File::delete($file);
        }
        parent::tearDown();
    }

    private function makeMatch(string $kickoff, int $home = 2, int $away = 1): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => $home,
            'away_score_ft'  => $away,
        ]);
    }

    // ── [A] Creates CSV ───────────────────────────────────────────────────────

    public function test_command_creates_csv_file(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $expected = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $this->assertFileExists($expected);
    }

    // ── [B] Header consistent with rows ──────────────────────────────────────

    public function test_csv_header_column_count_matches_data_row(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $row    = fgetcsv($handle);
        fclose($handle);

        $this->assertIsArray($header, 'CSV must have a header row.');
        $this->assertIsArray($row, 'CSV must have at least one data row.');
        $this->assertSame(count($header), count($row), 'Header and data row must have the same column count.');

        $this->assertContains('match_id', $header);
        $this->assertContains('kickoff_at', $header);
        $this->assertContains('label_result_1x2', $header);
    }

    // ── [C] Row count ─────────────────────────────────────────────────────────

    public function test_csv_data_row_count_matches_built_matches(): void
    {
        $this->makeMatch('9999-09-01 20:45:00');
        $this->makeMatch('9999-10-01 20:45:00');
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $handle   = fopen($path, 'r');
        fgetcsv($handle); // skip header
        $dataRows = 0;
        while (fgetcsv($handle) !== false) {
            $dataRows++;
        }
        fclose($handle);

        $this->assertSame(2, $dataRows);
    }

    // ── [D] --force required to overwrite ────────────────────────────────────

    public function test_force_is_required_to_overwrite_existing_file(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $args = ['--mode' => 'core_only', '--seasons' => '9999'];

        // First run succeeds.
        $this->artisan('robetting:build-prediction-dataset', $args)
            ->assertExitCode(0);

        // Second run without --force fails.
        $this->artisan('robetting:build-prediction-dataset', $args)
            ->assertExitCode(1);

        // Second run with --force succeeds.
        $this->artisan('robetting:build-prediction-dataset', $args + ['--force' => true])
            ->assertExitCode(0);
    }

    // ── [E] Invalid mode rejected ─────────────────────────────────────────────

    public function test_invalid_mode_returns_failure(): void
    {
        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'invalid_mode',
            '--seasons' => '9999',
        ])->assertExitCode(1);
    }

    // ── [F] finished → included ────────────────────────────────────────────────

    public function test_finished_match_is_included_in_dataset(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '9999-10-01 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $handle = fopen($path, 'r');
        fgetcsv($handle);
        $rows = 0;
        while (fgetcsv($handle) !== false) { $rows++; }
        fclose($handle);

        $this->assertSame(1, $rows, 'finished match must be included.');
    }

    // ── [G] awarded → excluded ────────────────────────────────────────────────

    public function test_awarded_match_is_excluded_from_dataset(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '9999-10-01 20:45:00',
            'status'         => 'awarded',
            'home_score_ft'  => 0,
            'away_score_ft'  => 0,
        ]);
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        // File should not be created (0 matches found → early return without writing)
        $this->assertFileDoesNotExist($path, 'awarded match must be excluded; no CSV created.');
    }

    // ── [H] walkover → excluded ───────────────────────────────────────────────

    public function test_walkover_match_is_excluded_from_dataset(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '9999-10-01 20:45:00',
            'status'         => 'walkover',
            'home_score_ft'  => 3,
            'away_score_ft'  => 0,
        ]);
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($path, 'walkover match must be excluded; no CSV created.');
    }
}
