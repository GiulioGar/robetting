<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\Corner\CornerMath;
use App\Services\Prediction\Corner\CornerModelService;
use RuntimeException;
use Tests\TestCase;

/**
 * Numerical parity of the PHP CORNER backend with the Python reference (tools/scripts/corner_joint.py / corner_export_artifact.py).
 * tests/Fixtures/corner_golden.json holds feature vectors of three 2026/27 matches (null = missing) and the Python outputs.
 */
class CornerModelServiceTest extends TestCase
{
    private function golden(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/corner_golden.json')), true);
    }

    private function named(array $vector): array
    {
        $art = json_decode((string) file_get_contents(base_path('tools/models/corner_nb_copula_v1.json')), true);

        return array_combine($art['features'], $vector);
    }

    public function test_php_matches_python_on_the_golden_matches(): void
    {
        $svc = new CornerModelService();
        foreach ($this->golden()['cases'] as $case) {
            $out = $svc->predictFromFeatures($this->named($case['features']));
            $exp = $case['expected'];
            $this->assertTrue($out['available']);
            $this->assertEqualsWithDelta($exp['mu_home'], $out['mu_home'], 1e-9, 'mu_home ' . $case['match_id']);
            $this->assertEqualsWithDelta($exp['mu_away'], $out['mu_away'], 1e-9);
            $this->assertEqualsWithDelta($exp['expected_total'], $out['expected_total'], 1e-9);
            foreach ($exp['over'] as $i => $p) {
                $this->assertEqualsWithDelta($p, array_values($out['over'])[$i], 1e-6, "over[$i] " . $case['match_id']);
            }
            $this->assertEqualsWithDelta($exp['total_mean'], $out['total_mean'], 1e-6);
            $this->assertEqualsWithDelta($exp['total_variance'], $out['total_variance'], 1e-5);
            foreach ($exp['total_pmf_0_24'] as $t => $p) {
                $this->assertEqualsWithDelta($p, $out['total_pmf'][$t], 1e-6, "total_pmf[$t] " . $case['match_id']);
            }
            $this->assertEqualsWithDelta($exp['truncated_mass'], $out['truncated_mass'], 1e-7);
        }
    }

    public function test_probabilities_are_normalised_and_consistent(): void
    {
        $out = (new CornerModelService())->predictFromFeatures($this->named($this->golden()['cases'][1]['features']));
        $this->assertEqualsWithDelta(1.0, array_sum($out['total_pmf']), 1e-9);
        $this->assertEqualsWithDelta(1.0, array_sum($out['home_pmf']) + 0.0, 1e-4);   // NB pmf up to 30 corners keeps all but a negligible tail
        $o = array_values($out['over']);
        $this->assertGreaterThan($o[1], $o[0]);
        $this->assertGreaterThan($o[2], $o[1]);
        foreach ($out['over'] as $line => $p) {
            $this->assertEqualsWithDelta(1.0, $p + $out['under'][$line], 1e-12);
        }
    }

    public function test_missing_features_use_the_artifact_median_not_zero(): void
    {
        $svc = new CornerModelService();
        $vector = $this->golden()['cases'][1]['features'];
        $all = $svc->predictFromFeatures($this->named($vector));
        $art = json_decode((string) file_get_contents(base_path('tools/models/corner_nb_copula_v1.json')), true);

        $vector[0] = null;                                                       // h_for5 missing
        $withMissing = $svc->predictFromFeatures($this->named($vector));
        $vector[0] = $art['imputer']['values'][0];                               // explicitly the median
        $explicit = $svc->predictFromFeatures($this->named($vector));
        $vector[0] = 0.0;                                                        // a real zero must differ
        $zero = $svc->predictFromFeatures($this->named($vector));

        $this->assertEqualsWithDelta($explicit['mu_home'], $withMissing['mu_home'], 1e-12);
        $this->assertNotEquals($zero['mu_home'], $withMissing['mu_home']);
        $this->assertNotEquals($all['mu_home'], $withMissing['mu_home']);
    }

    public function test_independence_reproduces_the_product_of_marginals(): void
    {
        [$P] = CornerMath::jointPmf(5.3, 0.1, 4.2, 0.12, 0.0, 30);
        $h = CornerMath::nbPmf(5.3, 0.1, 30);
        $a = CornerMath::nbPmf(4.2, 0.12, 30);
        $mass = array_sum($h) * array_sum($a);
        $this->assertEqualsWithDelta($h[5] * $a[3] / $mass, $P[5][3], 1e-5);
    }

    public function test_normal_cdf_and_inverse(): void
    {
        $this->assertEqualsWithDelta(0.5, CornerMath::ndtr(0.0), 1e-15);
        $this->assertEqualsWithDelta(0.9750021048517795, CornerMath::ndtr(1.96), 1e-13);
        $this->assertEqualsWithDelta(1.4882282217623072e-10, CornerMath::ndtr(-6.3), 1e-22);   // scipy.special.ndtr, far tail
        $this->assertEqualsWithDelta(1.959963984540054, CornerMath::ndtri(0.975), 1e-12);
        foreach ([1e-6, 0.01, 0.3, 0.5, 0.9, 0.999] as $p) {
            $this->assertEqualsWithDelta($p, CornerMath::ndtr(CornerMath::ndtri($p)), 1e-12 + 1e-9 * $p);
        }
    }

    public function test_missing_artifact_is_reported(): void
    {
        $this->expectException(RuntimeException::class);
        (new CornerModelService(base_path('tools/models/does_not_exist.json')))->predictFromFeatures([]);
    }
}
