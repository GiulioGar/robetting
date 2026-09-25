<?php

namespace App\Services\Prediction;

use RuntimeException;

/**
 * Runs inference for candidate ablation models (NO_E9 51-feat, NO_E9+NO_E10 39-feat)
 * alongside the production FULL 59 engine, for admin comparison only.
 *
 * No DB. No HTTP. No persistence.
 * Pipeline: subset extraction → median imputation → StandardScaler → dual Poisson GLM → 1X2.
 */
class CandidateModelService
{
    private const MAX_GOALS           = 10;
    private const NO_E9_FILE          = 'prediction_engine_no_e9.json';
    private const NO_E10_FILE         = 'prediction_engine_no_e9_no_e10.json';
    private const CAND40_FILE         = 'prediction_engine_candidate40.json';
    private const CAND40_ROBUST_BP_FILE = 'prediction_engine_candidate40_robust_bp.json';

    private static ?string $artifactDir = null;
    private static array   $factorials  = [];

    /** Override artifact directory (for unit tests without Laravel container). */
    public static function setArtifactDir(string $dir): void
    {
        self::$artifactDir = rtrim($dir, '/\\');
        self::$cache       = [];
    }

    /** Reset overrides (call in tearDownAfterClass). */
    public static function reset(): void
    {
        self::$artifactDir = null;
        self::$cache       = [];
        self::$factorials  = [];
    }

    /** Per-request artifact cache. */
    private static array $cache = [];

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run all candidate models on the given 59-feature snapshot.
     *
     * @param  array<string, float|null>  $features59  Output of PredictionEngineV1 extraction
     * @return array{
     *     full59: array,
     *     no_e9: array|null,
     *     no_e9_no_e10: array|null,
     *     candidate40: array|null,
     *     candidate40_robust_bp: array|null,
     *     no_e9_available: bool,
     *     no_e10_available: bool,
     *     candidate40_available: bool,
     *     candidate40_robust_bp_available: bool,
     * }
     */
    public function compare(array $features59): array
    {
        // Extended feature vector: features59 + derived features
        $extFeatures = $this->addDerivedFeatures($features59);

        $full59 = PredictionEngineV1::predict($features59);

        $noE9 = null;
        $noE9Available = false;
        try {
            $artNoE9 = $this->loadArtifact(self::NO_E9_FILE);
            $noE9 = $this->infer($artNoE9, $extFeatures);
            $noE9Available = true;
        } catch (RuntimeException) {
        }

        $noE10 = null;
        $noE10Available = false;
        try {
            $artNoE10 = $this->loadArtifact(self::NO_E10_FILE);
            $noE10 = $this->infer($artNoE10, $extFeatures);
            $noE10Available = true;
        } catch (RuntimeException) {
        }

        $cand40 = null;
        $cand40Available = false;
        try {
            $artCand40 = $this->loadArtifact(self::CAND40_FILE);
            $cand40 = $this->infer($artCand40, $extFeatures);
            $cand40Available = true;
        } catch (RuntimeException) {
        }

        $cand40RobustBp = null;
        $cand40RobustBpAvailable = false;
        try {
            $artRobustBp = $this->loadArtifact(self::CAND40_ROBUST_BP_FILE);
            $cand40RobustBp = $this->inferRobustBP($artRobustBp, $extFeatures);
            $cand40RobustBpAvailable = true;
        } catch (RuntimeException) {
        }

        return [
            'full59'                          => $full59,
            'no_e9'                           => $noE9,
            'no_e9_no_e10'                    => $noE10,
            'candidate40'                     => $cand40,
            'candidate40_robust_bp'           => $cand40RobustBp,
            'no_e9_available'                 => $noE9Available,
            'no_e10_available'                => $noE10Available,
            'candidate40_available'           => $cand40Available,
            'candidate40_robust_bp_available' => $cand40RobustBpAvailable,
        ];
    }

