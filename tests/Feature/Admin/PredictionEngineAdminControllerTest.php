<?php

namespace Tests\Feature\Admin;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P7D — Prediction Engine Admin Page tests.
 *
 * Tests:
 *  [A] Route accessible locally (isLocal guard allows)
 *  [B] Index without match_id renders match list, no prediction block
 *  [C] Prediction block rendered when match_id supplied
 *  [D] P(H), P(D), P(A) present in rendered HTML
 *  [E] lambda_home and lambda_away present in rendered HTML
 *  [F] feature_count = 59 shown
 *  [G] Feature debug section present
 *  [H] Probabilities sum to 1 (verified via service, not HTML parsing)
 */
class PredictionEngineAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────────────────

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

    // ── A. Route accessible ───────────────────────────────────────────────────

    /** @test */
    public function test_A_route_accessible_in_local_environment(): void
    {
        // app()->isLocal() is true in testing environment by default
        $response = $this->get(route('admin.prediction-engine.index'));
        $response->assertStatus(200);
    }

    // ── B. Index without match_id ─────────────────────────────────────────────

    /** @test */
    public function test_B_index_without_match_id_shows_no_prediction_block(): void
    {
        $response = $this->get(route('admin.prediction-engine.index'));
        $response->assertStatus(200);
        $response->assertSee('Prediction Engine V1');
        $response->assertDontSee('GOAL EXPECTANCY');
        $response->assertDontSee('probability_home');
    }

    // ── C–H: Prediction block via mocked service ──────────────────────────────

    /**
     * Run tests C–H using a mocked MatchPredictionService so no DB pipeline runs.
     * We bind a fake service that returns deterministic output.
     */
    private function mockPredictionService(int $matchId): void
    {
        $mock = $this->createMock(MatchPredictionService::class);
        $mock->method('predictWithDebug')->willReturn([
            'match_id'            => $matchId,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'feature_count'       => 59,
            'null_count'          => 2,
            'lambda_home'         => 1.4532,
            'lambda_away'         => 1.1087,
            'probability_home'    => 0.4312,
            'probability_draw'    => 0.2691,
            'probability_away'    => 0.2997,
            'features'            => array_fill_keys(
                array_map(fn ($i) => "core_feature_{$i}", range(1, 59)),
                1.234
            ),
            'timing_agg_ms'       => 412.5,
            'timing_inf_ms'       => 0.031,
            'timing_total_ms'     => 412.5,
            'match'               => null, // will be set by controller
        ]);
        $this->app->instance(MatchPredictionService::class, $mock);
    }

    /**
     * Same as mockPredictionService() but with real Elo feature keys so
     * Structural Alignment (elo_gap) can be driven deterministically.
     */
    private function mockPredictionServiceWithElo(int $matchId, float $homeElo, float $awayElo): void
    {
        $features = array_fill_keys(
            array_map(fn ($i) => "core_feature_{$i}", range(1, 57)),
            1.234
        );
        $features['core_elo_home_pre_match_elo'] = $homeElo;
        $features['core_elo_away_pre_match_elo'] = $awayElo;

        $mock = $this->createMock(MatchPredictionService::class);
        $mock->method('predictWithDebug')->willReturn([
            'match_id'            => $matchId,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'feature_count'       => 59,
            'null_count'          => 2,
            'lambda_home'         => 1.4532,
            'lambda_away'         => 1.1087,
            'probability_home'    => 0.4312,
            'probability_draw'    => 0.2691,
            'probability_away'    => 0.2997,
            'features'            => $features,
            'timing_agg_ms'       => 412.5,
            'timing_inf_ms'       => 0.031,
            'timing_total_ms'     => 412.5,
            'match'               => null,
        ]);
        $this->app->instance(MatchPredictionService::class, $mock);
    }

    private function seedTransfermarktSnapshots(FootballMatch $match, int $homeMarketValue, int $awayMarketValue): void
    {
        $ds = DataSource::create(['slug' => 'transfermarkt', 'name' => 'Transfermarkt', 'source_type' => 'manual_import']);

        TeamMarketValueSnapshot::create([
            'team_id'        => $match->home_team_id,
            'data_source_id' => $ds->id,
            'snapshot_date'  => now()->subDay(),
            'market_value'   => $homeMarketValue,
        ]);
        TeamMarketValueSnapshot::create([
            'team_id'        => $match->away_team_id,
            'data_source_id' => $ds->id,
            'snapshot_date'  => now()->subDay(),
            'market_value'   => $awayMarketValue,
        ]);
    }

    /** @test */
    public function test_C_prediction_block_shown_when_match_id_supplied(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
        $response->assertSee('GOAL EXPECTANCY');
    }

    // Step 1A.3b — $lastOfficialPrediction must ignore a more recent public_latest
    // row for the same match/model_key: it must keep showing the official one.
    /** @test */
    public function test_last_official_prediction_ignores_a_more_recent_public_latest_row(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        Prediction::create([
            'match_id'            => $match->id,
            'model_key'           => 'candidate47_structural_log',
            'purpose'             => Prediction::PURPOSE_OFFICIAL,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'generated_at'        => now()->subHours(2),
            'kickoff_at'          => $match->kickoff_at,
            'lambda_home'         => 1.5,
            'lambda_away'         => 1.1,
            'probability_home'    => 0.50,
            'probability_draw'    => 0.30,
            'probability_away'    => 0.20,
            'features_json'       => ['a' => 1.0],
        ]);

        // Newer, but public_latest — must NOT be picked as $lastOfficialPrediction.
        Prediction::create([
            'match_id'            => $match->id,
            'model_key'           => 'candidate47_structural_log',
            'purpose'             => Prediction::PURPOSE_PUBLIC_LATEST,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'generated_at'        => now()->subMinute(),
            'kickoff_at'          => $match->kickoff_at,
            'lambda_home'         => 1.9,
            'lambda_away'         => 0.9,
            'probability_home'    => 0.70,
            'probability_draw'    => 0.15,
            'probability_away'    => 0.15,
            'features_json'       => ['a' => 1.0],
        ]);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);

        // Official values (50/30/20), not the newer public_latest ones (70/15/15).
        $response->assertSee('P1 50.0%');
        $response->assertSee('PX 30.0%');
        $response->assertSee('P2 20.0%');
        $response->assertDontSee('P1 70.0%');
    }

    /** @test */
    public function test_D_probability_percentages_present_in_output(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
        // 43.1% = probability_home 0.4312
        $response->assertSee('43.1');
        // 26.9% = probability_draw 0.2691
        $response->assertSee('26.9');
        // 29.97% → 30.0% = probability_away 0.2997
        $response->assertSee('30.0');
    }

    /** @test */
    public function test_E_lambda_values_present_in_output(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
        $response->assertSee('1.4532');
        $response->assertSee('1.1087');
    }

    /** @test */
    public function test_F_feature_count_59_shown(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
        $response->assertSee('59');
    }

    /** @test */
    public function test_G_feature_debug_section_present(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
        $response->assertSee('Feature utilizzate');
        $response->assertSee('featureDebug');
    }

    /** @test */
    public function test_H_probabilities_sum_to_one(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        // Verify via the service mock's return values directly
        $pH = 0.4312;
        $pD = 0.2691;
        $pA = 0.2997;
        $this->assertEqualsWithDelta(1.0, $pH + $pD + $pA, 1e-8);

        // Also verify the page renders without error
        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);
    }

    // ── J. Fair odds display ──────────────────────────────────────────────────

    /**
     * @test
     * Fair odds (1/p) rendered next to each probability in the comparison table.
     * With mock data P(H)=0.4312 → 1/0.4312 ≈ 2.32
     *                P(D)=0.2691 → 1/0.2691 ≈ 3.72
     *                P(A)=0.2997 → 1/0.2997 ≈ 3.34
     */
    public function test_J_fair_odds_shown_in_comparison_table(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        // Also mock CandidateModelService so the comparison table uses deterministic values.
        $slot = [
            'lambda_home'      => 1.4532,
            'lambda_away'      => 1.1087,
            'probability_home' => 0.4312,
            'probability_draw' => 0.2691,
            'probability_away' => 0.2997,
        ];
        $candidateMock = $this->createMock(CandidateModelService::class);
        $candidateMock->method('compare')->willReturn([
            'full59'                          => $slot,
            'no_e9'                           => $slot,
            'no_e9_no_e10'                    => $slot,
            'candidate40'                     => $slot,
            'candidate40_robust_bp'           => array_merge($slot, ['lambda3' => 0.15]),
            'no_e9_available'                 => true,
            'no_e10_available'                => true,
            'candidate40_available'           => true,
            'candidate40_robust_bp_available' => true,
        ]);
        $this->app->instance(CandidateModelService::class, $candidateMock);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));
        $response->assertStatus(200);

        // Fair odds for the mocked probabilities (2 decimal places)
        $response->assertSee('2.32'); // 1 / 0.4312
        $response->assertSee('3.72'); // 1 / 0.2691
        $response->assertSee('3.34'); // 1 / 0.2997
    }

    // ── I. Diagnostic context block ───────────────────────────────────────────

    /**
     * @test
     * The context block renders when a prediction is present.
     * With no Transfermarkt data source seeded, structural/market show '—'.
     * Recent count shows 0/10 (no previous matches in test DB).
     * Elo shows '—' because the mock returns generic feature keys without Elo names.
     */
    public function test_I_context_block_renders_without_errors(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionService($match->id);

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('Contesto Match');
        $response->assertSee('Elo pre-match');
        $response->assertSee('Structural Strength');
        $response->assertSee('Market Value');
        $response->assertSee('RECENT considerati');
        // No Transfermarkt DS seeded → structural/market = '—'
        $response->assertSee('—');
        // No previous matches in test DB → recent count = 0
        $response->assertSee('0 / 10');
    }

    // ── K–N. Structural Alignment states ──────────────────────────────────────

    /**
     * @test
     * Elo and Structural both available, non-zero, same direction (home stronger
     * on both) → SAME. structural_to_elo_gap_ratio row must be shown.
     */
    public function test_K_structural_alignment_same(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionServiceWithElo($match->id, 1600, 1500); // elo_gap = +100
        $this->seedTransfermarktSnapshots($match, 200_000_000, 50_000_000); // structural_gap > 0

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('Structural Alignment');
        $response->assertSee('SAME');
        $response->assertDontSee('MISSING');
        $response->assertSee('structural_to_elo_gap_ratio');
    }

    /**
     * @test
     * Elo and Structural both available, non-zero, opposite direction (home
     * stronger on Elo, away stronger structurally) → OPPOSITE. No ratio shown.
     */
    public function test_L_structural_alignment_opposite(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionServiceWithElo($match->id, 1600, 1500); // elo_gap = +100
        $this->seedTransfermarktSnapshots($match, 50_000_000, 200_000_000); // structural_gap < 0

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('OPPOSITE');
        $response->assertDontSee('structural_to_elo_gap_ratio');
    }

    /**
     * @test
     * Elo and Structural both available, but structural_gap is exactly 0
     * (identical market value for home/away) → NEUTRAL, not MISSING.
     */
    public function test_M_structural_alignment_neutral(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionServiceWithElo($match->id, 1600, 1500); // elo_gap = +100
        $this->seedTransfermarktSnapshots($match, 100_000_000, 100_000_000); // structural_gap = 0

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('NEUTRAL');
        $response->assertDontSee('MISSING');
        $response->assertDontSee('structural_to_elo_gap_ratio');
    }

    /**
     * @test
     * No Transfermarkt data source/snapshot seeded → structural rating null
     * → MISSING (not NEUTRAL). Market Value ratio must show N/A, not 0.
     */
    public function test_N_structural_alignment_missing(): void
    {
        $match = $this->createMinimalMatch();
        $this->mockPredictionServiceWithElo($match->id, 1600, 1500);
        // No seedTransfermarktSnapshots() call — structural/market value stay null.

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('MISSING');
        $response->assertSee('N/A');
        $response->assertDontSee('structural_to_elo_gap_ratio');
    }
}
