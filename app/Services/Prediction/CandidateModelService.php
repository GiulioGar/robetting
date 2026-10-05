<?php

namespace App\Services\Prediction;

use Carbon\Carbon;
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
    private const CAND48_STRUCTURAL_FILE = 'prediction_engine_candidate48_structural.json';
    private const CAND47_STRUCTURAL_LOG_FILE = 'prediction_engine_candidate47_structural_log.json';
    private const LATENT_SNAPSHOT_FILE = 'latent_strength_current.json';
    private const STRUCTURAL_SNAPSHOT_FILE = 'structural_strength_current.json';

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
     * @param  int|null     $homeTeamId  Required (with $awayTeamId/$kickoffAt) to attempt Candidate48 Structural.
     * @param  int|null     $awayTeamId
     * @param  string|null  $kickoffAt   ISO8601/parseable match kickoff, for the anti-leakage check.
     * @return array{
     *     full59: array,
     *     no_e9: array|null,
     *     no_e9_no_e10: array|null,
     *     candidate40: array|null,
     *     candidate40_robust_bp: array|null,
     *     candidate48_structural: array|null,
     *     candidate48_structural_inputs: array{structural_home: float, structural_away: float, structural_gap: float}|null,
     *     candidate47_structural_log: array|null,
     *     candidate47_structural_log_inputs: array{structural_home: float, structural_away: float, structural_gap: float}|null,
     *     no_e9_available: bool,
     *     no_e10_available: bool,
     *     candidate40_available: bool,
     *     candidate40_robust_bp_available: bool,
     *     candidate48_structural_available: bool,
     *     candidate47_structural_log_available: bool,
     * }
     */
    public function compare(
        array $features59,
        ?int $homeTeamId = null,
        ?int $awayTeamId = null,
        ?string $kickoffAt = null,
    ): array {
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

        // Latent + Structural snapshots are resolved once and shared by
        // Candidate48 (RAW, kept for rollback/debug) and Candidate47 (LOG).
        $latent = null;
        $structural = null;
        if ($homeTeamId !== null && $awayTeamId !== null && $kickoffAt !== null) {
            try {
                $latent     = $this->resolveLatentFeatures($this->loadLatentSnapshot(), $homeTeamId, $awayTeamId, $kickoffAt);
                $structural = $this->resolveStructuralFeatures($this->loadStructuralSnapshot(), $homeTeamId, $awayTeamId, $kickoffAt);
            } catch (RuntimeException) {
                $latent = $structural = null;
            }
        }
        $structuralInputs = ($latent !== null && $structural !== null) ? [
            'structural_home' => $structural['structural_home'],
            'structural_away' => $structural['structural_away'],
            'structural_gap'  => $structural['structural_gap'],
        ] : null;

        $cand48Structural = null;
        $cand48StructuralAvailable = false;
        $cand48StructuralInputs = null;
        if ($structuralInputs !== null) {
            try {
                $ext48 = array_merge($extFeatures, $latent, $structural);
                $artCand48 = $this->loadArtifact(self::CAND48_STRUCTURAL_FILE);
                $cand48Structural = $this->inferRobustBP($artCand48, $ext48);
                $cand48StructuralAvailable = true;
                $cand48StructuralInputs = $structuralInputs;
            } catch (RuntimeException) {
            }
        }

        $cand47Log = null;
        $cand47LogAvailable = false;
        $cand47LogInputs = null;
        if ($structuralInputs !== null) {
            $logFeatures = self::structuralLogFeatures($structural['structural_home'], $structural['structural_away']);
            if ($logFeatures !== null) {
                try {
                    $ext47 = array_merge($extFeatures, $latent, $logFeatures);
                    $artCand47 = $this->loadArtifact(self::CAND47_STRUCTURAL_LOG_FILE);
                    $cand47Log = $this->inferRobustBP($artCand47, $ext47);
                    $cand47LogAvailable = true;
                    $cand47LogInputs = $structuralInputs;
                } catch (RuntimeException) {
                }
            }
        }

        return [
            'full59'                          => $full59,
            'no_e9'                           => $noE9,
            'no_e9_no_e10'                    => $noE10,
            'candidate40'                     => $cand40,
            'candidate40_robust_bp'           => $cand40RobustBp,
            'candidate48_structural'          => $cand48Structural,
            'candidate48_structural_inputs'   => $cand48StructuralInputs,
            'candidate47_structural_log'        => $cand47Log,
            'candidate47_structural_log_inputs' => $cand47LogInputs,
            'no_e9_available'                 => $noE9Available,
            'no_e10_available'                => $noE10Available,
            'candidate40_available'           => $cand40Available,
            'candidate40_robust_bp_available' => $cand40RobustBpAvailable,
            'candidate48_structural_available' => $cand48StructuralAvailable,
            'candidate47_structural_log_available' => $cand47LogAvailable,
        ];
    }

    /**
     * P26B — build the exact audit payload needed to persist an OFFICIAL
     * Candidate V2 LOG (candidate47_structural_log) prediction.
     *
     * Runs the IDENTICAL runtime pipeline as the candidate47_structural_log
     * branch of compare() (same addDerivedFeatures(), same snapshot loading/
     * resolution, same structuralLogFeatures(), same inferRobustBP() math) —
     * nothing here duplicates or re-derives the model. The only difference
     * from compare() is that this method also surfaces metadata compare()
     * intentionally never exposes (model_version, feature_set_version,
     * artifact_path, the exact 47-feature vector, and both snapshots'
     * generated_at), because compare() must stay lean for the normal
     * comparison UI (see class docblock) and only the explicit "save
     * official" action needs this audit-grade detail.
     *
     * @param  array<string, float|null>  $features59  Output of PredictionEngineV1 extraction
     * @return array{
     *   model_key: string,
     *   model_version: string,
     *   feature_set_version: string,
     *   artifact_path: string,
     *   lambda_home: float,
     *   lambda_away: float,
     *   lambda3: float,
     *   probability_home: float,
     *   probability_draw: float,
     *   probability_away: float,
     *   features_json: array<string, float|null>,
     *   structural_snapshot_generated_at: string,
     *   latent_snapshot_generated_at: string,
     * }|null  null when Candidate V2 LOG is unavailable for this match (same
     *         anti-leakage/availability gate compare() uses for candidate47:
     *         missing snapshot, team not in snapshot, snapshot not strictly
     *         before kickoff, or non-positive TOP25 value)
     */
    public function officialPredictionData(
        array $features59,
        int $homeTeamId,
        int $awayTeamId,
        string $kickoffAt,
    ): ?array {
        $extFeatures = $this->addDerivedFeatures($features59);

        try {
            $latentSnapshot     = $this->loadLatentSnapshot();
            $structuralSnapshot = $this->loadStructuralSnapshot();
        } catch (RuntimeException) {
            return null;
        }

        $latent     = $this->resolveLatentFeatures($latentSnapshot, $homeTeamId, $awayTeamId, $kickoffAt);
        $structural = $this->resolveStructuralFeatures($structuralSnapshot, $homeTeamId, $awayTeamId, $kickoffAt);
        if ($latent === null || $structural === null) {
            return null;
        }

        $logFeatures = self::structuralLogFeatures($structural['structural_home'], $structural['structural_away']);
        if ($logFeatures === null) {
            return null;
        }

        $ext47 = array_merge($extFeatures, $latent, $logFeatures);

        try {
            $artifact = $this->loadArtifact(self::CAND47_STRUCTURAL_LOG_FILE);
        } catch (RuntimeException) {
            return null;
        }

        $inference = $this->inferRobustBP($artifact, $ext47);

        // Persist exactly the 47 features the artifact actually consumes (in
        // its own declared order) — not the wider merged superset, which
        // also carries baseline keys the model never looks at.
        $usedFeatures = [];
        foreach ($artifact['features'] as $name) {
            $usedFeatures[$name] = $ext47[$name] ?? null;
        }

        return [
            'model_key'                        => 'candidate47_structural_log',
            'model_version'                     => (string) $artifact['model_version'],
            'feature_set_version'               => (string) $artifact['feature_set_version'],
            'artifact_path'                      => $this->artifactPathFor(self::CAND47_STRUCTURAL_LOG_FILE),
            'lambda_home'                       => $inference['lambda_home'],
            'lambda_away'                       => $inference['lambda_away'],
            'lambda3'                           => $inference['lambda3'],
            'probability_home'                  => $inference['probability_home'],
            'probability_draw'                  => $inference['probability_draw'],
            'probability_away'                  => $inference['probability_away'],
            'features_json'                     => $usedFeatures,
            'structural_snapshot_generated_at'  => (string) $structuralSnapshot['generated_at'],
            'latent_snapshot_generated_at'      => (string) $latentSnapshot['generated_at'],
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
    // Latent strength snapshot (input of Candidate48 Structural)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{generated_at: string, last_match_included_at: string, teams: array} */
    private function loadLatentSnapshot(): array
    {
        return $this->loadArtifact(self::LATENT_SNAPSHOT_FILE);
    }

    /**
     * Resolve the 4 latent attack/defence features for a match, enforcing
     * anti-leakage: the snapshot must have been generated strictly BEFORE
     * the match kickoff, and both teams must be present in it. Returns null
     * (Candidate48 unavailable) otherwise — never a stale/wrong value.
     *
     * @return array{latent_attack_home: float, latent_defence_home: float, latent_attack_away: float, latent_defence_away: float}|null
     */
    private function resolveLatentFeatures(array $snapshot, int $homeTeamId, int $awayTeamId, string $kickoffAt): ?array
    {
        $generatedAt = Carbon::parse($snapshot['generated_at']);
        $kickoff     = Carbon::parse($kickoffAt);

        if (! $generatedAt->lt($kickoff)) {
            return null; // snapshot not strictly before kickoff -> unusable, never leak
        }

        $byTeam = [];
        foreach ($snapshot['teams'] as $team) {
            $byTeam[(int) $team['team_id']] = $team;
        }

        if (! isset($byTeam[$homeTeamId], $byTeam[$awayTeamId])) {
            return null; // one or both teams missing from the snapshot
        }

        return [
            'latent_attack_home'  => (float) $byTeam[$homeTeamId]['attack'],
            'latent_defence_home' => (float) $byTeam[$homeTeamId]['defence'],
            'latent_attack_away'  => (float) $byTeam[$awayTeamId]['attack'],
            'latent_defence_away' => (float) $byTeam[$awayTeamId]['defence'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Structural strength snapshot (Candidate48 — "ROBETTING CANDIDATE V2")
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{generated_at: string, definition: string, teams: array<string, array{transfermarkt_club_id: int, team_name: string, players_count: int, top25_market_value: int}>}
     *
     * Produced by tools/scripts/p16d_generate_structural_snapshot.py (P16D),
     * which extends the P16C pilot to every current-season team with a safe
     * P16A team_id -> Transfermarkt club_id mapping. `teams` is keyed by
     * Robetting team_id (string, per the JSON object schema). Teams without
     * a safe mapping are simply absent — never guessed, never backfilled —
     * so a match involving one of them resolves to Candidate48 unavailable
     * below, exactly like a missing snapshot file.
     */
    private function loadStructuralSnapshot(): array
    {
        return $this->loadArtifact(self::STRUCTURAL_SNAPSHOT_FILE);
    }

    /**
     * Resolve the 4 Structural (TOP25 market value) features for a match,
     * enforcing the same anti-leakage rule as resolveLatentFeatures(): the
     * snapshot must have been generated strictly BEFORE kickoff, and both
     * teams must be present in it. Returns null (Candidate48 unavailable)
     * otherwise — never a stale/wrong value.
     *
     * @return array{structural_home: float, structural_away: float, structural_gap: float, structural_gap_signed_square: float}|null
     */
    private function resolveStructuralFeatures(array $snapshot, int $homeTeamId, int $awayTeamId, string $kickoffAt): ?array
    {
        $generatedAt = Carbon::parse($snapshot['generated_at']);
        $kickoff     = Carbon::parse($kickoffAt);

        if (! $generatedAt->lt($kickoff)) {
            return null; // snapshot not strictly before kickoff -> unusable, never leak
        }

        $teams = $snapshot['teams'] ?? [];

        if (! isset($teams[(string) $homeTeamId], $teams[(string) $awayTeamId])) {
            return null; // one or both teams missing from the snapshot
        }

        $structHome = (float) $teams[(string) $homeTeamId]['top25_market_value'];
        $structAway = (float) $teams[(string) $awayTeamId]['top25_market_value'];
        $gap        = $structHome - $structAway;

        return [
            'structural_home'              => $structHome,
            'structural_away'              => $structAway,
            'structural_gap'               => $gap,
            'structural_gap_signed_square' => ($gap >= 0.0 ? 1.0 : -1.0) * $gap * $gap,
        ];
    }

    /**
     * Candidate47 LOG structural features — the exact transformation used in
     * training (generate_candidate47_structural_log_artifact.py).
     * Returns null when either TOP25 value is <= 0 (log undefined → LOG unavailable).
     *
     * @return array{structural_log_home: float, structural_log_away: float, structural_log_ratio: float}|null
     */
    public static function structuralLogFeatures(float $top25Home, float $top25Away): ?array
    {
        if ($top25Home <= 0.0 || $top25Away <= 0.0) {
            return null;
        }

        return [
            'structural_log_home'  => log($top25Home),
            'structural_log_away'  => log($top25Away),
            'structural_log_ratio' => log($top25Home / $top25Away),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Artifact loading
    // ─────────────────────────────────────────────────────────────────────────

    private function artifactPathFor(string $filename): string
    {
        $dir = self::$artifactDir ?? base_path('tools/models');
        return $dir . DIRECTORY_SEPARATOR . $filename;
    }

    private function loadArtifact(string $filename): array
    {
        if (isset(self::$cache[$filename])) {
            return self::$cache[$filename];
        }

        $path = $this->artifactPathFor($filename);

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
