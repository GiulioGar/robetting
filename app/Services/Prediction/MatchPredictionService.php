<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Analytics\PreMatchFeatureAggregator;
use InvalidArgumentException;

/**
 * End-to-end V1 prediction for a single pre-match fixture.
 *
 * Pipeline:
 *   FootballMatch  →  PreMatchFeatureAggregator::aggregate()
 *                  →  PreMatchFeatureAggregator::flatten()
 *                  →  extract 59 feature names from PredictionEngineV1
 *                  →  PredictionEngineV1::predict()
 *
 * Guarantees:
 *   - Feature list comes exclusively from the artifact (no hardcoded list).
 *   - Null flat values are passed through; the engine applies median imputation.
 *   - Missing keys (feature in artifact but not in flat output) raise an exception.
 *   - AS-OF leakage guard is delegated to PreMatchFeatureAggregator (unchanged).
 *   - No DB writes. No HTTP. No scheduler.
 */
class MatchPredictionService
{
    /**
     * Run the full V1 prediction pipeline for a single match.
     *
     * @param  FootballMatch  $match
     * @return array{
     *     match_id: int,
     *     model_version: string,
     *     feature_set_version: string,
     *     feature_count: int,
     *     null_count: int,
     *     lambda_home: float,
     *     lambda_away: float,
     *     probability_home: float,
     *     probability_draw: float,
     *     probability_away: float,
     * }
     *
     * @throws InvalidArgumentException  if kickoff_at is null or a required feature is absent
     */
    public function predict(FootballMatch $match): array
    {
        // 1. Generate the full pre-match snapshot (AS-OF kickoff_at) and flatten.
        $flat = $this->buildFlatSnapshot($match);

        // 3. Extract exactly the 59 features required by the artifact — in order.
        $featureNames = PredictionEngineV1::featureNames();
        $engineInput  = $this->extractFeatures($featureNames, $flat);

        // 4. Count nulls for reporting (before engine applies imputation).
        $nullCount = count(array_filter($engineInput, fn ($v) => $v === null));

        // 5. Inference.
        $prediction = PredictionEngineV1::predict($engineInput);

        $meta = PredictionEngineV1::metadata();

        return [
            'match_id'            => $match->id,
            'model_version'       => $meta['model_version'],
            'feature_set_version' => $meta['feature_set_version'],
            'feature_count'       => count($engineInput),
            'null_count'          => $nullCount,
            'lambda_home'         => $prediction['lambda_home'],
            'lambda_away'         => $prediction['lambda_away'],
            'probability_home'    => $prediction['probability_home'],
            'probability_draw'    => $prediction['probability_draw'],
            'probability_away'    => $prediction['probability_away'],
        ];
    }

    /**
     * Run the full V1 prediction pipeline and return extra debug information.
     * The aggregation is performed ONCE — no duplicate pipeline execution.
     *
     * @return array Prediction result plus:
     *   - 'features'          array<string, float|null>  The 59 extracted features
     *   - 'timing_agg_ms'     float  Aggregation + flatten time in ms
     *   - 'timing_inf_ms'     float  Engine inference time in ms
     *   - 'timing_total_ms'   float  Total wall time in ms
     */
    public function predictWithDebug(FootballMatch $match): array
    {
        $t0  = microtime(true);
        $flat = $this->buildFlatSnapshot($match);
        $tAgg = (microtime(true) - $t0) * 1000;

        $featureNames = PredictionEngineV1::featureNames();
        $engineInput  = $this->extractFeatures($featureNames, $flat);
        $nullCount    = count(array_filter($engineInput, fn ($v) => $v === null));

        $t1         = microtime(true);
        $prediction = PredictionEngineV1::predict($engineInput);
        $tInf       = (microtime(true) - $t1) * 1000;

        $meta = PredictionEngineV1::metadata();

        return [
            'match_id'            => $match->id,
            'model_version'       => $meta['model_version'],
            'feature_set_version' => $meta['feature_set_version'],
            'feature_count'       => count($engineInput),
            'null_count'          => $nullCount,
            'lambda_home'         => $prediction['lambda_home'],
            'lambda_away'         => $prediction['lambda_away'],
            'probability_home'    => $prediction['probability_home'],
            'probability_draw'    => $prediction['probability_draw'],
            'probability_away'    => $prediction['probability_away'],
            'features'            => $engineInput,
            'timing_agg_ms'       => round($tAgg, 1),
            'timing_inf_ms'       => round($tInf, 3),
            'timing_total_ms'     => round($tAgg + $tInf, 1),
        ];
    }

    /**
     * Build the flat feature snapshot for a match.
     * Separated as a protected method so tests can override without needing the full DB pipeline.
     *
     * @return array<string, mixed>
     */
    protected function buildFlatSnapshot(FootballMatch $match): array
    {
        $snapshot = PreMatchFeatureAggregator::aggregate($match);
        return PreMatchFeatureAggregator::flatten($snapshot);
    }

    /**
     * Extract exactly the listed feature names from a flat snapshot.
     *
     * - Key present, non-null value → float
     * - Key present, null value     → null  (engine will impute)
     * - Key absent                  → InvalidArgumentException
     *
     * @param  string[]              $featureNames  Ordered list from artifact.
     * @param  array<string, mixed>  $flat          Output of PreMatchFeatureAggregator::flatten().
     * @return array<string, float|null>
     *
     * @throws InvalidArgumentException
     */
    protected function extractFeatures(array $featureNames, array $flat): array
    {
        $out = [];
        foreach ($featureNames as $name) {
            if (! array_key_exists($name, $flat)) {
                throw new InvalidArgumentException(
                    "MatchPredictionService: feature '{$name}' required by artifact is absent from flat snapshot. " .
                    'This likely means the feature set version does not match the PreMatchFeatureAggregator output.'
                );
            }
            $out[$name] = $flat[$name] === null ? null : (float) $flat[$name];
        }
        return $out;
    }
}
