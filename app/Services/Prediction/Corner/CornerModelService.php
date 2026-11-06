<?php

namespace App\Services\Prediction\Corner;

use App\Models\FootballMatch;
use RuntimeException;

/**
 * CORNER model inference (EXPERIMENTAL, not wired to any page or stored prediction).
 *
 * Reads the versioned artifact tools/models/corner_nb_copula_v1.json (no fitting at request time):
 * median imputation -> standardisation -> NB2 means for home / away -> joint pmf with a Gaussian copula -> total pmf and Over/Under.
 * Available only for the five core leagues and only when both teams have corner data in their last-10 window;
 * otherwise it returns available=false with a reason and NO probabilities.
 */
class CornerModelService
{
    public const ARTIFACT_FILE = 'corner_nb_copula_v1.json';

    /** @var array<string, array> */
    private static array $artifacts = [];

    public function __construct(private readonly ?string $artifactPath = null)
    {
    }

    /** @return array<string, mixed> */
    public function predictForMatch(FootballMatch $match): array
    {
        $slug = (string) $match->competition?->slug;
        if (! in_array($slug, array_values((array) config('api-football.core_leagues', [])), true)) {
            return ['available' => false, 'reason' => 'competition_not_supported'];
        }
        if ($match->kickoff_at === null) {
            return ['available' => false, 'reason' => 'no_kickoff'];
        }
        try {
            $this->artifact();
        } catch (RuntimeException) {
            return ['available' => false, 'reason' => 'artifact_unavailable'];
        }

        $features = CornerFeatureBuilder::forMatch($match);
        if (($features['h_n10'] ?? 0) < 1 || ($features['a_n10'] ?? 0) < 1) {
            return ['available' => false, 'reason' => 'insufficient_corner_history', 'features' => $features];
        }

        return $this->predictFromFeatures($features);
    }

    /**
     * Pure inference from a feature vector keyed by the artifact feature names (null = missing -> artifact median).
     *
     * @param  array<string, float|int|null>  $features
     * @return array<string, mixed>
     */
    public function predictFromFeatures(array $features): array
    {
        $art = $this->artifact();
        $names = $art['features'];
        $z = [];
        foreach ($names as $i => $name) {
            $raw = $features[$name] ?? null;
            $value = $raw === null ? (float) $art['imputer']['values'][$i] : (float) $raw;
            $z[$i] = ($value - (float) $art['scaler']['mean'][$i]) / (float) $art['scaler']['scale'][$i];
        }
        $muH = self::mean($art['home_model'], $z);
        $muA = self::mean($art['away_model'], $z);
        $phiH = (float) $art['home_model']['dispersion'];
        $phiA = (float) $art['away_model']['dispersion'];
        $k = (int) $art['copula']['max_corners_per_side'];
        $g = $art['copula']['grid'];

        [$P, $mass] = CornerMath::jointPmf($muH, $phiH, $muA, $phiA, (float) $art['copula']['rho'], $k, (float) $g['x_lo'], (float) $g['x_hi'], (float) $g['dx']);
        $total = CornerMath::totalPmf($P);
        $over = CornerMath::overProbs($total, $art['thresholds']);
        [$tMean, $tVar] = CornerMath::moments($total);

        $overOut = [];
        $underOut = [];
        foreach ($art['thresholds'] as $i => $t) {
            $overOut[(string) $t] = $over[$i];
            $underOut[(string) $t] = 1.0 - $over[$i];
        }

        return [
            'available' => true,
            'model_key' => $art['model_key'],
            'model_version' => $art['model_version'],
            'features' => array_intersect_key($features, array_flip($names)),
            'mu_home' => $muH,
            'mu_away' => $muA,
            'expected_total' => $muH + $muA,
            'home_pmf' => CornerMath::nbPmf($muH, $phiH, $k),
            'away_pmf' => CornerMath::nbPmf($muA, $phiA, $k),
            'total_pmf' => $total,
            'total_mean' => $tMean,
            'total_variance' => $tVar,
            'over' => $overOut,
            'under' => $underOut,
            'copula_rho' => (float) $art['copula']['rho'],
            'truncated_mass' => 1.0 - $mass,
        ];
    }

    private static function mean(array $model, array $z): float
    {
        $eta = (float) $model['intercept'];
        foreach ($model['coefficients'] as $i => $c) {
            $eta += (float) $c * $z[$i];
        }

        return exp($eta);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = $this->artifactPath ?? base_path('tools/models/' . self::ARTIFACT_FILE);
        if (isset(self::$artifacts[$path])) {
            return self::$artifacts[$path];
        }
        if (! is_file($path)) {
            throw new RuntimeException("CORNER artifact not found: {$path}");
        }
        $art = json_decode((string) file_get_contents($path), true);
        foreach (['features', 'imputer', 'scaler', 'home_model', 'away_model', 'copula', 'thresholds', 'model_version', 'model_key'] as $key) {
            if (! is_array($art) || ! array_key_exists($key, $art)) {
                throw new RuntimeException("CORNER artifact invalid: missing '{$key}'");
            }
        }

        return self::$artifacts[$path] = $art;
    }
}
