<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PublicMatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * P17B/P18D — "Pronostico Robetting" block on the public match page.
 *
 *  A. future match + V2 available  → Candidate V2 LOG used (never V2 RAW), no fallback
 *  B. P1+PX+P2 ≈ 1, fair odds = 1/p
 *  C. public values identical to the Admin Candidate V2 column
 *  D. V2 unavailable               → FULL59 fallback, flagged internally only
 *  E. compare() throws             → FULL59 fallback from base prediction
 *  F. finished match               → no block, pipeline never invoked
 *  G. base pipeline throws         → no block, page still renders
 *  H. no technical details rendered in the public block
 */
class MatchPagePublicPredictionTest extends TestCase
{
    use RefreshDatabase;

    private const V2 = [
        'lambda_home'      => 1.7735,
        'lambda_away'      => 1.2685,
        'lambda3'          => 0.15,
        'probability_home' => 0.4912,
        'probability_draw' => 0.2471,
        'probability_away' => 0.2617,
    ];

    private const V2_RAW = [
        'lambda_home'      => 1.8100,
        'lambda_away'      => 1.2000,
        'lambda3'          => 0.15,
        'probability_home' => 0.5050,
        'probability_draw' => 0.2410,
        'probability_away' => 0.2540,
    ];

    private const FULL59 = [
        'lambda_home'      => 1.6774,
        'lambda_away'      => 1.3710,
        'probability_home' => 0.4483,
        'probability_draw' => 0.2381,
        'probability_away' => 0.3136,
    ];

