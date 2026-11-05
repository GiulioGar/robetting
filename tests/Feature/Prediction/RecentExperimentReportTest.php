<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\RecentExperimentCaptureService as Cap;
use App\Services\Prediction\RecentExperimentReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team History vs RECENT_ALL report (read-only) with synthetic results.
 */
class RecentExperimentReportTest extends TestCase
{
    use RefreshDatabase;

    private Competition $uel;
    private Season $season;
    private Team $a;
    private Team $b;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $this->uel = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Europa League', 'slug' => 'uefa-europa-league', 'format' => 'cup', 'is_active' => true]);
        $this->season = Season::create(['competition_id' => $this->uel->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->a = Team::create(['name' => 'Alpha', 'type' => 'club', 'is_active' => true]);
        $this->b = Team::create(['name' => 'Beta', 'type' => 'club', 'is_active' => true]);
    }

    private function match(array $attrs = []): FootballMatch
    {
        return FootballMatch::create($attrs + [
            'competition_id' => $this->uel->id, 'season_id' => $this->season->id, 'home_team_id' => $this->a->id, 'away_team_id' => $this->b->id,
            'kickoff_at' => '2026-11-05 20:00:00', 'status' => 'finished', 'definitive_at' => '2026-11-05 22:00:00',
            'home_score_ft' => 2, 'away_score_ft' => 1,
        ]);
    }

    /** @param array{0:float,1:float,2:float} $th @param array{0:float,1:float,2:float} $all */
    private function pair(FootballMatch $m, array $th, array $all, array $over = [], float $gap = 0.8, int $allShots = 10): void
    {
        $base = [
            'match_id' => $m->id, 'purpose' => Prediction::PURPOSE_RECENT_EXPERIMENT, 'model_version' => '1.1.0', 'feature_set_version' => 'fs',
            'artifact_sha256' => str_repeat('a', 64), 'generated_at' => '2026-11-05 16:00:00', 'kickoff_at' => $m->kickoff_at,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15, 'structural_snapshot_version' => 'S1', 'latent_snapshot_generated_at' => '2026-10-20 18:59:59',
        ];
        foreach ([Cap::MODEL_KEY_TEAM_HISTORY => $th, Cap::MODEL_KEY_RECENT_ALL => $all] as $key => $p) {
            $meta = ['structural' => ['log_ratio_gap' => $gap], 'recent_windows' => [
                'recent_all' => ['home' => ['matches_with_results' => 10, 'matches_with_shot_stats' => 10], 'away' => ['matches_with_results' => 10, 'matches_with_shot_stats' => $allShots]],
                'team_history' => ['home' => ['matches_with_results' => 10, 'matches_with_shot_stats' => 10], 'away' => ['matches_with_results' => 4, 'matches_with_shot_stats' => 4]],
            ]];
            Prediction::create(array_merge($base, [
                'model_key' => $key, 'probability_home' => $p[0], 'probability_draw' => $p[1], 'probability_away' => $p[2],
                'features_json' => ['f' => 1.0, '_experiment' => $meta],
            ], $key === Cap::MODEL_KEY_RECENT_ALL ? $over : []));
        }
    }

    private function report(): array
    {
        return app(RecentExperimentReportService::class)->build();
    }

    public function test_metrics_are_computed_per_match_once_with_the_same_convention(): void
    {
        $m1 = $this->match();                                                                              // 2-1 -> outcome 1
        $m2 = $this->match(['home_score_ft' => 1, 'away_score_ft' => 1, 'home_score_et' => 2, 'away_score_et' => 1, 'home_score_penalties' => 5, 'away_score_penalties' => 4]);   // FT 1-1 -> X
        $this->pair($m1, [0.5, 0.3, 0.2], [0.6, 0.25, 0.15]);
        $this->pair($m2, [0.4, 0.4, 0.2], [0.5, 0.2, 0.3], [], -0.4, 3);
        Prediction::create([                                                                               // an OFFICIAL row of the same match must not matter
            'match_id' => $m1->id, 'model_key' => 'candidate47_structural_log', 'purpose' => Prediction::PURPOSE_OFFICIAL, 'model_version' => '1.1.0',
            'feature_set_version' => 'fs', 'generated_at' => '2026-11-05 15:00:00', 'kickoff_at' => $m1->kickoff_at, 'lambda_home' => 1, 'lambda_away' => 1,
            'probability_home' => 0.1, 'probability_draw' => 0.1, 'probability_away' => 0.8, 'features_json' => [],
        ]);

        $r = $this->report();

        $this->assertSame(4, $r['predictions_recorded']);                 // 4 recent_exp rows (the official one is ignored) vs 2 matches
        $this->assertSame(2, $r['matches_with_rows']);
        $this->assertSame(2, $r['evaluable']);
        $s = $r['summary'];
        $this->assertEqualsWithDelta((0.38 + 0.56) / 2, $s['team_history']['brier'], 1e-9);
        $this->assertEqualsWithDelta((0.245 + 0.98) / 2, $s['recent_all']['brier'], 1e-9);
        $this->assertEqualsWithDelta((-log(0.5) - log(0.4)) / 2, $s['team_history']['log_loss'], 1e-9);
        $this->assertEqualsWithDelta((-log(0.6) - log(0.2)) / 2, $s['recent_all']['log_loss'], 1e-9);
        $this->assertEqualsWithDelta(0.1425, $s['brier_diff'], 1e-9);          // positive: RECENT_ALL worse overall
        $this->assertGreaterThan(0, $s['log_loss_diff']);
        $this->assertSame(1, $s['recent_all_worse_log_loss']);
        $this->assertSame(1, $s['recent_all_better_log_loss']);
        $d = collect($r['matches'])->keyBy('match_id');
        $this->assertSame('1', $d[$m1->id]['outcome']);
        $this->assertSame('X', $d[$m2->id]['outcome']);                       // 90' score, not extra time / penalties
        $this->assertEqualsWithDelta(0.245 - 0.38, $d[$m1->id]['brier_diff'], 1e-9);
        $this->assertEqualsWithDelta(-0.4, $d[$m2->id]['structural_gap'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $d[$m2->id]['shot_coverage_all'], 1e-9);       // min(10/10, 3/10)
    }

    public function test_only_finished_certified_ninety_minute_results_are_evaluable(): void
    {
        foreach ([
            ['status' => 'scheduled', 'home_score_ft' => null, 'away_score_ft' => null, 'definitive_at' => null],
            ['status' => 'awarded'],
            ['status' => 'abandoned'],
            ['status' => 'finished', 'definitive_at' => null],
            ['status' => 'finished', 'home_score_ft' => null, 'away_score_ft' => null],
        ] as $attrs) {
            $this->pair($this->match($attrs), [0.4, 0.3, 0.3], [0.5, 0.3, 0.2]);
        }

        $r = $this->report();

        $this->assertSame(0, $r['evaluable']);
        $this->assertNull($r['summary']);
        $this->assertSame([], $r['matches']);
        $this->assertSame(5, count($r['excluded']['not_evaluable']));
        $this->assertContains('status awarded', $r['excluded']['not_evaluable']);
        $this->assertContains('finished but not certified (definitive_at missing)', $r['excluded']['not_evaluable']);
        $this->assertContains('FT score missing', $r['excluded']['not_evaluable']);
    }

    public function test_incoherent_or_incomplete_pairs_are_excluded_with_a_reason(): void
    {
        $this->pair($this->match(), [0.4, 0.3, 0.3], [0.5, 0.3, 0.2], ['artifact_sha256' => str_repeat('b', 64)]);
        $this->pair($this->match(), [0.4, 0.3, 0.3], [0.5, 0.3, 0.2], ['generated_at' => '2026-11-05 16:00:01']);
        $late = $this->match();
        $this->pair($late, [0.4, 0.3, 0.3], [0.5, 0.3, 0.2]);
        Prediction::where('match_id', $late->id)->update(['generated_at' => '2026-11-05 20:00:00']);          // not before kickoff
        $single = $this->match();
        $this->pair($single, [0.4, 0.3, 0.3], [0.5, 0.3, 0.2]);
        Prediction::where('match_id', $single->id)->where('model_key', Cap::MODEL_KEY_RECENT_ALL)->delete();

        $r = $this->report();

        $this->assertSame(0, $r['evaluable']);
        $why = implode(' | ', $r['excluded']['incoherent_pair']);
        $this->assertStringContainsString('artifact_sha256', $why);
        $this->assertStringContainsString('generated_at', $why);
        $this->assertStringContainsString('not strictly before kickoff', $why);
        $this->assertStringContainsString('exactly one row per variant', $why);
    }

    public function test_descriptive_block_separates_identical_pairs_and_withholds_correlation_on_tiny_samples(): void
    {
        $this->pair($this->match(), [0.5, 0.3, 0.2], [0.5, 0.3, 0.2]);                  // identical windows
        $this->pair($this->match(), [0.5, 0.3, 0.2], [0.6, 0.25, 0.15]);

        $r = $this->report();

        $this->assertSame(2, $r['evaluable']);
        $this->assertSame(1, $r['summary']['identical_predictions']);
        $this->assertSame(1, $r['descriptive']['matches_where_variants_differ']);
        $this->assertNull($r['descriptive']['corr_log_loss_diff_vs_abs_structural_gap']);     // < 3 usable matches
        $this->assertStringContainsString('no significance', $r['descriptive']['note']);
    }

    public function test_command_states_zero_evaluable_cases_clearly(): void
    {
        $this->pair($this->match(['status' => 'scheduled', 'home_score_ft' => null, 'away_score_ft' => null, 'definitive_at' => null, 'kickoff_at' => Carbon::now()->addDay()]),
            [0.4, 0.3, 0.3], [0.5, 0.3, 0.2]);

        $this->artisan('robetting:recent-experiment-report')
            ->expectsOutputToContain('EVALUABLE MATCHES=0')
            ->expectsOutputToContain('0 evaluable cases')
            ->assertSuccessful();
    }

    public function test_command_prints_summary_and_per_match_detail(): void
    {
        $this->pair($this->match(), [0.5, 0.3, 0.2], [0.6, 0.25, 0.15]);

        $this->artisan('robetting:recent-experiment-report')
            ->expectsOutputToContain('EVALUABLE MATCHES=1')
            ->expectsOutputToContain('Alpha - Beta')
            ->expectsOutputToContain('Descriptive only')
            ->assertSuccessful();
    }
}
