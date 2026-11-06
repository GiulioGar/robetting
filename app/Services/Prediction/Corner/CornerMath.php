<?php

namespace App\Services\Prediction\Corner;

/**
 * Numerics of the CORNER model — a line-by-line port of tools/scripts/corner_joint.py (Step 84):
 * negative-binomial (NB2) marginals and a Gaussian copula on discrete counts, integrated with the same fixed trapezoid grid.
 * Pure functions, no I/O. The golden fixture tests/Fixtures/corner_golden.json (Python outputs) pins the numerical parity.
 */
final class CornerMath
{
    private const SQRT2 = 1.4142135623730951;
    private const INV_SQRT_PI = 0.5641895835477563;
    private const SQRT_2PI = 2.5066282746310002;

    /** @var array{x: float[], phi: float[]}|null */
    private static ?array $grid = null;

    /** Standard normal CDF: erf power series for |x| < 3.54 (no cancellation), Laplace continued fraction in the tails. */
    public static function ndtr(float $x): float
    {
        if (is_nan($x)) {
            return NAN;
        }
        if ($x >= 8.3) {
            return 1.0;
        }
        if ($x <= -37.5) {
            return 0.0;
        }
        $z = abs($x) / self::SQRT2;
        if ($z < 2.5) {
            $z2 = $z * $z;
            $term = $z;
            $sum = $z;
            for ($n = 0; $n < 200; $n++) {
                $term *= 2.0 * $z2 / (2 * $n + 3);
                $sum += $term;
                if ($term < 1e-17 * $sum) {
                    break;
                }
            }
            $erf = 2.0 * self::INV_SQRT_PI * exp(-$z2) * $sum;

            return $x >= 0 ? 0.5 * (1.0 + $erf) : 0.5 * (1.0 - $erf);
        }
        $f = $z;
        for ($k = 120; $k >= 1; $k--) {
            $f = $z + ($k / 2.0) / $f;
        }
        $tail = 0.5 * exp(-$z * $z) * self::INV_SQRT_PI / $f;

        return $x >= 0 ? 1.0 - $tail : $tail;
    }

    /** Inverse normal CDF (Acklam's rational approximation + two Halley refinements). 0 -> -INF, 1 -> +INF. */
    public static function ndtri(float $p): float
    {
        if ($p <= 0.0) {
            return -INF;
        }
        if ($p >= 1.0) {
            return INF;
        }
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $plow = 0.02425;

        if ($p < $plow) {
            $q = sqrt(-2.0 * log($p));
            $x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        } elseif ($p <= 1.0 - $plow) {
            $q = $p - 0.5;
            $r = $q * $q;
            $x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
        } else {
            $q = sqrt(-2.0 * log(1.0 - $p));
            $x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }
        for ($i = 0; $i < 2; $i++) {
            $e = self::ndtr($x) - $p;
            $u = $e * self::SQRT_2PI * exp($x * $x / 2.0);
            $x -= $u / (1.0 + $x * $u / 2.0);
        }

        return $x;
    }

    /** NB2 pmf for 0..$k: mean $mu, variance mu + phi mu^2. */
    public static function nbPmf(float $mu, float $phi, int $k): array
    {
        $r = 1.0 / $phi;
        $q = $r / ($r + $mu);
        $pmf = [exp($r * log($q))];
        for ($j = 0; $j < $k; $j++) {
            $pmf[] = $pmf[$j] * ($j + $r) / ($j + 1) * (1.0 - $q);
        }

        return $pmf;
    }

