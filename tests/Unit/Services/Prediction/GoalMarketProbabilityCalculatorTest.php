<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\GoalMarketProbabilityCalculator as Calc;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GOAL Probability Calculator v1. Reference values for (1.5, 1.2, 0.15) come from an independent brute-force
 * summation of the full Bivariate Poisson joint (70x70 grid, mass 1 - 2e-16), not from the closed forms under test.
 */
class GoalMarketProbabilityCalculatorTest extends TestCase
{
    private const D = 1e-9;

    public function test_numeric_reference_values(): void
    {
        $r = Calc::calculate(1.5, 1.2, 0.15);

        $under = ['0.5' => 0.078081666001, '1.5' => 0.265477664404, '2.5' => 0.502065112387,
                  '3.5' => 0.710074670614, '4.5' => 0.852624464149];
        foreach ($under as $line => $u) {
            $this->assertEqualsWithDelta($u, $r['total_goals'][$line]['under'], self::D, "under {$line}");
            $this->assertEqualsWithDelta(1 - $u, $r['total_goals'][$line]['over'], self::D, "over {$line}");
        }

        $this->assertEqualsWithDelta(0.553757293941, $r['btts']['yes'], self::D);
        $this->assertEqualsWithDelta(1 - 0.553757293941, $r['btts']['no'], self::D);

        $multigol = ['1-3' => 0.631993004613, '2-4' => 0.587146799746, '2-5' => 0.668051274136, '3-6' => 0.470953105585];
        foreach ($multigol as $k => $p) {
            $this->assertEqualsWithDelta($p, $r['multigol'][$k], self::D, "multigol {$k}");
        }

        $home = ['0.5' => 0.223130160148, '1.5' => 0.557825400371, '2.5' => 0.808846830538];
        $away = ['0.5' => 0.301194211912, '1.5' => 0.662627266207, '2.5' => 0.879487098784];
        foreach ($home as $line => $u) {
            $this->assertEqualsWithDelta($u, $r['team_goals']['home'][$line]['under'], self::D);
        }
        foreach ($away as $line => $u) {
            $this->assertEqualsWithDelta($u, $r['team_goals']['away'][$line]['under'], self::D);
        }
    }

    public function test_total_distribution_is_normalised_without_truncation(): void
    {
        foreach ([[0.3, 0.2, 0.05], [1.5, 1.2, 0.15], [2.8, 3.1, 0.15], [6.0, 5.0, 0.4]] as [$lh, $la, $l3]) {
            $s = $lh + $la - 2 * $l3;
            $sum = 0.0;
            for ($t = 0; $t <= 120; $t++) {
                $sum += Calc::totalPmf($t, $s, $l3);
            }
            $this->assertEqualsWithDelta(1.0, $sum, 1e-12, "mass for {$lh},{$la},{$l3}");
        }
    }

    public function test_complements_and_ranges(): void
    {
        foreach ([[0.4, 0.3, 0.1], [1.5, 1.2, 0.15], [3.2, 2.9, 0.15]] as [$lh, $la, $l3]) {
            $r = Calc::calculate($lh, $la, $l3);
            $markets = array_merge(
                array_values($r['total_goals']),
                array_values($r['team_goals']['home']),
                array_values($r['team_goals']['away']),
                [['over' => $r['btts']['yes'], 'under' => $r['btts']['no']]]
            );
            foreach ($markets as $m) {
                $this->assertEqualsWithDelta(1.0, $m['over'] + $m['under'], 1e-15);
                $this->assertGreaterThanOrEqual(0.0, $m['over']);
                $this->assertLessThanOrEqual(1.0, $m['over']);
            }
            foreach ($r['multigol'] as $p) {
                $this->assertGreaterThan(0.0, $p);
                $this->assertLessThan(1.0, $p);
            }
        }
    }