    /**
     * Append derived features to the base feature vector.
     * Currently adds: elo_gap_signed_square = copysign((elo_h - elo_a)^2, elo_h - elo_a)
     *
     * @param  array<string, float|null>  $features59
     * @return array<string, float|null>
     */
    private function addDerivedFeatures(array $features59): array
    {
        $ext  = $features59;
        $eloH = isset($features59['core_elo_home_pre_match_elo'])
            ? (float) $features59['core_elo_home_pre_match_elo']
            : null;
        $eloA = isset($features59['core_elo_away_pre_match_elo'])
            ? (float) $features59['core_elo_away_pre_match_elo']
            : null;

        if ($eloH !== null && $eloA !== null) {
            $gap = $eloH - $eloA;
            $ext['elo_gap_signed_square'] = ($gap >= 0.0 ? 1.0 : -1.0) * $gap * $gap;
        } else {
            $ext['elo_gap_signed_square'] = null; // median imputed during infer()
        }

        return $ext;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Inference
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, float|null> $features59 */
    private function infer(array $artifact, array $features59): array
    {
        $names   = $artifact['features'];
        $medians = $artifact['imputer']['values'];
        $means   = $artifact['scaler']['mean'];
        $scales  = $artifact['scaler']['scale'];
        $n       = count($names);

        $z = [];
        foreach ($names as $i => $name) {
            $raw   = $features59[$name] ?? null;
            $x     = ($raw === null) ? (float) $medians[$i] : (float) $raw;
            $z[$i] = ($x - (float) $means[$i]) / (float) $scales[$i];
        }

        $etaH = $artifact['home_model']['intercept'];
        $coefH = $artifact['home_model']['coefficients'];
        for ($i = 0; $i < $n; $i++) {
            $etaH += $coefH[$i] * $z[$i];
        }
        $lambdaH = exp($etaH);

        $etaA = $artifact['away_model']['intercept'];
        $coefA = $artifact['away_model']['coefficients'];
        for ($i = 0; $i < $n; $i++) {
            $etaA += $coefA[$i] * $z[$i];
        }
        $lambdaA = exp($etaA);

        [$pH, $pD, $pA] = $this->lambdaTo1x2($lambdaH, $lambdaA);

        return [
            'lambda_home'      => $lambdaH,
            'lambda_away'      => $lambdaA,
            'probability_home' => $pH,
            'probability_draw' => $pD,
            'probability_away' => $pA,
        ];
    }

    /**
     * Inference with MISSING30 gate + Bivariate Poisson score matrix.
     * rest_days > missing30_threshold is treated as null (median-imputed).
     *
     * @param array<string, float|null> $features59
     */
    private function inferRobustBP(array $artifact, array $features59): array
    {
        $names     = $artifact['features'];
        $medians   = $artifact['imputer']['values'];
        $means     = $artifact['scaler']['mean'];
        $scales    = $artifact['scaler']['scale'];
        $n         = count($names);
        $lambda3   = (float) $artifact['lambda3'];
        $threshold = (float) ($artifact['missing30_threshold'] ?? 30.0);
        $missing30 = $artifact['missing30_features'] ?? [];

        $z = [];
        foreach ($names as $i => $name) {
            $raw = $features59[$name] ?? null;
            // MISSING30 gate: OOD rest_days treated as missing before imputation
            if ($raw !== null && in_array($name, $missing30, true) && (float) $raw > $threshold) {
                $raw = null;
            }
            $x     = ($raw === null) ? (float) $medians[$i] : (float) $raw;
            $z[$i] = ($x - (float) $means[$i]) / (float) $scales[$i];
        }

        $etaH  = $artifact['home_model']['intercept'];
        $coefH = $artifact['home_model']['coefficients'];
        for ($i = 0; $i < $n; $i++) {
            $etaH += $coefH[$i] * $z[$i];
        }
        $lambdaH = exp($etaH);

        $etaA  = $artifact['away_model']['intercept'];
        $coefA = $artifact['away_model']['coefficients'];
        for ($i = 0; $i < $n; $i++) {
            $etaA += $coefA[$i] * $z[$i];
        }
        $lambdaA = exp($etaA);

        [$pH, $pD, $pA] = $this->lambdaToBivariate1x2($lambdaH, $lambdaA, $lambda3);

        return [
            'lambda_home'      => $lambdaH,
            'lambda_away'      => $lambdaA,
            'lambda3'          => $lambda3,
            'probability_home' => $pH,
            'probability_draw' => $pD,
            'probability_away' => $pA,
        ];
    }

    /**
     * Bivariate Poisson score matrix (Karlis & Ntzoufras 2003).
     *
     * lambda1 = lambdaHome - lambda3
     * lambda2 = lambdaAway - lambda3
     * P(x,y) = exp(-(l1+l2+l3)) * (l1^x/x!) * (l2^y/y!)
     *          * sum_{k=0..min(x,y)} C(x,k)*C(y,k)*k! * (l3/(l1*l2))^k
     *
     * Computed in log-space for numerical stability.
     */
    private function lambdaToBivariate1x2(float $lambdaHome, float $lambdaAway, float $lambda3): array
    {
        $lambdaHome = max($lambdaHome, 1e-6);
        $lambdaAway = max($lambdaAway, 1e-6);
        // Safety cap: lambda3 must be strictly < min(lambda1, lambda2)
        $lambda3 = min($lambda3, min($lambdaHome, $lambdaAway) * 0.9999);

        $lambda1 = $lambdaHome - $lambda3;
        $lambda2 = $lambdaAway - $lambda3;

        // Log-factorials 0..MAX_GOALS
        $logFact = [0.0];
        for ($k = 1; $k <= self::MAX_GOALS; $k++) {
            $logFact[$k] = $logFact[$k - 1] + log($k);
        }

        $logRatio = log($lambda3) - log($lambda1) - log($lambda2);
        $constTerm = -($lambda1 + $lambda2 + $lambda3);

        $pH = $pD = $pA = 0.0;

        for ($x = 0; $x <= self::MAX_GOALS; $x++) {
            $logPX = $x > 0 ? $x * log($lambda1) - $logFact[$x] : -$logFact[0];
            for ($y = 0; $y <= self::MAX_GOALS; $y++) {
                $logPY = $y > 0 ? $y * log($lambda2) - $logFact[$y] : -$logFact[0];

                // Inner sum: k=0 term = 1, k>0 terms in log-space
                $innerSum = 1.0;
                $minXY = min($x, $y);
                for ($k = 1; $k <= $minXY; $k++) {
                    $logTerm = ($logFact[$x] - $logFact[$k] - $logFact[$x - $k])
                             + ($logFact[$y] - $logFact[$k] - $logFact[$y - $k])
                             + $logFact[$k]
                             + $k * $logRatio;
                    $innerSum += exp($logTerm);
                }

                $p = exp($constTerm + $logPX + $logPY) * $innerSum;

                if ($x > $y) {
                    $pH += $p;
                } elseif ($x === $y) {
                    $pD += $p;
                } else {
                    $pA += $p;
                }
            }
        }

        $total = $pH + $pD + $pA;
        if ($total < 1e-12) {
            return [1 / 3, 1 / 3, 1 / 3];
        }
        return [$pH / $total, $pD / $total, $pA / $total];
    }

    /** Identical math to PredictionEngineV1::lambdaTo1x2. */
    private function lambdaTo1x2(float $lambdaHome, float $lambdaAway): array
    {
        $lambdaHome = max($lambdaHome, 1e-6);
        $lambdaAway = max($lambdaAway, 1e-6);

        $facts = $this->getFactorials();

        $pmfH = [];
        $pmfA = [];
        for ($k = 0; $k <= self::MAX_GOALS; $k++) {
            $pmfH[$k] = exp(-$lambdaHome) * ($lambdaHome ** $k) / $facts[$k];
            $pmfA[$k] = exp(-$lambdaAway) * ($lambdaAway ** $k) / $facts[$k];
        }

        $pH = $pD = $pA = 0.0;
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

    private function getFactorials(): array
    {
        if (self::$factorials === []) {
            self::$factorials = [1];
            for ($k = 1; $k <= self::MAX_GOALS; $k++) {
                self::$factorials[$k] = self::$factorials[$k - 1] * $k;
            }
        }
        return self::$factorials;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Artifact loading
    // ─────────────────────────────────────────────────────────────────────────

    private function loadArtifact(string $filename): array
    {
        if (isset(self::$cache[$filename])) {
            return self::$cache[$filename];
        }

        $dir  = self::$artifactDir ?? base_path('tools/models');
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (! file_exists($path)) {
            throw new RuntimeException("Candidate artifact not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Cannot read candidate artifact: {$path}");
        }

        $art = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::$cache[$filename] = $art;
        return $art;
    }
}
