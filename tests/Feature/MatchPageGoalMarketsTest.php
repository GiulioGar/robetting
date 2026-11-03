<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\GoalMarketProbabilityCalculator;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 43 — "Probabilità GOAL Robetting" on the public match page.
 *
 *  A. Candidate47 available  → section rendered from the SAME lambdas as the 1X2 box, values = calculator output
 *  B. FULL59 fallback        → section hidden (no Bivariate lambda3), page still renders
 *  C. base pipeline failure  → section hidden, page still renders
 *  D. finished match         → section hidden
 *  E. invalid lambdas        → section hidden, no error
 *  F. exactly one prediction/compare call per request (no duplicate predictions)
 */
class MatchPageGoalMarketsTest extends TestCase
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
        $competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-goal-markets', 'country_id' => $country->id]);
        $season      = Season::create(['competition_id' => $competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $home        = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $away        = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);

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

    private function mockBase(int $matchId, bool $throws = false): void
    {
        $predMock = $this->createMock(MatchPredictionService::class);
        $method   = $predMock->expects($this->any())->method('predictWithDebug');

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

    private function mockCompare(?array $v2, int $expectedCalls = -1): void
    {
        $candMock = $this->createMock(CandidateModelService::class);
        $old      = ['lambda_home' => 1.3, 'lambda_away' => 1.1,
                     'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
        $inputs   = ['structural_home' => 424_000_000.0, 'structural_away' => 111_800_000.0, 'structural_gap' => 312_200_000.0];

        $method = $candMock->expects($expectedCalls >= 0 ? $this->exactly($expectedCalls) : $this->any())->method('compare');
        $method->willReturn([
            'full59'                           => self::FULL59,
            'no_e9'                            => $old,
            'no_e9_no_e10'                     => $old,
            'candidate40'                      => $old,
            'candidate40_robust_bp'            => $old + ['lambda3' => 0.15],
            'candidate44_bp'                   => $old + ['lambda3' => 0.15],
            'candidate48_structural'           => $v2,
            'candidate48_structural_inputs'    => $v2 ? $inputs : null,
            'candidate47_structural_log'        => $v2,
            'candidate47_structural_log_inputs' => $v2 ? $inputs : null,
            'no_e9_available'                  => true,
            'no_e10_available'                 => true,
            'candidate40_available'            => true,
            'candidate40_robust_bp_available'  => true,
            'candidate44_bp_available'         => true,
            'candidate48_structural_available' => $v2 !== null,
            'candidate47_structural_log_available' => $v2 !== null,
        ]);

        $this->app->instance(CandidateModelService::class, $candMock);
    }

    /** HTML of the GOAL section only. */
    private function goalBlock(string $html): string
    {
        $start = strpos($html, 'data-testid="goal-markets"');
        $this->assertNotFalse($start, 'GOAL markets section not rendered');
        $end = strpos($html, 'data-testid="market-1x2"', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_A_section_rendered_with_calculator_values(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(self::V2);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();

        $expected = GoalMarketProbabilityCalculator::calculate(1.7735, 1.2685, 0.15);
        $this->assertEquals($expected, $response->viewData('goalMarkets'));

        $block = $this->goalBlock($response->getContent());
        $this->assertStringContainsString('Probabilità GOAL Robetting', $response->getContent());
        foreach (['goal-over-under', 'goal-btts', 'goal-team-goals', 'goal-multigol'] as $id) {
            $this->assertStringContainsString("data-testid=\"{$id}\"", $block);
        }

        $pct = fn (float $p) => number_format($p * 100, 1) . '%';
        foreach ($expected['total_goals'] as $line => $p) {
            $this->assertStringContainsString($pct($p['over']), $block, "over {$line}");
            $this->assertStringContainsString($pct($p['under']), $block, "under {$line}");
        }
        $this->assertStringContainsString($pct($expected['btts']['yes']), $block);
        $this->assertStringContainsString($pct($expected['btts']['no']), $block);
        foreach ($expected['multigol'] as $range => $p) {
            $this->assertStringContainsString('data-multigol="' . $range . '"', $block);
            $this->assertStringContainsString($pct($p), $block);
        }
        foreach (['home', 'away'] as $side) {
            foreach ($expected['team_goals'][$side] as $p) {
                $this->assertStringContainsString($pct($p['over']), $block);
            }
        }
        $this->assertStringContainsString('Napoli', $block);
        $this->assertStringContainsString('Frosinone', $block);
    }

    public function test_B_fallback_full59_hides_section(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(null);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('goalMarkets'));
        $response->assertDontSee('Probabilità GOAL Robetting');
        $response->assertSee('Pronostico Robetting');
    }

    public function test_C_base_failure_hides_section_without_breaking_page(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id, throws: true);
        $this->mockCompare(self::V2);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('goalMarkets'));
        $response->assertDontSee('Probabilità GOAL Robetting');
        $response->assertSee('Confronto pre-match');
    }

    public function test_D_finished_match_has_no_section(): void
    {
        $match = $this->createMatch('finished', false);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('goalMarkets'));
        $response->assertDontSee('Probabilità GOAL Robetting');
    }

    public function test_E_invalid_lambdas_hide_section_without_error(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(array_merge(self::V2, ['lambda_home' => 0.0]));

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $this->assertNull($response->viewData('goalMarkets'));
        $response->assertDontSee('Probabilità GOAL Robetting');
    }

    public function test_F_no_duplicate_prediction_call(): void
    {
        $match = $this->createMatch();
        $this->mockBase($match->id);
        $this->mockCompare(self::V2, expectedCalls: 1);

        $this->get(route('matches.show', $match))->assertOk();
    }
}
