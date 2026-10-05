<?php

namespace Tests\Unit\Services\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\PredictionEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P27D1 — PredictionEvaluationService core: LogLoss/Brier/RPS formulas,
 * finished-only filter, duplicate-generation policy, model_key/version
 * scoping, aggregation. No DB writes are performed by the service itself.
 */
class PredictionEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PredictionEvaluationService $service;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PredictionEvaluationService();

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
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

    /** Second league fixture, created lazily only by tests that need multi-league grouping. */
    private function createMatchInSecondLeague(string $status, ?int $hg, ?int $ag, string $kickoffAt = '2026-01-03 18:00:00'): FootballMatch
    {
        $country = Country::create(['name' => 'England', 'code' => 'EN']);
        $competition = Competition::create(['name' => 'Premier League', 'slug' => 'premier-league-test', 'country_id' => $country->id]);
        $season = Season::create([
            'competition_id' => $competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
        $home = Team::create(['name' => 'Arsenal', 'country_id' => $country->id]);
        $away = Team::create(['name' => 'Chelsea', 'country_id' => $country->id]);

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
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
            'features_json'        => ['a' => 1.0],
        ], $overrides));
    }

    /** Resolve a prediction row directly (bypass the capture service, this test targets evaluation only). */
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

    // 1. LogLoss/Brier formulas match the historical p16a_step4_train_compare.py convention
    public function test_logloss_brier_rps_formulas_match_historical_convention(): void
    {
        $match = $this->createMatch('finished', 1, 1); // draw
        $pred = $this->createPrediction($match, [
            'probability_home' => 0.45,
            'probability_draw' => 0.30,
            'probability_away' => 0.25,
        ]);
        $this->resolve($pred, 1, 1);

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];
        $m = $report['metrics'];

        $this->assertSame(1, $m['n']);
        $this->assertEqualsWithDelta(-log(0.30), $m['log_loss'], 1e-9);

        $expectedBrier = (0.45 - 0) ** 2 + (0.30 - 1) ** 2 + (0.25 - 0) ** 2;
        $this->assertEqualsWithDelta($expectedBrier, $m['brier'], 1e-9);

        // RPS: ordering Away<Draw<Home, actual=Draw -> O1=0, O2=1
        $f1 = 0.25;
        $f2 = 0.25 + 0.30;
        $expectedRps = (($f1 - 0) ** 2 + ($f2 - 1) ** 2) / 2;
        $this->assertEqualsWithDelta($expectedRps, $m['rps'], 1e-9);
    }

    // 2. Perfect forecast -> RPS = 0 (knowledge/evaluation/rps.md section 13)
    public function test_rps_zero_for_perfect_home_forecast(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $pred = $this->createPrediction($match, [
            'probability_home' => 1.0,
            'probability_draw' => 0.0,
            'probability_away' => 0.0,
        ]);
        $this->resolve($pred, 2, 0);

        $m = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['metrics'];

        $this->assertEqualsWithDelta(0.0, $m['rps'], 1e-9);
    }

    // 3. outcome 1/X/2 derived correctly and predicted_outcome tie-break (Home wins ties)
    public function test_outcome_and_tie_break_priority(): void
    {
        $match = $this->createMatch('finished', 1, 1);
        $pred = $this->createPrediction($match, [
            'probability_home' => 0.34,
            'probability_draw' => 0.33,
            'probability_away' => 0.33,
        ]);
        $this->resolve($pred, 1, 1);

        $m = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['metrics'];

        // Home (0.34) is the max here -> predicted='1' != actual='X' -> incorrect
        $this->assertEqualsWithDelta(0.0, $m['accuracy'], 1e-9);
    }

    // 4. awarded excluded from evaluation
    public function test_awarded_excluded_from_evaluation(): void
    {
        $match = $this->createMatch('awarded', 3, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 3, 0, 'awarded');

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];

        $this->assertSame(1, $report['dataset']['excluded_awarded_walkover']);
        $this->assertSame(0, $report['dataset']['evaluated']);
        $this->assertNull($report['metrics']);
    }

    // 5. walkover excluded from evaluation
    public function test_walkover_excluded_from_evaluation(): void
    {
        $match = $this->createMatch('walkover', 3, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 3, 0, 'walkover');

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];

        $this->assertSame(1, $report['dataset']['excluded_awarded_walkover']);
        $this->assertSame(0, $report['dataset']['evaluated']);
    }

    // 6. unresolved excluded (home_goals still null)
    public function test_unresolved_excluded_from_evaluation(): void
    {
        $match = $this->createMatch('scheduled', null, null);
        $this->createPrediction($match);

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];

        $this->assertSame(1, $report['dataset']['unresolved']);
        $this->assertSame(0, $report['dataset']['resolved']);
        $this->assertSame(0, $report['dataset']['evaluated']);
        $this->assertNull($report['metrics']);
    }

    // 7. duplicate same match/model/version: only latest generated_at evaluated
    public function test_duplicate_same_match_model_version_keeps_latest_generated_at(): void
    {
        $match = $this->createMatch('finished', 2, 0);

        $older = $this->createPrediction($match, [
            'generated_at' => now()->subDays(5),
            'probability_home' => 0.40,
            'probability_draw' => 0.30,
            'probability_away' => 0.30,
        ]);
        $this->resolve($older, 2, 0);

        $newer = $this->createPrediction($match, [
            'generated_at' => now()->subDays(1),
            'probability_home' => 0.70,
            'probability_draw' => 0.20,
            'probability_away' => 0.10,
        ]);
        $this->resolve($newer, 2, 0);

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];

        $this->assertSame(2, $report['dataset']['resolved']);
        $this->assertSame(1, $report['dataset']['evaluated']);
        $this->assertSame(1, $report['dataset']['older_duplicates_excluded']);

        // Metrics must reflect the NEWER row's probabilities (0.70 home), not the older (0.40)
        $expectedLogLoss = -log(0.70);
        $this->assertEqualsWithDelta($expectedLogLoss, $report['metrics']['log_loss'], 1e-9);
    }

    // 8. different model_version rows are never mixed together
    public function test_different_model_versions_not_mixed(): void
    {
        $match1 = $this->createMatch('finished', 1, 0);
        $pred1 = $this->createPrediction($match1, ['model_version' => '1.0.0']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_version' => '2.0.0']);
        $this->resolve($pred2, 1, 0);

        $reports = $this->service->evaluate('candidate47_structural_log');

        $this->assertCount(2, $reports);
        $versions = array_column($reports, 'model_version');
        sort($versions);
        $this->assertSame(['1.0.0', '2.0.0'], $versions);

        foreach ($reports as $report) {
            $this->assertSame(1, $report['metrics']['n']);
        }
    }

    // 9. model_key filter: a different model_key's rows never appear
    public function test_model_key_filter_excludes_other_models(): void
    {
        $match1 = $this->createMatch('finished', 1, 0);
        $pred1 = $this->createPrediction($match1, ['model_key' => 'candidate47_structural_log']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_key' => 'full59']);
        $this->resolve($pred2, 1, 0);

        $reports = $this->service->evaluate('candidate47_structural_log');

        $this->assertCount(1, $reports);
        $this->assertSame('candidate47_structural_log', $reports[0]['model_key']);
        $this->assertSame(1, $reports[0]['metrics']['n']);
    }

    // 10. global aggregate: N, means, accuracy correct across several rows
    public function test_global_aggregate_across_multiple_predictions(): void
    {
        $m1 = $this->createMatch('finished', 2, 0, '2026-01-01 18:00:00');
        $p1 = $this->createPrediction($m1, ['probability_home' => 0.6, 'probability_draw' => 0.25, 'probability_away' => 0.15]);
        $this->resolve($p1, 2, 0); // Home, correctly predicted

        $m2 = $this->createMatch('finished', 0, 1, '2026-01-02 18:00:00');
        $p2 = $this->createPrediction($m2, ['probability_home' => 0.5, 'probability_draw' => 0.2, 'probability_away' => 0.3]);
        $this->resolve($p2, 0, 1); // Away, Home predicted -> incorrect

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];
        $m = $report['metrics'];

        $this->assertSame(2, $m['n']);
        $this->assertEqualsWithDelta(0.5, $m['accuracy'], 1e-9);
        $this->assertEqualsWithDelta((0.6 + 0.5) / 2, $m['predicted_mean']['1'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $m['actual_frequency']['1'], 1e-9); // one '1', one '2'
        $this->assertEqualsWithDelta(0.5, $m['actual_frequency']['2'], 1e-9);
    }

    // 11. zero evaluable predictions -> metrics null, dataset counts still reported
    public function test_zero_evaluable_predictions_returns_null_metrics(): void
    {
        $match = $this->createMatch('scheduled', null, null);
        $this->createPrediction($match);

        $reports = $this->service->evaluate('candidate47_structural_log');

        $this->assertCount(1, $reports);
        $this->assertNull($reports[0]['metrics']);
        $this->assertSame(1, $reports[0]['dataset']['total']);
    }

    // 12. no predictions at all for a model_key -> empty reports array
    public function test_no_predictions_for_model_key_returns_empty_array(): void
    {
        $reports = $this->service->evaluate('nonexistent_model_key');

        $this->assertSame([], $reports);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P27D2 — BY LEAGUE
    // ─────────────────────────────────────────────────────────────────────

    // 13. two different leagues aggregated separately, each with correct metrics
    public function test_by_league_aggregates_two_leagues_separately(): void
    {
        $m1 = $this->createMatch('finished', 2, 0, '2026-01-01 18:00:00');
        $p1 = $this->createPrediction($m1, ['probability_home' => 0.6, 'probability_draw' => 0.25, 'probability_away' => 0.15]);
        $this->resolve($p1, 2, 0); // Home, correct

        $m2 = $this->createMatchInSecondLeague('finished', 0, 1);
        $p2 = $this->createPrediction($m2, ['probability_home' => 0.5, 'probability_draw' => 0.2, 'probability_away' => 0.3]);
        $this->resolve($p2, 0, 1); // Away, Home predicted -> incorrect

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];
        $byLeague = $report['by_league'];

        $this->assertCount(2, $byLeague);

        $byName = collect($byLeague)->keyBy('competition_name');
        $this->assertTrue($byName->has('Serie A'));
        $this->assertTrue($byName->has('Premier League'));

        $serieA = $byName->get('Serie A')['metrics'];
        $this->assertSame(1, $serieA['n']);
        $this->assertEqualsWithDelta(1.0, $serieA['accuracy'], 1e-9);

        $pl = $byName->get('Premier League')['metrics'];
        $this->assertSame(1, $pl['n']);
        $this->assertEqualsWithDelta(0.0, $pl['accuracy'], 1e-9);
    }

    // 14. by_league never double-counts an older duplicate already excluded by P27D1 dedup
    public function test_by_league_respects_p27d1_dedup(): void
    {
        $match = $this->createMatch('finished', 2, 0);

        $older = $this->createPrediction($match, ['generated_at' => now()->subDays(5)]);
        $this->resolve($older, 2, 0);

        $newer = $this->createPrediction($match, ['generated_at' => now()->subDays(1)]);
        $this->resolve($newer, 2, 0);

        $report = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0];

        $this->assertCount(1, $report['by_league']);
        $this->assertSame(1, $report['by_league'][0]['metrics']['n']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P27D2 — FAVORITE ANALYSIS
    // ─────────────────────────────────────────────────────────────────────

    // 15. home favorite classified correctly, favorite/underdog/draw stats correct
    public function test_favorite_analysis_home_favorite(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $pred = $this->createPrediction($match, [
            'probability_home' => 0.60, 'probability_draw' => 0.25, 'probability_away' => 0.15,
        ]);
        $this->resolve($pred, 2, 0); // Home wins -> favorite wins

        $favorite = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['favorite_analysis'];
        $group = $favorite['by_classification']['HOME_FAVORITE'];

        $this->assertSame(1, $group['n']);
        $this->assertEqualsWithDelta(0.60, $group['mean_favorite_probability'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $group['actual_favorite_win_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $group['mean_predicted_draw_probability'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $group['actual_draw_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.15, $group['mean_predicted_underdog_probability'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $group['actual_underdog_win_rate'], 1e-9);
        $this->assertNull($favorite['by_classification']['AWAY_FAVORITE']);
    }

    // 16. away favorite classified correctly
    public function test_favorite_analysis_away_favorite(): void
    {
        $match = $this->createMatch('finished', 0, 2);
        $pred = $this->createPrediction($match, [
            'probability_home' => 0.20, 'probability_draw' => 0.20, 'probability_away' => 0.60,
        ]);
        $this->resolve($pred, 0, 2); // Away wins -> favorite wins

        $favorite = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['favorite_analysis'];
        $group = $favorite['by_classification']['AWAY_FAVORITE'];

        $this->assertSame(1, $group['n']);
        $this->assertEqualsWithDelta(0.60, $group['mean_favorite_probability'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $group['actual_favorite_win_rate'], 1e-9);
        $this->assertNull($favorite['by_classification']['HOME_FAVORITE']);
    }

    // 17-20. strength buckets: <0.45, 0.45-0.55, 0.55-0.65, >=0.65
    public function test_favorite_strength_buckets(): void
    {
        // Favorite probability = max(pHome, pAway) = pHome here (home is always
        // the favorite). Draw probability varied so the <0.45 bucket is
        // actually reachable (max(pHome,pAway) >= 0.45 whenever pDraw <= 0.1).
        $cases = [
            ['kickoff' => '2026-01-01 18:00:00', 'pHome' => 0.40, 'pDraw' => 0.35, 'pAway' => 0.25, 'bucket' => '<0.45'],
            ['kickoff' => '2026-01-02 18:00:00', 'pHome' => 0.50, 'pDraw' => 0.30, 'pAway' => 0.20, 'bucket' => '0.45-0.55'],
            ['kickoff' => '2026-01-03 18:00:00', 'pHome' => 0.60, 'pDraw' => 0.25, 'pAway' => 0.15, 'bucket' => '0.55-0.65'],
            ['kickoff' => '2026-01-04 18:00:00', 'pHome' => 0.70, 'pDraw' => 0.20, 'pAway' => 0.10, 'bucket' => '>=0.65'],
        ];

        foreach ($cases as $case) {
            $match = $this->createMatch('finished', 1, 0, $case['kickoff']);
            $pred = $this->createPrediction($match, [
                'probability_home' => $case['pHome'],
                'probability_draw' => $case['pDraw'],
                'probability_away' => $case['pAway'],
                'kickoff_at' => $case['kickoff'],
            ]);
            $this->resolve($pred, 1, 0);
        }

        $favorite = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['favorite_analysis'];

        foreach ($cases as $case) {
            $group = $favorite['by_bucket'][$case['bucket']];
            $this->assertNotNull($group, "bucket {$case['bucket']} should have 1 row");
            $this->assertSame(1, $group['n'], "bucket {$case['bucket']} count");
        }
    }

    // 21. tie P1 == P2 -> NO_CLEAR_FAVORITE, excluded from classification/buckets, counted separately
    public function test_tie_p1_equals_p2_is_no_clear_favorite(): void
    {
        $match = $this->createMatch('finished', 1, 1);
        $pred = $this->createPrediction($match, [
            'probability_home' => 0.40, 'probability_draw' => 0.20, 'probability_away' => 0.40,
        ]);
        $this->resolve($pred, 1, 1);

        $favorite = $this->service->evaluate('candidate47_structural_log', '1.0.0')[0]['favorite_analysis'];

        $this->assertSame(1, $favorite['no_clear_favorite_count']);
        $this->assertNull($favorite['by_classification']['HOME_FAVORITE']);
        $this->assertNull($favorite['by_classification']['AWAY_FAVORITE']);
        foreach ($favorite['by_bucket'] as $bucket) {
            $this->assertNull($bucket);
        }
    }

    // 22. favorite_analysis is null and by_league is empty when evaluated=0 (no misleading sections)
    public function test_favorite_analysis_and_by_league_are_empty_when_nothing_evaluated(): void
    {
        $match = $this->createMatch('scheduled', null, null);
        $this->createPrediction($match);

        $report = $this->service->evaluate('candidate47_structural_log')[0];

        $this->assertNull($report['favorite_analysis']);
        $this->assertSame([], $report['by_league']);
    }

    // 23. different model_versions still kept separate for by_league/favorite_analysis too
    public function test_favorite_and_league_not_mixed_across_model_versions(): void
    {
        $match1 = $this->createMatch('finished', 2, 0, '2026-01-01 18:00:00');
        $pred1 = $this->createPrediction($match1, [
            'model_version' => '1.0.0',
            'probability_home' => 0.60, 'probability_draw' => 0.25, 'probability_away' => 0.15,
        ]);
        $this->resolve($pred1, 2, 0);

        $match2 = $this->createMatch('finished', 0, 2, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, [
            'model_version' => '2.0.0',
            'probability_home' => 0.15, 'probability_draw' => 0.25, 'probability_away' => 0.60,
        ]);
        $this->resolve($pred2, 0, 2);

        $reports = $this->service->evaluate('candidate47_structural_log');
        $byVersion = collect($reports)->keyBy('model_version');

        $v1Favorite = $byVersion->get('1.0.0')['favorite_analysis'];
        $this->assertNotNull($v1Favorite['by_classification']['HOME_FAVORITE']);
        $this->assertNull($v1Favorite['by_classification']['AWAY_FAVORITE']);

        $v2Favorite = $byVersion->get('2.0.0')['favorite_analysis'];
        $this->assertNotNull($v2Favorite['by_classification']['AWAY_FAVORITE']);
        $this->assertNull($v2Favorite['by_classification']['HOME_FAVORITE']);
    }
}
