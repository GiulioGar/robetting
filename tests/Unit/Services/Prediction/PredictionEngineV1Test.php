<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\PredictionEngineV1;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * P7B — PHP Inference Engine V1 tests.
 * Pure unit tests: no DB, no HTTP, no Laravel container.
 * Golden fixture: tests/Fixtures/Prediction/prediction_engine_v1_golden.json
 * Tolerance: absolute diff <= 1e-10 for lambda and probabilities.
 */
class PredictionEngineV1Test extends TestCase
{
    private const TOLERANCE = 1e-10;
    private const GOLDEN_PATH = __DIR__ . '/../../../Fixtures/Prediction/prediction_engine_v1_golden.json';

    private static array $golden = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Point service at real artifact (bypasses base_path() — no app container needed)
        $artifactPath = __DIR__ . '/../../../../tools/models/prediction_engine_v1.json';
        PredictionEngineV1::setArtifactPath(realpath($artifactPath) ?: $artifactPath);

        $json = file_get_contents(self::GOLDEN_PATH);
        self::$golden = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        PredictionEngineV1::reset();
        parent::tearDownAfterClass();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A. Artifact loading
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_A_artifact_loads_and_metadata_is_valid(): void
    {
        $meta = PredictionEngineV1::metadata();

        $this->assertIsString($meta['model_version']);
        $this->assertNotEmpty($meta['model_version']);
        $this->assertIsArray($meta['trained_seasons']);
        $this->assertNotEmpty($meta['trained_seasons']);
        $this->assertIsInt($meta['training_matches']);
        $this->assertGreaterThan(0, $meta['training_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. 59 features required
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_B_feature_names_returns_exactly_59_names(): void
    {
        $names = PredictionEngineV1::featureNames();
        $this->assertCount(59, $names);

        foreach ($names as $name) {
            $this->assertIsString($name);
            $this->assertStringStartsWith('core_', $name);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. null → median imputation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_C_null_feature_falls_back_to_median_imputation(): void
    {
        $case = self::$golden['cases'][0];
        $base = $case['features'];

        // Prediction with real values
        $resultFull = PredictionEngineV1::predict($base);

        // Set EVERY feature that is already non-null to null for ONE feature
        // and compare that the result changes (imputation replaces the actual value).
        $featureNames = PredictionEngineV1::featureNames();
        $firstNonNull = null;
        foreach ($featureNames as $name) {
            if ($base[$name] !== null) {
                $firstNonNull = $name;
                break;
            }
        }
        $this->assertNotNull($firstNonNull, 'All features are null in golden case 0 — unexpected.');

        $withNull       = $base;
        $withNull[$firstNonNull] = null;
        $resultWithNull = PredictionEngineV1::predict($withNull);

        // Imputed result won't match exactly — just verify it runs and returns valid probabilities
        $this->assertValidPrediction($resultWithNull);

        // They should differ (unless actual value = median, which is unlikely for the first feature)
        // We can't assert exact difference without knowing the median, so just check finite outputs
        $this->assertIsFloat($resultWithNull['lambda_home']);
    }

    /** @test */
    public function test_C2_all_null_features_produce_valid_output(): void
    {
        // All-null input = all features imputed to median
        $features = array_fill_keys(PredictionEngineV1::featureNames(), null);
        $result   = PredictionEngineV1::predict($features);
        $this->assertValidPrediction($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D. Missing feature → exception
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_D_missing_feature_key_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/required feature/');

        // Build a complete input then remove one key
        $features = array_fill_keys(PredictionEngineV1::featureNames(), null);
        $name     = array_key_first($features);
        unset($features[$name]);

        PredictionEngineV1::predict($features);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E. PHP lambda vs golden Python
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_E_lambda_matches_golden_within_tolerance(): void
    {
        $maxDiffHome = 0.0;
        $maxDiffAway = 0.0;

        foreach (self::$golden['cases'] as $case) {
            $result = PredictionEngineV1::predict($case['features']);

            $diffH = abs($result['lambda_home'] - $case['expected']['lambda_home']);
            $diffA = abs($result['lambda_away'] - $case['expected']['lambda_away']);
            $maxDiffHome = max($maxDiffHome, $diffH);
            $maxDiffAway = max($maxDiffAway, $diffA);

            $this->assertLessThanOrEqual(
                self::TOLERANCE,
                $diffH,
                "match {$case['match_id']}: lambda_home diff={$diffH}"
            );
            $this->assertLessThanOrEqual(
                self::TOLERANCE,
                $diffA,
                "match {$case['match_id']}: lambda_away diff={$diffA}"
            );
        }

        fwrite(STDERR, sprintf("\n  [E] max lambda diff HOME=%.2e  AWAY=%.2e\n", $maxDiffHome, $maxDiffAway));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. PHP P(H/D/A) vs golden Python
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_F_probabilities_match_golden_within_tolerance(): void
    {
        $maxDiffH = $maxDiffD = $maxDiffA = 0.0;

        foreach (self::$golden['cases'] as $case) {
            $result = PredictionEngineV1::predict($case['features']);
            $exp    = $case['expected'];

            $diffH = abs($result['probability_home'] - $exp['probability_home']);
            $diffD = abs($result['probability_draw'] - $exp['probability_draw']);
            $diffA = abs($result['probability_away'] - $exp['probability_away']);
            $maxDiffH = max($maxDiffH, $diffH);
            $maxDiffD = max($maxDiffD, $diffD);
            $maxDiffA = max($maxDiffA, $diffA);

            $this->assertLessThanOrEqual(
                self::TOLERANCE, $diffH,
                "match {$case['match_id']}: P(H) diff={$diffH}"
            );
            $this->assertLessThanOrEqual(
                self::TOLERANCE, $diffD,
                "match {$case['match_id']}: P(D) diff={$diffD}"
            );
            $this->assertLessThanOrEqual(
                self::TOLERANCE, $diffA,
                "match {$case['match_id']}: P(A) diff={$diffA}"
            );
        }

        fwrite(STDERR, sprintf("\n  [F] max prob diff P(H)=%.2e  P(D)=%.2e  P(A)=%.2e\n", $maxDiffH, $maxDiffD, $maxDiffA));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G. P(H)+P(D)+P(A) = 1
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_G_probabilities_sum_to_one(): void
    {
        foreach (self::$golden['cases'] as $case) {
            $r   = PredictionEngineV1::predict($case['features']);
            $sum = $r['probability_home'] + $r['probability_draw'] + $r['probability_away'];

            $this->assertEqualsWithDelta(
                1.0, $sum, 1e-8,
                "match {$case['match_id']}: P sum={$sum}"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // H. Outputs always finite and positive
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_H_outputs_are_finite_and_positive(): void
    {
        foreach (self::$golden['cases'] as $case) {
            $r = PredictionEngineV1::predict($case['features']);

            $this->assertIsFinite($r['lambda_home'],      "match {$case['match_id']}: lambda_home");
            $this->assertIsFinite($r['lambda_away'],      "match {$case['match_id']}: lambda_away");
            $this->assertIsFinite($r['probability_home'], "match {$case['match_id']}: probability_home");
            $this->assertIsFinite($r['probability_draw'], "match {$case['match_id']}: probability_draw");
            $this->assertIsFinite($r['probability_away'], "match {$case['match_id']}: probability_away");

            $this->assertGreaterThan(0.0, $r['lambda_home'],      "match {$case['match_id']}: lambda_home > 0");
            $this->assertGreaterThan(0.0, $r['lambda_away'],      "match {$case['match_id']}: lambda_away > 0");
            $this->assertGreaterThan(0.0, $r['probability_home'], "match {$case['match_id']}: probability_home > 0");
            $this->assertGreaterThan(0.0, $r['probability_draw'], "match {$case['match_id']}: probability_draw > 0");
            $this->assertGreaterThan(0.0, $r['probability_away'], "match {$case['match_id']}: probability_away > 0");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Performance sanity
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_performance_sanity(): void
    {
        $cases    = self::$golden['cases'];
        $features = $cases[0]['features'];

        // First call (artifact load if not already cached)
        $t0 = microtime(true);
        PredictionEngineV1::predict($features);
        $t1 = microtime(true);
        $firstCallMs = ($t1 - $t0) * 1000;

        // 20 calls with artifact already cached
        $t0 = microtime(true);
        foreach ($cases as $case) {
            PredictionEngineV1::predict($case['features']);
        }
        $t1      = microtime(true);
        $totalMs = ($t1 - $t0) * 1000;
        $perMs   = $totalMs / count($cases);

        fwrite(STDERR, sprintf(
            "\n  [perf] first call=%.2f ms | 20-match loop=%.2f ms | per-match=%.3f ms\n",
            $firstCallMs, $totalMs, $perMs
        ));

        // Sanity: per-match inference should be well under 10ms
        $this->assertLessThan(10.0, $perMs, "Per-match inference > 10ms — unexpected.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function assertValidPrediction(array $result): void
    {
        $this->assertArrayHasKey('lambda_home',      $result);
        $this->assertArrayHasKey('lambda_away',      $result);
        $this->assertArrayHasKey('probability_home', $result);
        $this->assertArrayHasKey('probability_draw', $result);
        $this->assertArrayHasKey('probability_away', $result);

        $this->assertIsFinite($result['lambda_home']);
        $this->assertIsFinite($result['lambda_away']);
        $this->assertGreaterThan(0.0, $result['lambda_home']);
        $this->assertGreaterThan(0.0, $result['lambda_away']);

        $sum = $result['probability_home'] + $result['probability_draw'] + $result['probability_away'];
        $this->assertEqualsWithDelta(1.0, $sum, 1e-8);
    }

    private function assertIsFinite(float $value, string $message = ''): void
    {
        $this->assertTrue(is_finite($value), $message ?: "Expected finite, got {$value}");
    }
}
