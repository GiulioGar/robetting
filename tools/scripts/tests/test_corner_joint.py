"""
Step 84 — targeted tests for tools/scripts/corner_joint.py (copula numerics, normalisation, no train/test leakage).

Run from the repo root:
  py -3 -m unittest tools.scripts.tests.test_corner_joint -v
"""
import sys
import unittest
from pathlib import Path

import numpy as np
from scipy.stats import multivariate_normal, nbinom

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import corner_experiment as ce  # noqa: E402
import corner_joint as cj  # noqa: E402


def _pmf(mu, phi, k=cj.K):
    r = 1 / phi
    return nbinom.pmf(np.arange(k + 1), r, r / (r + mu))


class CopulaNumericsTest(unittest.TestCase):

    def test_bivariate_normal_cdf_matches_scipy(self):
        for rho in (-0.6, -0.25, 0.0, 0.4):
            for a, b in ((0.3, -0.7), (-1.5, 1.2), (2.0, 2.5)):
                got = cj.bvn_cdf_paired(np.array([a]), np.array([b]), rho)[0]
                ref = multivariate_normal([0, 0], [[1, rho], [rho, 1]]).cdf([a, b])
                self.assertAlmostEqual(got, ref, places=4, msg=f'{rho} {a} {b}')

    def test_infinities_give_zero_or_marginal(self):
        self.assertAlmostEqual(cj.bvn_cdf_paired(np.array([-np.inf]), np.array([0.5]), -0.3)[0], 0.0, places=9)
        self.assertAlmostEqual(cj.bvn_cdf_paired(np.array([np.inf]), np.array([0.5]), -0.3)[0], 0.6914624612740131, places=4)


class JointPmfTest(unittest.TestCase):

    def test_normalised_and_nonnegative(self):
        for rho in (-0.5, 0.0, 0.3):
            P, mass = cj.joint_pmf(5.3, 0.1, 4.2, 0.1, rho)
            self.assertAlmostEqual(float(P.sum()), 1.0, places=12)
            self.assertTrue((P >= 0).all())
            self.assertGreater(mass, 0.9999)             # truncation at K loses a negligible tail

    def test_rho_zero_is_independence(self):
        P, _ = cj.joint_pmf(5.3, 0.1, 4.2, 0.12, 0.0)
        ind = np.outer(_pmf(5.3, 0.1), _pmf(4.2, 0.12))
        self.assertLess(np.abs(P - ind / ind.sum()).max(), 1e-4)

    def test_marginals_are_preserved_and_sign_of_dependence_follows_rho(self):
        for rho in (-0.5, 0.4):
            P, _ = cj.joint_pmf(5.3, 0.1, 4.2, 0.1, rho)
            self.assertLess(np.abs(P.sum(1) - _pmf(5.3, 0.1)).max(), 2e-3)
            self.assertLess(np.abs(P.sum(0) - _pmf(4.2, 0.1)).max(), 2e-3)
            g = np.arange(P.shape[0])
            cov = (np.outer(g, g) * P).sum() - (P.sum(1) * g).sum() * (P.sum(0) * g).sum()
            self.assertEqual(np.sign(cov), np.sign(rho))

    def test_negative_dependence_lowers_total_variance_and_probabilities_are_valid(self):
        Pi, _ = cj.joint_pmf(5.3, 0.1, 4.2, 0.1, 0.0)
        Pc, _ = cj.joint_pmf(5.3, 0.1, 4.2, 0.1, -0.4)
        (mi, vi), (mc, vc) = cj.moments(cj.total_pmf(Pi)), cj.moments(cj.total_pmf(Pc))
        self.assertAlmostEqual(mi, mc, places=2)         # same means (same marginals)
        self.assertLess(vc, vi)
        for p in cj.over_probs(cj.total_pmf(Pc)):
            self.assertTrue(0.0 < p < 1.0)

    def test_over_probs_are_monotonic_in_the_threshold(self):
        P, _ = cj.joint_pmf(5.3, 0.1, 4.2, 0.1, -0.3)
        o = cj.over_probs(cj.total_pmf(P))
        self.assertGreater(o[0], o[1])
        self.assertGreater(o[1], o[2])


class RhoEstimationTest(unittest.TestCase):

    def test_rho_is_a_pure_function_of_the_given_pairs(self):
        rng = np.random.default_rng(1)
        n = 400
        mh, ma = np.full(n, 5.3), np.full(n, 4.2)
        yh, ya = rng.poisson(mh), rng.poisson(ma)
        a = cj.fit_rho(yh, ya, mh, 0.1, ma, 0.1)
        self.assertEqual(a, cj.fit_rho(yh.copy(), ya.copy(), mh.copy(), 0.1, ma.copy(), 0.1))

    def test_independent_data_gives_rho_near_zero_and_negative_data_negative(self):
        rng = np.random.default_rng(2)
        n = 3000
        mh, ma = np.full(n, 5.3), np.full(n, 4.2)
        yh, ya = rng.poisson(mh), rng.poisson(ma)
        self.assertLess(abs(cj.fit_rho(yh, ya, mh, 0.05, ma, 0.05)), 0.1)
        z = rng.multivariate_normal([0, 0], [[1, -0.5], [-0.5, 1]], n)         # Gaussian copula, rho = -0.5
        from scipy.stats import norm
        r = 1 / 0.1
        yh = nbinom.ppf(norm.cdf(z[:, 0]), r, r / (r + 5.3)).astype(int)
        ya = nbinom.ppf(norm.cdf(z[:, 1]), r, r / (r + 4.2)).astype(int)
        self.assertLess(cj.fit_rho(yh, ya, mh, 0.1, ma, 0.1), -0.35)


class NoLeakageTest(unittest.TestCase):

    def test_copula_parameter_ignores_test_targets(self):
        rng = np.random.default_rng(3)

        def rows(shuffle_test):
            out = []
            for i in range(260):
                year = ce.TRAIN_YEAR if i < 180 else ce.TEST_YEAR
                out.append({'id': i, 'comp': 1, 'year': year, 'ko': f'2024-08-{i % 28 + 1:02d}', 'yh': int(rng_h[i]), 'ya': int(rng_a[i]),
                            'x': list(feat[i])})
            if shuffle_test:
                for r, (yh, ya) in zip(out[180:], zip(rng_h[180:][::-1], rng_a[180:][::-1])):
                    r['yh'], r['ya'] = int(yh), int(ya)
            return out

        feat = rng.normal(size=(260, len(ce.FEATURES)))
        rng_h, rng_a = rng.poisson(5.3, 260), rng.poisson(4.2, 260)
        r1 = cj.run(rows(False))
        r2 = cj.run(rows(True))
        self.assertEqual(r1['rho_copula_train'], r2['rho_copula_train'])
        self.assertEqual(r1['n_train'], 180)
        self.assertEqual(r1['n_test'], 80)


if __name__ == '__main__':
    unittest.main()
