<?php

namespace Tests\Unit\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PredictionEngineV1;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P7C — MatchPredictionService unit tests.
 * No DB, no Laravel container.
 * Uses a controlled subclass to inject a synthetic flat snapshot,
 * bypassing PreMatchFeatureAggregator.
 *
 * Tests A–F as specified in P7C.
 */
class MatchPredictionServiceTest extends TestCase
{
    private static string $artifactPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $path = __DIR__ . '/../../../../tools/models/prediction_engine_v1.json';
        self::$artifactPath = realpath($path) ?: $path;
        PredictionEngineV1::setArtifactPath(self::$artifactPath);
    }

    public static function tearDownAfterClass(): void
    {
        PredictionEngineV1::reset();
        parent::tearDownAfterClass();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test double: subclass that bypasses PreMatchFeatureAggregator
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a valid synthetic flat array with all 59 features.
     * Values are taken from golden case 0 so the engine produces real output.
     *
     * @param  array<string, float|null>  $overrides  Per-feature overrides
     * @param  string[]                   $remove     Feature keys to remove entirely
     */
    private function makeFlatSnapshot(array $overrides = [], array $remove = []): array
    {
        $golden = json_decode(
            file_get_contents(__DIR__ . '/../../../Fixtures/Prediction/prediction_engine_v1_golden.json'),
            true
        );
        $flat = $golden['cases'][0]['features']; // already keyed by feature name

        foreach ($overrides as $k => $v) {
            $flat[$k] = $v;
        }
        foreach ($remove as $k) {
            unset($flat[$k]);
        }
        return $flat;
    }

    private function makeServiceWithFlat(array $flat): MatchPredictionService
    {
        return new class($flat) extends MatchPredictionService {
            public function __construct(private array $flat) {}

            protected function buildFlatSnapshot(FootballMatch $match): array
            {
                return $this->flat;
            }
        };
    }

    private function makeStubMatch(int $id = 9999): FootballMatch
    {
        $match = $this->createStub(FootballMatch::class);
        $match->method('__get')->willReturnCallback(fn ($k) => match ($k) {
            'id' => $id,
            default => null,
        });
        return $match;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A. Uses featureNames() from artifact, not a hardcoded list
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_A_feature_names_come_from_artifact_not_hardcoded(): void
    {
        $artifactNames = PredictionEngineV1::featureNames();
        $this->assertCount(59, $artifactNames);

        // Verify there is no hardcoded list in the service source — it must call featureNames()
        $source = file_get_contents(__DIR__ . '/../../../../app/Services/Prediction/MatchPredictionService.php');
        $this->assertStringContainsString('featureNames()', $source,
            'Service must call PredictionEngineV1::featureNames(), not maintain its own list.'
        );
        $this->assertStringNotContainsString("'core_elo_home_pre_match_elo'", $source,
            'Service must NOT hardcode individual feature names.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. Passes exactly 59 features to the engine
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_B_exactly_59_features_passed_to_engine(): void
    {
        $flat    = $this->makeFlatSnapshot();
        $service = $this->makeServiceWithFlat($flat);
        $result  = $service->predict($this->makeStubMatch());

        $this->assertSame(59, $result['feature_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. Null values are preserved (engine will impute)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_C_null_feature_values_are_preserved_not_silently_replaced(): void
    {
        $featureNames = PredictionEngineV1::featureNames();
        // Null the first two features
        $overrides = [
            $featureNames[0] => null,
            $featureNames[1] => null,
        ];
        $flat    = $this->makeFlatSnapshot($overrides);
        $service = $this->makeServiceWithFlat($flat);
        $result  = $service->predict($this->makeStubMatch());

        // null_count must reflect the two nulled features
        $this->assertGreaterThanOrEqual(2, $result['null_count'],
            'Service must pass null values through to the engine (imputation is the engine\'s job).'
        );
        // Output should still be valid (engine imputes medians)
        $this->assertArrayHasKey('probability_home', $result);
        $sum = $result['probability_home'] + $result['probability_draw'] + $result['probability_away'];
        $this->assertEqualsWithDelta(1.0, $sum, 1e-8);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D. Feature required by artifact but absent from flat → exception
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_D_absent_feature_key_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/required by artifact is absent/');

        $featureNames = PredictionEngineV1::featureNames();
        $flat         = $this->makeFlatSnapshot(remove: [$featureNames[0]]);
        $service      = $this->makeServiceWithFlat($flat);
        $service->predict($this->makeStubMatch());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E. Output contains all required keys
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_E_output_contains_required_keys(): void
    {
        $flat    = $this->makeFlatSnapshot();
        $service = $this->makeServiceWithFlat($flat);
        $result  = $service->predict($this->makeStubMatch(42));

        $required = [
            'match_id', 'model_version', 'feature_set_version',
            'feature_count', 'null_count',
            'lambda_home', 'lambda_away',
            'probability_home', 'probability_draw', 'probability_away',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        $this->assertSame(42, $result['match_id']);
        $this->assertIsString($result['model_version']);
        $this->assertGreaterThan(0.0, $result['lambda_home']);
        $this->assertGreaterThan(0.0, $result['lambda_away']);
        $this->assertIsFloat($result['probability_home']);
        $this->assertIsFloat($result['probability_draw']);
        $this->assertIsFloat($result['probability_away']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. P(H)+P(D)+P(A) ≈ 1
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_F_probabilities_sum_to_one(): void
    {
        $flat    = $this->makeFlatSnapshot();
        $service = $this->makeServiceWithFlat($flat);
        $result  = $service->predict($this->makeStubMatch());

        $sum = $result['probability_home'] + $result['probability_draw'] + $result['probability_away'];
        $this->assertEqualsWithDelta(1.0, $sum, 1e-8, "P(H)+P(D)+P(A) must equal 1, got {$sum}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Extra: extractFeatures is consistent with artifact feature count
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_extract_returns_exactly_artifact_count_of_features(): void
    {
        $service   = new class extends MatchPredictionService {
            protected function buildFlatSnapshot(FootballMatch $match): array { return []; }
            public function callExtract(array $names, array $flat): array
            {
                return $this->extractFeatures($names, $flat);
            }
        };

        $names  = PredictionEngineV1::featureNames();
        $flat   = array_fill_keys($names, 1.5);
        $result = $service->callExtract($names, $flat);

        $this->assertCount(59, $result);
        foreach ($result as $v) {
            $this->assertSame(1.5, $v);
        }
    }
}
