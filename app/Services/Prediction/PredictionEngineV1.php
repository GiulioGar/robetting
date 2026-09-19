<?php

namespace App\Services\Prediction;

use RuntimeException;
use InvalidArgumentException;

/**
 * Offline Poisson GLM inference engine — reads prediction_engine_v1.json.
 * No DB, no HTTP, no Laravel dependencies.
 * Pipeline: median imputation -> StandardScaler -> dual PoissonRegressor -> Poisson 1X2.
 */
class PredictionEngineV1
{
    private const MAX_GOALS      = 10;
    private const EXPECTED_FEATS = 59;

    /** Loaded artifact, lazily initialised once per process. */
    private static ?array $artifact = null;

    /** Custom artifact path — set via setArtifactPath() for testing. Null = use base_path(). */
    private static ?string $artifactPath = null;

    /** Precomputed k! for k = 0..MAX_GOALS to avoid repeated computation. */
    private static array $factorials = [];

    /**
     * Override artifact path (for unit tests that run without the Laravel app container).
     * Call reset() to restore default after tests.
     */
    public static function setArtifactPath(string $path): void
    {
        self::$artifactPath = $path;
        self::$artifact     = null; // force reload from new path
    }

    /** Reset overrides (call in tearDownAfterClass). */
    public static function reset(): void
    {
        self::$artifact     = null;
        self::$artifactPath = null;
        self::$factorials   = [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run full inference pipeline.
     *
     * @param  array<string,float|null>  $features  Associative: feature_name => value|null
     * @return array{lambda_home:float, lambda_away:float, probability_home:float, probability_draw:float, probability_away:float}
     *
     * @throws InvalidArgumentException  if a required feature key is missing entirely
     * @throws RuntimeException          if the artifact is invalid or numerics are non-finite
     */
    public static function predict(array $features): array
    {
        $art = self::loadArtifact();

        $z = self::preprocess($features, $art);

        $lambdaHome = self::poissonLambda(
            $art['home_model']['intercept'],
            $art['home_model']['coefficients'],
            $z
        );

        $lambdaAway = self::poissonLambda(
            $art['away_model']['intercept'],
            $art['away_model']['coefficients'],
            $z
        );

        [$pH, $pD, $pA] = self::lambdaTo1x2($lambdaHome, $lambdaAway);

        return [
            'lambda_home'      => $lambdaHome,
            'lambda_away'      => $lambdaAway,
            'probability_home' => $pH,
            'probability_draw' => $pD,
            'probability_away' => $pA,
        ];
    }

    /** Return the list of feature names expected by the engine (from artifact). */
    public static function featureNames(): array
    {
        return self::loadArtifact()['features'];
    }

    /** Return the loaded artifact metadata (version, seasons, training_matches). */
    public static function metadata(): array
    {
        $art = self::loadArtifact();
        return [
            'model_version'      => $art['model_version'],
            'feature_set_version' => $art['feature_set_version'],
            'trained_seasons'    => $art['trained_seasons'],
            'training_matches'   => $art['training_matches'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Artifact loading (lazy, cached per process)
    // ─────────────────────────────────────────────────────────────────────────

    private static function loadArtifact(): array
    {
        if (self::$artifact !== null) {
            return self::$artifact;
        }

        $path = self::$artifactPath ?? base_path('tools/models/prediction_engine_v1.json');

        if (! file_exists($path)) {
            throw new RuntimeException("Prediction artifact not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Cannot read prediction artifact: {$path}");
        }

        $art = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::validateArtifact($art);

        self::$artifact = $art;
        return self::$artifact;
    }

    private static function validateArtifact(array $art): void
    {
        $required = ['model_version', 'features', 'imputer', 'scaler', 'home_model', 'away_model'];
        foreach ($required as $key) {
            if (! isset($art[$key])) {
                throw new RuntimeException("Artifact missing key: {$key}");
            }
        }

        $n = count($art['features']);
        if ($n !== self::EXPECTED_FEATS) {
            throw new RuntimeException("Artifact features count={$n}, expected " . self::EXPECTED_FEATS);
        }

        foreach (['imputer.values' => $art['imputer']['values'],
                  'scaler.mean'    => $art['scaler']['mean'],
                  'scaler.scale'   => $art['scaler']['scale'],
                  'home.coef'      => $art['home_model']['coefficients'],
                  'away.coef'      => $art['away_model']['coefficients']] as $label => $arr) {
            $c = count($arr);
            if ($c !== self::EXPECTED_FEATS) {
                throw new RuntimeException("Artifact {$label} count={$c}, expected " . self::EXPECTED_FEATS);
            }
        }

        foreach ($art['scaler']['scale'] as $i => $s) {
            if ($s <= 0.0) {
                throw new RuntimeException("Artifact scaler.scale[{$i}] <= 0 (value={$s})");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Preprocessing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,float|null>  $features
     * @return float[]  z-scores, indexed 0..58
     *
     * @throws InvalidArgumentException  if a feature key is missing entirely
     */
    private static function preprocess(array $features, array $art): array
    {
        $names    = $art['features'];
        $medians  = $art['imputer']['values'];
        $means    = $art['scaler']['mean'];
        $scales   = $art['scaler']['scale'];

        $z = [];
        foreach ($names as $i => $name) {
            if (! array_key_exists($name, $features)) {
                throw new InvalidArgumentException(
                    "PredictionEngineV1: required feature '{$name}' is missing from input."
                );
            }

            $raw = $features[$name];

            // null → median imputation
            $x = ($raw === null) ? (float) $medians[$i] : (float) $raw;

            // z-score
            $z[] = ($x - (float) $means[$i]) / (float) $scales[$i];
        }

        return $z;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Poisson GLM
    // ─────────────────────────────────────────────────────────────────────────

    /** @param float[] $coef  @param float[] $z */
    private static function poissonLambda(float $intercept, array $coef, array $z): float
    {
        $eta = $intercept;
        for ($i = 0; $i < self::EXPECTED_FEATS; $i++) {
            $eta += $coef[$i] * $z[$i];
        }

        $lambda = exp($eta);

        if (! is_finite($lambda) || $lambda <= 0.0) {
            throw new RuntimeException("PoissonGLM: non-positive or non-finite lambda={$lambda}");
        }

        return $lambda;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1X2 from Poisson PMF (mirrors Python P5C/P7A exactly)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return float[]  [P_H, P_D, P_A]
     */
    private static function lambdaTo1x2(float $lambdaHome, float $lambdaAway): array
    {
        $lambdaHome = max($lambdaHome, 1e-6);
        $lambdaAway = max($lambdaAway, 1e-6);

        $facts = self::getFactorials();

        // Precompute PMF vectors for home and away
        $pmfH = [];
        $pmfA = [];
        for ($k = 0; $k <= self::MAX_GOALS; $k++) {
            $pmfH[$k] = exp(-$lambdaHome) * ($lambdaHome ** $k) / $facts[$k];
            $pmfA[$k] = exp(-$lambdaAway) * ($lambdaAway ** $k) / $facts[$k];
        }

        $pH = 0.0;
        $pD = 0.0;
        $pA = 0.0;

        for ($i = 0; $i <= self::MAX_GOALS; $i++) {
            for ($j = 0; $j <= self::MAX_GOALS; $j++) {
                $p = $pmfH[$i] * $pmfA[$j];
                if ($i > $j) {
                    $pH += $p;
                } elseif ($i === $j) {
                    $pD += $p;
                } else {
                    $pA += $p;
                }
            }
        }

        $total = $pH + $pD + $pA;

        return [$pH / $total, $pD / $total, $pA / $total];
    }

    private static function getFactorials(): array
    {
        if (self::$factorials === []) {
            self::$factorials = [1];
            for ($k = 1; $k <= self::MAX_GOALS; $k++) {
                self::$factorials[$k] = self::$factorials[$k - 1] * $k;
            }
        }
        return self::$factorials;
    }
}
