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
        // slug must be one of config('api-football.core_leagues') values — the
        // --mode=core_only target filter now restricts to those slugs only.
        $this->comp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Test League',
            'slug'       => 'serie-a',
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
        // _9999.csv already matches both legacy and team_history test outputs
        // (e.g. dataset_core_v1_team_history_9999.csv) — do NOT broaden this
        // glob, it must never risk matching real production dataset filenames
        // such as dataset_core_v1_team_history_2024_2025.csv.
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

    // ── [I] --recent-window-mode default → legacy filename ──────────────────────

    public function test_recent_window_mode_defaults_to_legacy_filename(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $legacyPath = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $this->assertFileExists($legacyPath);
    }

    // ── [J] --recent-window-mode=legacy explicit → same legacy filename ─────────

    public function test_recent_window_mode_legacy_explicit_uses_legacy_filename(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $legacyPath = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'                => 'core_only',
            '--seasons'             => '9999',
            '--recent-window-mode'  => 'legacy',
        ])->assertExitCode(0);

        $this->assertFileExists($legacyPath);
    }

    // ── [K] --recent-window-mode=team_history → separate filename, legacy untouched ──

    public function test_recent_window_mode_team_history_uses_separate_filename_and_does_not_touch_legacy(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $legacyPath      = base_path('tools/datasets/dataset_core_v1_9999.csv');
        $teamHistoryPath = base_path('tools/datasets/dataset_core_v1_team_history_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'               => 'core_only',
            '--seasons'            => '9999',
            '--recent-window-mode' => 'team_history',
        ])->assertExitCode(0);

        $this->assertFileExists($teamHistoryPath);
        $this->assertFileDoesNotExist($legacyPath, 'team_history run must never create/overwrite the legacy filename.');
    }

    // ── [L] invalid --recent-window-mode rejected, no dataset written ───────────

    public function test_invalid_recent_window_mode_returns_failure_and_writes_no_file(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $legacyPath      = base_path('tools/datasets/dataset_core_v1_9999.csv');
        $teamHistoryPath = base_path('tools/datasets/dataset_core_v1_team_history_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'               => 'core_only',
            '--seasons'            => '9999',
            '--recent-window-mode' => 'bogus_mode',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($legacyPath);
        $this->assertFileDoesNotExist($teamHistoryPath);
    }

    // ── [M] legacy and team_history datasets can coexist (no overwrite either way) ──

    public function test_legacy_and_team_history_datasets_coexist(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $legacyPath      = base_path('tools/datasets/dataset_core_v1_9999.csv');
        $teamHistoryPath = base_path('tools/datasets/dataset_core_v1_team_history_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'               => 'core_only',
            '--seasons'            => '9999',
            '--recent-window-mode' => 'team_history',
        ])->assertExitCode(0);

        $this->assertFileExists($legacyPath);
        $this->assertFileExists($teamHistoryPath);
    }

    // ── [N] core_only target filter: non-core-league competitions excluded ──────

    public function test_core_only_excludes_matches_from_non_core_league_competitions(): void
    {
        $country     = Country::create(['name' => 'TestCo2', 'football_code' => 'TC2']);
        $cupComp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Test Cup',
            'slug'       => 'coppa-italia', // a real additional_competitions slug, not in core_leagues
            'format'     => 'cup',
            'is_active'  => true,
        ]);
        $cupSeason   = Season::create([
            'competition_id' => $cupComp->id,
            'name'           => '9999/00',
            'year_start'     => 9999,
            'year_end'       => 10000,
            'is_current'     => false,
        ]);
        $cupSeason->teams()->attach([$this->teamHome->id, $this->teamAway->id]);

        // Core-league match (should be included) + cup match (should be excluded).
        $this->makeMatch('9999-10-01 20:45:00');
        FootballMatch::create([
            'competition_id' => $cupComp->id,
            'season_id'      => $cupSeason->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '9999-10-02 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 2,
        ]);

        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'    => 'core_only',
            '--seasons' => '9999',
        ])->assertExitCode(0);

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $matchIdCol = array_search('match_id', $header, true);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row[$matchIdCol];
        }
        fclose($handle);

        $this->assertCount(1, $rows, 'only the core-league match must be included.');
    }

    // ── [O] --output overrides the destination filename ──────────────────────────

    public function test_output_option_overrides_destination_filename(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $defaultPath  = base_path('tools/datasets/dataset_core_v1_9999.csv');
        $overridePath = base_path('tools/datasets/custom_output_9999.csv');

        if (File::exists($overridePath)) {
            File::delete($overridePath);
        }

        try {
            $this->artisan('robetting:build-prediction-dataset', [
                '--mode'    => 'core_only',
                '--seasons' => '9999',
                '--output'  => $overridePath,
            ])->assertExitCode(0);

            $this->assertFileExists($overridePath, '--output must be honoured as the destination path.');
            $this->assertFileDoesNotExist($defaultPath, '--output must prevent writing to the default filename.');
        } finally {
            if (File::exists($overridePath)) {
                File::delete($overridePath);
            }
        }
    }

    // ── [P] --elo-mode: invalid value rejected ──────────────────────────────────

    public function test_invalid_elo_mode_returns_failure(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'     => 'core_only',
            '--seasons'  => '9999',
            '--elo-mode' => 'bogus_mode',
        ])->assertExitCode(1);
    }

    // ── [Q] --elo-mode=legacy (default/explicit) still succeeds ─────────────────

    public function test_elo_mode_legacy_succeeds(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'     => 'core_only',
            '--seasons'  => '9999',
            '--elo-mode' => 'legacy',
        ])->assertExitCode(0);

        $this->assertFileExists($path);
    }

    // ── [R] --elo-mode=network succeeds and is forwarded to the builder ─────────

    public function test_elo_mode_network_succeeds(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'     => 'core_only',
            '--seasons'  => '9999',
            '--elo-mode' => 'network',
        ])->assertExitCode(0);

        $this->assertFileExists($path);
    }

    // ── [S] --elo-mode=extended succeeds ─────────────────────────────────────────

    public function test_elo_mode_extended_succeeds(): void
    {
        $this->makeMatch('9999-10-01 20:45:00');
        $path = base_path('tools/datasets/dataset_core_v1_9999.csv');

        $this->artisan('robetting:build-prediction-dataset', [
            '--mode'     => 'core_only',
            '--seasons'  => '9999',
            '--elo-mode' => 'extended',
        ])->assertExitCode(0);

        $this->assertFileExists($path);
    }
}
