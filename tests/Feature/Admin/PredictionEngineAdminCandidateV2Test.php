<?php

namespace Tests\Feature\Admin;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P16B — Admin "Model Comparison" simplification tests.
 *
 * Only FULL59 and ROBETTING CANDIDATE V2 must be visible; the older
 * NO_E9/CANDIDATE39/40/C40 ROBUST BP/C44 BP slots keep working under the
 * hood (CandidateModelService still computes them, still tested elsewhere)
 * but must not render on this page.
 */
class PredictionEngineAdminCandidateV2Test extends TestCase
{
    use RefreshDatabase;

    private function createMinimalMatch(): FootballMatch
    {
        $country     = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $competition = Competition::create([
            'name'       => 'Serie A',
            'slug'       => 'serie-a-test',
            'country_id' => $country->id,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2025/26',
            'year_start'     => 2025,
            'year_end'       => 2026,
        ]);
        $home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => now()->addDays(2),
            'status'         => 'scheduled',
        ]);
    }

    private function mockServices(int $matchId, bool $c48Available): void
    {
        $predMock = $this->createMock(MatchPredictionService::class);
        $predMock->method('predictWithDebug')->willReturn([
            'match_id'            => $matchId,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'feature_count'       => 59,
            'null_count'          => 0,
            'lambda_home'         => 1.4532,
            'lambda_away'         => 1.1087,
            'probability_home'    => 0.4312,
            'probability_draw'    => 0.2691,
            'probability_away'    => 0.2997,
            'features'            => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.234),
            'timing_agg_ms'       => 10.0,
            'timing_inf_ms'       => 0.1,
            'timing_total_ms'     => 10.1,
            'match'               => null,
        ]);
        $this->app->instance(MatchPredictionService::class, $predMock);

        $c48Slot = $c48Available ? [
            'lambda_home'      => 1.50,
            'lambda_away'      => 1.00,
            'lambda3'          => 0.15,
            'probability_home' => 0.50,
            'probability_draw' => 0.27,
            'probability_away' => 0.23,
        ] : null;

        $oldSlot = [
            'lambda_home' => 1.30, 'lambda_away' => 1.10,
            'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30,
        ];

        $candMock = $this->createMock(CandidateModelService::class);
        $candMock->method('compare')->willReturn([
            'full59'                          => ['lambda_home' => 1.4532, 'lambda_away' => 1.1087,
                                                    'probability_home' => 0.4312, 'probability_draw' => 0.2691, 'probability_away' => 0.2997],
            'no_e9'                           => $oldSlot,
            'no_e9_no_e10'                    => $oldSlot,
            'candidate40'                     => $oldSlot,
            'candidate40_robust_bp'           => array_merge($oldSlot, ['lambda3' => 0.15]),
            'candidate44_bp'                  => array_merge($oldSlot, ['lambda3' => 0.15]),
            'candidate48_structural'          => $c48Slot,
            'candidate48_structural_inputs'   => $c48Available ? [
                'structural_home' => 450_000_000.0,
                'structural_away' => 120_000_000.0,
                'structural_gap'  => 330_000_000.0,
            ] : null,
            'no_e9_available'                 => true,
            'no_e10_available'                => true,
            'candidate40_available'           => true,
            'candidate40_robust_bp_available' => true,
            'candidate44_bp_available'        => true,
            'candidate48_structural_available' => $c48Available,
        ]);
        $this->app->instance(CandidateModelService::class, $candMock);
    }

    /** @test */
    public function test_only_full59_and_candidate_v2_are_shown(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockServices($match->id, true);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('FULL 59 — PRODUCTION');
        $response->assertSee('ROBETTING CANDIDATE V2 — STRUCTURAL');
        $response->assertDontSee('NO_E9 51');
        $response->assertDontSee('CANDIDATE 39');
        $response->assertDontSee('CANDIDATE 40');
        $response->assertDontSee('C40 ROBUST BP');
        $response->assertDontSee('C44 BP');
    }

    /** @test */
    public function test_structural_top25_shown_when_candidate_v2_available(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockServices($match->id, true);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('Structural TOP25');
        $response->assertSee('450.000.000');
        $response->assertSee('120.000.000');
        $response->assertSee('330.000.000');
    }

    /** @test */
    public function test_structural_top25_shows_unavailable_message_when_candidate_v2_unavailable(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockServices($match->id, false);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('ROBETTING CANDIDATE V2 — STRUCTURAL');
        $response->assertSee('(n/a)');
        $response->assertSee('Structural TOP25 non disponibile');
    }
}
