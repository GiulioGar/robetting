<?php

namespace App\Services\Prediction;

use InvalidArgumentException;

/**
 * GOAL Probability Calculator v1.
 *
 * Pure, stateless: derives the GOAL market probabilities from the Bivariate Poisson
 * (Karlis & Ntzoufras) defined by lambda_home, lambda_away and lambda3, without touching the lambdas.
 *
 *   H = X1 + X3, A = X2 + X3, X1 ~ Pois(lh - l3), X2 ~ Pois(la - l3), X3 ~ Pois(l3), independent.
 *
 * Closed forms (no 0..N truncation, no renormalisation):
 *   - total T = H + A = Pois(lh + la - 2*l3) + 2*Pois(l3); P(T = t) = sum_k Pois(k; l3) * Pois(t - 2k; lh + la - 2*l3).
 *     Over/Under and Multigol are finite sums of this pmf (Over = 1 - Under, Multigol inclusive on both ends).
 *   - Team goals use the exact marginals H ~ Pois(lh), A ~ Pois(la).
 *   - BTTS YES = P(H>0, A>0) = (1-e^-lh)(1-e^-la) + e^-(lh+la) * (e^l3 - 1), the joint-consistent probability
 *     (algebraically 1 - e^-lh - e^-la + e^-(lh+la-l3), written without cancellation).
 *
 * Output is derived, never persisted here, and never replaces the stored official predictions.
 * Note: CandidateModelService applies a safety cap l3 <= 0.9999 * min(lh, la) before computing 1X2; use
 * effectiveLambda3() to obtain the same value when consistency with that 1X2 is wanted. calculate() itself
 * uses exactly the lambda3 it is given.
 */
final class GoalMarketProbabilityCalculator
{
    public const TOTAL_LINES = [0.5, 1.5, 2.5, 3.5, 4.5];
    public const TEAM_LINES = [0.5, 1.5, 2.5];
    public const MULTIGOL_RANGES = [[1, 3], [2, 4], [2, 5], [3, 6]];

    /**
     * @return array{
     *   inputs: array{lambda_home: float, lambda_away: float, lambda3: float},
     *   total_goals: array<string, array{over: float, under: float}>,
     *   btts: array{yes: float, no: float},
     *   team_goals: array{home: array<string, array{over: float, under: float}>, away: array<string, array{over: float, under: float}>},
     *   multigol: array<string, float>
     * }
     */
    public static function calculate(float $lambdaHome, float $lambdaAway, float $lambda3): array
    {
        self::validate($lambdaHome, $lambdaAway, $lambda3);

        $s = $lambdaHome + $lambdaAway - 2.0 * $lambda3;   // mean of X1 + X2

        $maxT = (int) max(max(self::TOTAL_LINES), ...array_map(fn ($r) => $r[1], self::MULTIGOL_RANGES));
        $pmf = [];
        for ($t = 0; $t <= $maxT; $t++) {
            $pmf[$t] = self::totalPmf($t, $s, $lambda3);
        }

        $total = [];
        foreach (self::TOTAL_LINES as $line) {
            $under = self::sum($pmf, 0, (int) floor($line));
            $total[self::key($line)] = ['over' => 1.0 - $under, 'under' => $under];
        }

        $yes = (-expm1(-$lambdaHome)) * (-expm1(-$lambdaAway)) + exp(-($lambdaHome + $lambdaAway)) * expm1($lambda3);
        $yes = min(1.0, max(0.0, $yes));

        $multigol = [];
        foreach (self::MULTIGOL_RANGES as [$lo, $hi]) {
            $multigol[$lo . '-' . $hi] = self::sum($pmf, $lo, $hi);
        }

        return [
            'inputs' => ['lambda_home' => $lambdaHome, 'lambda_away' => $lambdaAway, 'lambda3' => $lambda3],
            'total_goals' => $total,
            'btts' => ['yes' => $yes, 'no' => 1.0 - $yes],
            'team_goals' => [
                'home' => self::teamGoals($lambdaHome),
                'away' => self::teamGoals($lambdaAway),
            ],
            'multigol' => $multigol,
        ];
    }

    /** Same safety cap CandidateModelService applies before computing the Bivariate 1X2. */
    public static function effectiveLambda3(float $lambdaHome, float $lambdaAway, float $lambda3): float
    {
        return min($lambda3, min($lambdaHome, $lambdaAway) * 0.9999);
    }

    /** P(T = t) for the Bivariate Poisson total. */
    public static function totalPmf(int $t, float $s, float $lambda3): float
    {
        $p = 0.0;
        for ($k = 0; 2 * $k <= $t; $k++) {
            $p += self::poissonPmf($k, $lambda3) * self::poissonPmf($t - 2 * $k, $s);
        }

        return $p;
    }

    private static function validate(float $lambdaHome, float $lambdaAway, float $lambda3): void
    {
        foreach (['lambda_home' => $lambdaHome, 'lambda_away' => $lambdaAway, 'lambda3' => $lambda3] as $name => $v) {
            if (! is_finite($v)) {
                throw new InvalidArgumentException("{$name} must be finite.");
            }
        }
        if ($lambdaHome <= 0.0 || $lambdaAway <= 0.0) {
            throw new InvalidArgumentException('lambda_home and lambda_away must be > 0.');
        }
        if ($lambda3 < 0.0) {
            throw new InvalidArgumentException('lambda3 must be >= 0.');
        }
        if ($lambda3 > min($lambdaHome, $lambdaAway)) {
            throw new InvalidArgumentException('lambda3 must not exceed min(lambda_home, lambda_away).');
        }
    }

    /** @return array<string, array{over: float, under: float}> */
    private static function teamGoals(float $lambda): array
    {
        $out = [];
        foreach (self::TEAM_LINES as $line) {
            $under = 0.0;
            for ($k = 0; $k <= (int) floor($line); $k++) {
                $under += self::poissonPmf($k, $lambda);
            }
            $out[self::key($line)] = ['over' => 1.0 - $under, 'under' => $under];
        }

        return $out;
    }

    /** Poisson pmf in log space; lambda = 0 is the point mass at 0. */
    private static function poissonPmf(int $k, float $lambda): float
    {
        if ($k < 0) {
            return 0.0;
        }
        if ($lambda <= 0.0) {
            return $k === 0 ? 1.0 : 0.0;
        }

        return exp($k * log($lambda) - $lambda - self::logFactorial($k));
    }

    private static function logFactorial(int $n): float
    {
        $r = 0.0;
        for ($i = 2; $i <= $n; $i++) {
            $r += log($i);
        }

        return $r;
    }

    /** @param array<int, float> $pmf */
    private static function sum(array $pmf, int $lo, int $hi): float
    {
        $values = [];
        for ($t = $lo; $t <= $hi; $t++) {
            $values[] = $pmf[$t];
        }

        return array_sum($values);
    }

    private static function key(float $line): string
    {
        return number_format($line, 1, '.', '');
    }
}