    private function createMatch(string $status = 'scheduled', bool $future = true): FootballMatch
    {
        $country     = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $competition = Competition::create([
            'name'       => 'Serie A',
            'slug'       => 'serie-a-p17b',
            'country_id' => $country->id,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);
        $home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => $future ? now()->addDays(3) : now()->subDays(3),
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 2 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ]);
    }

    private function mockBase(int $matchId, bool $throws = false, bool $expectNever = false): void
    {
        $predMock = $this->createMock(MatchPredictionService::class);
        $method   = $predMock->expects($expectNever ? $this->never() : $this->any())->method('predictWithDebug');

        if ($throws) {
            $method->willThrowException(new RuntimeException('aggregation failed'));
        } else {
            $method->willReturn(array_merge(self::FULL59, [
                'match_id'            => $matchId,
                'model_version'       => '1.0.0',
                'feature_set_version' => 'core_v1',
                'feature_count'       => 59,
                'null_count'          => 0,
                'features'            => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.0),
                'timing_agg_ms'       => 1.0,
                'timing_inf_ms'       => 0.1,
                'timing_total_ms'     => 1.1,
            ]));
        }

        $this->app->instance(MatchPredictionService::class, $predMock);
    }

    private function mockCompare(bool $v2Available, bool $throws = false): void
    {
        $candMock = $this->createMock(CandidateModelService::class);

        if ($throws) {
            $candMock->method('compare')->willThrowException(new RuntimeException('artifact missing'));
        } else {
            $old = ['lambda_home' => 1.3, 'lambda_away' => 1.1,
                    'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
            $candMock->method('compare')->willReturn([
                'full59'                           => self::FULL59,
                'no_e9'                            => $old,
                'no_e9_no_e10'                     => $old,
                'candidate40'                      => $old,
                'candidate40_robust_bp'            => $old + ['lambda3' => 0.15],
                'candidate44_bp'                   => $old + ['lambda3' => 0.15],
                // V2 RAW (rollback only) carries different numbers: the public page must never show them.
                'candidate48_structural'           => $v2Available ? self::V2_RAW : null,
                'candidate48_structural_inputs'    => $v2Available
                    ? ['structural_home' => 424_000_000.0, 'structural_away' => 111_800_000.0, 'structural_gap' => 312_200_000.0]
                    : null,
                'candidate47_structural_log'        => $v2Available ? self::V2 : null,
                'candidate47_structural_log_inputs' => $v2Available
                    ? ['structural_home' => 424_000_000.0, 'structural_away' => 111_800_000.0, 'structural_gap' => 312_200_000.0]
                    : null,
                'no_e9_available'                  => true,
                'no_e10_available'                 => true,
                'candidate40_available'            => true,
                'candidate40_robust_bp_available'  => true,
                'candidate44_bp_available'         => true,
                'candidate48_structural_available' => $v2Available,
                'candidate47_structural_log_available' => $v2Available,
            ]);
        }

        $this->app->instance(CandidateModelService::class, $candMock);
    }

    /** Rendered HTML of the public prediction block only. */
    private function publicBlock(string $html): string
    {
        $start = strpos($html, 'data-testid="public-prediction"');
        $this->assertNotFalse($start, 'Public prediction block not rendered');
        $end = strpos($html, 'Statistiche match', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_A_future_match_uses_candidate_v2(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(true);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $pp = $response->viewData('publicPrediction');
        $this->assertSame(PublicMatchPredictionService::MODEL_CANDIDATE_V2, $pp['model_used']);
        $this->assertFalse($pp['fallback_used']);
        $this->assertSame(self::V2['probability_home'], $pp['probability_home']);
        $this->assertSame(self::V2['lambda3'], $pp['lambda3']);

        $block = $this->publicBlock($response->getContent());
        $this->assertStringContainsString('Pronostico Robetting', $block);
        $this->assertStringContainsString('49.1%', $block);
        $this->assertStringContainsString('24.7%', $block);
        $this->assertStringContainsString('26.2%', $block);
        $this->assertStringNotContainsString('44.8%', $block); // FULL59 P1 not shown
        $this->assertStringNotContainsString('50.5%', $block); // V2 RAW P1 not shown
    }

    public function test_B_probabilities_sum_to_one_and_fair_odds_are_inverse(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(true);

        $pp = app(PublicMatchPredictionService::class)->predict($match);

        $this->assertEqualsWithDelta(1.0, $pp['probability_home'] + $pp['probability_draw'] + $pp['probability_away'], 1e-3);
        foreach (['home', 'draw', 'away'] as $k) {
            $this->assertEqualsWithDelta(1.0 / $pp["probability_{$k}"], $pp["fair_odds_{$k}"], 1e-12);
        }

        $block = $this->publicBlock($this->get(route('matches.show', $match))->getContent());
        $this->assertStringContainsString(number_format(1 / 0.4912, 2), $block); // 2.04
        $this->assertStringContainsString(number_format(1 / 0.2471, 2), $block); // 4.05
        $this->assertStringContainsString(number_format(1 / 0.2617, 2), $block); // 3.82
    }

    public function test_C_public_values_match_admin_candidate_v2_column(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(true);

        $admin  = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]))->getContent();
        $public = $this->publicBlock($this->get(route('matches.show', $match))->getContent());

        foreach (['probability_home', 'probability_draw', 'probability_away'] as $k) {
            $pctStr  = number_format(self::V2[$k] * 100, 1) . '%';
            $oddsStr = number_format(1 / self::V2[$k], 2);
            $this->assertStringContainsString($pctStr, $admin);
            $this->assertStringContainsString($pctStr, $public);
            $this->assertStringContainsString("({$oddsStr})", $admin);
            $this->assertStringContainsString($oddsStr, $public);
        }
    }

    public function test_D_v2_unavailable_falls_back_to_full59_silently(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(false);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $pp = $response->viewData('publicPrediction');
        $this->assertSame(PublicMatchPredictionService::MODEL_FULL59, $pp['model_used']);
        $this->assertTrue($pp['fallback_used']);
        $this->assertNull($pp['lambda3']);
        $this->assertSame(self::FULL59['probability_home'], $pp['probability_home']);

        $block = $this->publicBlock($response->getContent());
        $this->assertStringContainsString('44.8%', $block);
        $this->assertStringNotContainsStringIgnoringCase('fallback', $block);
        $this->assertStringNotContainsStringIgnoringCase('full', $block);
    }

    public function test_E_compare_exception_falls_back_to_base_full59(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(false, throws: true);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $pp = $response->viewData('publicPrediction');
        $this->assertSame(PublicMatchPredictionService::MODEL_FULL59, $pp['model_used']);
        $this->assertTrue($pp['fallback_used']);
        $this->assertSame(self::FULL59['probability_away'], $pp['probability_away']);
        $response->assertSee('Pronostico Robetting');
    }

    public function test_F_finished_match_has_no_prediction_block(): void
    {
        $match = $this->createMatch('finished', future: false);
        $this->mockBase($match->id, expectNever: true);
        $this->mockCompare(true);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('publicPrediction'));
        $response->assertDontSee('Pronostico Robetting');
    }

    public function test_F2_scheduled_but_past_kickoff_has_no_prediction_block(): void
    {
        $match = $this->createMatch('scheduled', future: false);
        $this->mockBase($match->id, expectNever: true);
        $this->mockCompare(true);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertDontSee('Pronostico Robetting');
    }

    public function test_G_base_pipeline_failure_hides_block_without_breaking_page(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id, throws: true);
        $this->mockCompare(true);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('publicPrediction'));
        $response->assertDontSee('Pronostico Robetting');
        $response->assertSee('Statistiche match');
    }

    public function test_H_public_block_hides_technical_details(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(true);

        $block = $this->publicBlock($this->get(route('matches.show', $match))->getContent());

        foreach (['λ', 'lambda', 'Structural', 'TOP25', 'Elo', 'Latent', 'Candidate', 'model', '1.7735', '0.1500', 'debug'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $block, "Technical detail '{$needle}' leaked");
        }
        $this->assertStringContainsString('Quota equa', $block);
    }
}
