<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ExportBp1Sample;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `robetting:export-bp1-sample` (B-P1): read-only export for tools/scripts/goal_bp1_prospective_analysis.py.
 * SYNTHETIC data only (test database); no real 2026/27 result is read here.
 */
class ExportBp1SampleCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '9c11330ed725d20c1609098643051d69b12ab717e2688e9116b2a2d4fa7135d8';

    private string $dir;
    private Team $home;
    private Team $away;
    private Season $season;
    private Season $oldSeason;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp1_export_test_' . uniqid();

        $country    = Country::create(['name' => 'Italy']);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);

        $comp = new Competition(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $comp->id = 15;
        $comp->save();
        $this->season    = Season::create(['competition_id' => 15, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->oldSeason = Season::create(['competition_id' => 15, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*'));
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function match(Season $season, string $kickoff, string $status, ?int $hg, ?int $ag, ?string $round): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => 15, 'season_id' => $season->id, 'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id, 'kickoff_at' => $kickoff, 'status' => $status,
            'home_score_ft' => $hg, 'away_score_ft' => $ag, 'round' => $round,
        ]);
    }

    private function prediction(FootballMatch $m, array $over = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id' => $m->id, 'purpose' => Prediction::PURPOSE_OFFICIAL, 'model_key' => 'candidate47_structural_log',
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'artifact_sha256' => self::SHA,
            'generated_at' => '2026-10-31 10:00:00', 'kickoff_at' => $m->kickoff_at,
            'lambda_home' => 1.6, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.45, 'probability_draw' => 0.27, 'probability_away' => 0.28,
            'features_json' => ['a' => 1], 'latent_snapshot_generated_at' => '2026-10-30 13:57:40',
        ], $over));
    }

    private function readCsv(string $path): array
    {
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $head = array_shift($rows);

        return array_map(fn ($r) => array_combine($head, $r), $rows);
    }

    public function test_requires_out_dir_and_does_not_overwrite_without_force(): void
    {
        $this->artisan('robetting:export-bp1-sample')->assertExitCode(2);

        $this->artisan('robetting:export-bp1-sample', ['--out-dir' => $this->dir])->assertSuccessful();
        $this->artisan('robetting:export-bp1-sample', ['--out-dir' => $this->dir])->assertFailed();
        $this->artisan('robetting:export-bp1-sample', ['--out-dir' => $this->dir, '--force' => true])->assertSuccessful();
    }

    public function test_exports_only_official_rows_of_the_season_with_playoff_flag_and_is_read_only(): void
    {
        $reg  = $this->match($this->season, '2026-11-07 15:00:00', 'finished', 2, 1, 'Regular Season - 11');
        $po   = $this->match($this->season, '2026-11-08 15:00:00', 'finished', 1, 1, 'Relegation Round');
        $nul  = $this->match($this->season, '2026-11-09 15:00:00', 'scheduled', null, null, null);
        $old  = $this->match($this->oldSeason, '2026-05-20 15:00:00', 'finished', 0, 0, 'Regular Season - 38');

        $this->prediction($reg, ['availability_snapshot_json' => ['k' => 1]]);
        $this->prediction($po);
        $this->prediction($nul);
        $this->prediction($reg, ['purpose' => Prediction::PURPOSE_PUBLIC_LATEST]);   // not official
        $this->prediction($old);                                                      // other season

        $before = [Prediction::count(), FootballMatch::count()];
        $this->artisan('robetting:export-bp1-sample', ['--out-dir' => $this->dir])->assertSuccessful();
        $this->assertSame($before, [Prediction::count(), FootballMatch::count()], 'export must not write');

        $pred = $this->readCsv($this->dir . '/bp1_predictions.csv');
        $this->assertSame(ExportBp1Sample::PREDICTION_COLUMNS, array_keys($pred[0]));
        $this->assertCount(3, $pred);
        $byMatch = array_column($pred, null, 'match_id');
        $this->assertSame('0', $byMatch[$reg->id]['is_playoff']);
        $this->assertSame('1', $byMatch[$po->id]['is_playoff']);
        $this->assertSame('', $byMatch[$nul->id]['is_playoff']);   // NULL label: never guessed
        $this->assertSame('1', $byMatch[$reg->id]['has_availability']);
        $this->assertSame('0', $byMatch[$po->id]['has_availability']);
        $this->assertSame('2', $byMatch[$reg->id]['home_score_ft']);
        $this->assertSame('', $byMatch[$nul->id]['home_score_ft']);
        $this->assertSame(self::SHA, $byMatch[$reg->id]['artifact_sha256']);
        $this->assertSame('official', $byMatch[$reg->id]['purpose']);

        $elig = $this->readCsv($this->dir . '/bp1_eligible.csv');
        $this->assertSame(ExportBp1Sample::ELIGIBLE_COLUMNS, array_keys($elig[0]));
        $this->assertCount(3, $elig);   // the 3 matches of 2026/27, any status; the 2025/26 one is not exported
    }

    public function test_playoff_flag_rule(): void
    {
        $this->assertSame(0, ExportBp1Sample::playoffFlag('Regular Season - 1'));
        $this->assertSame(0, ExportBp1Sample::playoffFlag('Regular Season - 38'));
        $this->assertSame(1, ExportBp1Sample::playoffFlag('Final'));
        $this->assertSame(1, ExportBp1Sample::playoffFlag('Relegation Round'));
        $this->assertSame(1, ExportBp1Sample::playoffFlag('Regular Season - x'));
        $this->assertNull(ExportBp1Sample::playoffFlag(null));
        $this->assertNull(ExportBp1Sample::playoffFlag('  '));
    }

    /** Export -> analysis script, end to end, on synthetic rows (skipped if the python launcher is unavailable). */
    public function test_export_is_readable_by_the_analysis_script(): void
    {
        $py = getenv('ROBETTING_PYTHON') ?: 'py';
        $probe = new Process([$py, '--version']);
        try {
            $probe->run();
        } catch (\Throwable) {
            $this->markTestSkipped('python launcher not available');
        }
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python launcher not available');
        }

        $days = ['2026-11-03', '2026-11-04', '2026-11-10', '2026-11-11', '2026-11-17', '2026-11-18'];
        foreach ($days as $i => $d) {
            $m = $this->match($this->season, "$d 15:00:00", 'finished', $i % 3, $i % 2, 'Regular Season - ' . ($i + 11));
            $this->prediction($m, ['lambda_home' => 1.2 + $i * 0.1, 'lambda_away' => 1.0 + $i * 0.05]);
        }
        $this->prediction($this->match($this->season, '2026-11-12 15:00:00', 'finished', 1, 0, 'Final'));            // playoff: excluded, descriptive
        $this->prediction($this->match($this->season, '2026-11-19 15:00:00', 'scheduled', null, null, 'Regular Season - 20')); // not finished yet
        $this->match($this->season, '2026-11-20 15:00:00', 'finished', 1, 1, 'Regular Season - 21');                 // eligible, no official row

        $this->artisan('robetting:export-bp1-sample', ['--out-dir' => $this->dir])->assertSuccessful();

        $run = new Process([$py, '-I', base_path('tools/scripts/goal_bp1_prospective_analysis.py'),
            '--predictions', $this->dir . '/bp1_predictions.csv', '--eligible', $this->dir . '/bp1_eligible.csv',
            '--t-freeze', '2026-11-01T00:00:00Z', '--reps', '50']);
        $run->run();
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput() . $run->getOutput());

        $out = $run->getOutput();
        $this->assertStringContainsString('rows read: 8 | included in primary sample: 6', $out);
        $this->assertStringContainsString('playoff=1', $out);
        $this->assertStringContainsString('not_finished=1', $out);
        $this->assertStringContainsString('valid for the descriptive block N = 1', $out);
        $this->assertStringContainsString('eligible finished matches: 7 | in sample: 6', $out);
        $this->assertStringContainsString('no_official_row=1', $out);
    }
}