    public function test_market_coherence(): void
    {
        $r = Calc::calculate(1.7, 1.1, 0.15);
        $u = fn (string $l) => $r['total_goals'][$l]['under'];

        // Multigol is the inclusive difference of total-goal CDF values.
        $this->assertEqualsWithDelta($u('3.5') - $u('0.5'), $r['multigol']['1-3'], 1e-14);
        $this->assertEqualsWithDelta($u('4.5') - $u('1.5'), $r['multigol']['2-4'], 1e-14);
        $this->assertGreaterThan($r['multigol']['2-4'], $r['multigol']['2-5']);   // 2-5 adds P(T=5) to 2-4

        // Over/under monotone in the line.
        $prev = 0.0;
        foreach (Calc::TOTAL_LINES as $line) {
            $this->assertGreaterThan($prev, $u(number_format($line, 1)));
            $prev = $u(number_format($line, 1));
        }

        // BTTS needs both teams to score, hence at least 2 total goals and a goal for each team.
        $this->assertLessThan($r['total_goals']['1.5']['over'], $r['btts']['yes']);
        $this->assertLessThan(1 - $r['team_goals']['home']['0.5']['under'], $r['btts']['yes']);
        $this->assertLessThan(1 - $r['team_goals']['away']['0.5']['under'], $r['btts']['yes']);

        // BTTS equals the sum of the full joint over h>=1, a>=1 (independent brute-force path).
        $this->assertEqualsWithDelta($this->bruteForceBtts(1.7, 1.1, 0.15), $r['btts']['yes'], 1e-12);
    }

    public function test_lambda3_zero_reduces_to_independent_poissons(): void
    {
        $r = Calc::calculate(1.4, 0.9, 0.0);
        $this->assertEqualsWithDelta((1 - exp(-1.4)) * (1 - exp(-0.9)), $r['btts']['yes'], 1e-14);
        $mu = 2.3;
        $this->assertEqualsWithDelta(exp(-$mu) * (1 + $mu + $mu * $mu / 2), $r['total_goals']['2.5']['under'], 1e-14);
    }

    public function test_extreme_lambdas_are_stable(): void
    {
        $r = Calc::calculate(1e-6, 1e-6, 0.0);
        $this->assertEqualsWithDelta(1e-12, $r['btts']['yes'], 1e-15);   // tiny probability kept without cancellation
        $this->assertGreaterThan(0.0, $r['btts']['yes']);

        $r = Calc::calculate(9.0, 8.0, 0.15);
        $this->assertEqualsWithDelta(1.0, $r['total_goals']['4.5']['over'] + $r['total_goals']['4.5']['under'], 1e-15);
        $this->assertGreaterThan(0.95, $r['total_goals']['4.5']['over']);
    }

    public function test_effective_lambda3_matches_candidate_cap(): void
    {
        $this->assertSame(0.15, Calc::effectiveLambda3(1.5, 1.2, 0.15));
        $this->assertEqualsWithDelta(0.1 * 0.9999, Calc::effectiveLambda3(0.1, 1.2, 0.15), 1e-15);
    }

    #[DataProvider('invalidParameters')]
    public function test_invalid_parameters_are_rejected(float $lh, float $la, float $l3): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::calculate($lh, $la, $l3);
    }

    public static function invalidParameters(): array
    {
        return [
            'zero home' => [0.0, 1.0, 0.1],
            'negative away' => [1.0, -0.5, 0.1],
            'negative lambda3' => [1.0, 1.0, -0.01],
            'lambda3 above min' => [1.0, 0.5, 0.6],
            'nan' => [NAN, 1.0, 0.1],
            'infinite' => [1.0, INF, 0.1],
        ];
    }

    private function bruteForceBtts(float $lh, float $la, float $l3, int $g = 60): float
    {
        $pp = fn (int $k, float $l) => $l <= 0 ? ($k === 0 ? 1.0 : 0.0) : exp($k * log($l) - $l - array_sum(array_map('log', $k > 1 ? range(2, $k) : [1])));
        $sum = 0.0;
        for ($h = 1; $h < $g; $h++) {
            for ($a = 1; $a < $g; $a++) {
                for ($k = 0; $k <= min($h, $a); $k++) {
                    $sum += $pp($k, $l3) * $pp($h - $k, $lh - $l3) * $pp($a - $k, $la - $l3);
                }
            }
        }

        return $sum;
    }
}