    /**
     * Joint pmf of (home, away) corners, (k+1)x(k+1), renormalised, plus the truncated mass kept before renormalising.
     * P(i,j) = C(Fh(i),Fa(j)) - C(Fh(i-1),Fa(j)) - C(Fh(i),Fa(j-1)) + C(Fh(i-1),Fa(j-1)), C = Gaussian copula with parameter $rho
     * (rho = 0 is independence).
     *
     * @return array{0: float[][], 1: float}
     */
    public static function jointPmf(float $muH, float $phiH, float $muA, float $phiA, float $rho, int $k, float $xLo = -8.0, float $xHi = 8.0, float $dx = 0.005): array
    {
        $uH = self::cdfWithZero(self::nbPmf($muH, $phiH, $k));
        $uA = self::cdfWithZero(self::nbPmf($muA, $phiA, $k));
        $a = array_map([self::class, 'ndtri'], $uH);
        $b = array_map([self::class, 'ndtri'], $uA);

        $grid = self::grid($xLo, $xHi, $dx);
        $nx = count($grid['x']);
        $s = sqrt(1.0 - $rho * $rho);

        // cum[j][t] = cumulative trapezoid of phi(x) * Phi((b_j - rho x)/s)
        $cum = [];
        foreach ($b as $j => $bj) {
            $row = [0.0];
            $prev = $grid['phi'][0] * self::ndtr(($bj - $rho * $grid['x'][0]) / $s);
            for ($t = 1; $t < $nx; $t++) {
                $cur = $grid['phi'][$t] * self::ndtr(($bj - $rho * $grid['x'][$t]) / $s);
                $row[$t] = $row[$t - 1] + ($prev + $cur) / 2.0 * $dx;
                $prev = $cur;
            }
            $cum[$j] = $row;
        }

        $i0 = [];
        $fr = [];
        foreach ($a as $i => $ai) {
            $pos = (min(max($ai, $xLo), $xHi) - $xLo) / $dx;
            $idx = min((int) floor($pos), $nx - 2);
            $i0[$i] = $idx;
            $fr[$i] = $pos - $idx;
        }
        $C = [];
        for ($i = 0; $i <= $k + 1; $i++) {
            for ($j = 0; $j <= $k + 1; $j++) {
                $C[$i][$j] = $cum[$j][$i0[$i]] * (1.0 - $fr[$i]) + $cum[$j][$i0[$i] + 1] * $fr[$i];
            }
        }
        $P = [];
        $mass = 0.0;
        for ($i = 0; $i <= $k; $i++) {
            for ($j = 0; $j <= $k; $j++) {
                $v = max($C[$i + 1][$j + 1] - $C[$i][$j + 1] - $C[$i + 1][$j] + $C[$i][$j], 0.0);
                $P[$i][$j] = $v;
                $mass += $v;
            }
        }
        foreach ($P as $i => $row) {
            foreach ($row as $j => $v) {
                $P[$i][$j] = $v / $mass;
            }
        }

        return [$P, $mass];
    }

    /** @param float[][] $P @return float[] pmf of home + away corners (index = total) */
    public static function totalPmf(array $P): array
    {
        $k = count($P);
        $out = array_fill(0, 2 * $k - 1, 0.0);
        foreach ($P as $i => $row) {
            foreach ($row as $j => $v) {
                $out[$i + $j] += $v;
            }
        }

        return $out;
    }

    /** P(total > t) for each threshold t (x.5 lines): 1 - cdf[floor(t)]. */
    public static function overProbs(array $totalPmf, array $thresholds): array
    {
        $cdf = [];
        $acc = 0.0;
        foreach ($totalPmf as $v) {
            $acc += $v;
            $cdf[] = $acc;
        }
        $out = [];
        foreach ($thresholds as $t) {
            $out[] = 1.0 - $cdf[(int) floor($t)];
        }

        return $out;
    }

    /** @return array{0: float, 1: float} mean and variance of the total pmf */
    public static function moments(array $totalPmf): array
    {
        $m = 0.0;
        foreach ($totalPmf as $t => $v) {
            $m += $t * $v;
        }
        $var = 0.0;
        foreach ($totalPmf as $t => $v) {
            $var += ($t - $m) ** 2 * $v;
        }

        return [$m, $var];
    }

    /** @return float[] [0, F(0), ..., F(k)] — F(-1) = 0 */
    private static function cdfWithZero(array $pmf): array
    {
        $out = [0.0];
        $acc = 0.0;
        foreach ($pmf as $v) {
            $acc += $v;
            $out[] = min($acc, 1.0);
        }

        return $out;
    }

    private static function grid(float $xLo, float $xHi, float $dx): array
    {
        if (self::$grid === null || self::$grid['key'] !== "$xLo|$xHi|$dx") {
            $n = (int) round(($xHi - $xLo) / $dx) + 1;
            $x = [];
            $phi = [];
            for ($t = 0; $t < $n; $t++) {
                $x[$t] = $xLo + $t * $dx;
                $phi[$t] = exp(-0.5 * $x[$t] * $x[$t]) / self::SQRT_2PI;
            }
            self::$grid = ['key' => "$xLo|$xHi|$dx", 'x' => $x, 'phi' => $phi];
        }

        return self::$grid;
    }
}
