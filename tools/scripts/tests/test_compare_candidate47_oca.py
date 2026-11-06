"""
Step 74 — targeted tests for the pure helpers of tools/scripts/compare_candidate47_oca.py.

Run from the repo root:
  py -3 -m unittest tools.scripts.tests.test_compare_candidate47_oca -v
"""
import sys
import unittest
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import compare_candidate47_oca as oca  # noqa: E402


def _summary(covered, total, att=None, de=None, support=None):
    return {'matches_total': total, 'matches_covered': covered,
            'opp_latent_attack_mean': att, 'opp_latent_defence_mean': de,
            'opp_support_mean': support, 'opp_support_min': None,
            'opp_support_matches': [], 'missing_opponent_ids': []}


class OcaColumnsTest(unittest.TestCase):

    def test_home_and_away_stay_separate_and_attack_defence_separate(self):
        c = oca.oca_columns(_summary(8, 10, 0.1, -0.2, 30.0), _summary(10, 10, -0.3, 0.4, 50.0))
        self.assertEqual((c['oca_att_home'], c['oca_def_home']), (0.1, -0.2))
        self.assertEqual((c['oca_att_away'], c['oca_def_away']), (-0.3, 0.4))
        self.assertAlmostEqual(c['oca_cov_home'], 0.8)
        self.assertAlmostEqual(c['oca_cov_away'], 1.0)
        self.assertEqual((c['oca_support_home'], c['oca_support_away']), (30.0, 50.0))

    def test_missing_means_stay_none_while_coverage_is_explicit(self):
        c = oca.oca_columns(_summary(0, 10), _summary(0, 0))
        for k in ('oca_att_home', 'oca_def_home', 'oca_support_home', 'oca_att_away', 'oca_def_away', 'oca_support_away'):
            self.assertIsNone(c[k], k)
        self.assertEqual((c['oca_cov_home'], c['oca_cov_away']), (0.0, 0.0))

    def test_matrix_uses_nan_never_zero_for_missing(self):
        summaries = {('1', 'home'): _summary(0, 10), ('1', 'away'): _summary(10, 10, 0.2, 0.1, 40.0)}
        X = oca.oca_matrix(['1'], summaries)
        self.assertTrue(np.isnan(X[0, oca.OCA_NAMES.index('oca_att_home')]))
        self.assertEqual(X[0, oca.OCA_NAMES.index('oca_cov_home')], 0.0)
        self.assertEqual(X[0, oca.OCA_NAMES.index('oca_att_away')], 0.2)


class WindowsTest(unittest.TestCase):

    def test_window_is_strictly_before_the_target_and_per_side(self):
        tm = {
            '1': [{'kickoff_at': '2026-01-01 18:00:00', 'opponent_id': '9'},
                  {'kickoff_at': '2026-02-01 18:00:00', 'opponent_id': '8'},
                  {'kickoff_at': '2026-03-01 18:00:00', 'opponent_id': '2'},      # the target itself
                  {'kickoff_at': '2026-04-01 18:00:00', 'opponent_id': '7'}],     # future
            '2': [{'kickoff_at': '2026-02-15 18:00:00', 'opponent_id': '6'},
                  {'kickoff_at': '2026-03-01 18:00:00', 'opponent_id': '1'}],
        }
        rows = [{'match_id': 'm', 'kickoff_at': '2026-03-01T18:00:00+00:00', 'home_team_id': '1', 'away_team_id': '2'}]
        w = oca.windows_for_rows(rows, tm)
        self.assertEqual(w[('m', 'home')], [('2026-02-01', '8'), ('2026-01-01', '9')])
        self.assertEqual(w[('m', 'away')], [('2026-02-15', '6')])


class SplitTest(unittest.TestCase):

    def test_date_split_is_disjoint_chronological_and_excludes_earlier_rows_from_train(self):
        rows = [{'kickoff_at': d + 'T18:00:00+00:00'} for d in
                ('2025-09-01', '2025-12-31', '2026-01-01', '2026-05-29', '2026-08-15', '2026-11-02')]
        train, test = oca.date_split_masks(rows, '2026-01-01', '2026-07-01')
        self.assertEqual(train.tolist(), [False, False, True, True, False, False])
        self.assertEqual(test.tolist(), [False, False, False, False, True, True])
        self.assertFalse((train & test).any())

    def test_distribution_report_is_nan_aware_and_reports_shift_in_train_sd(self):
        X = np.array([[1.0], [3.0], [np.nan], [5.0], [7.0]])
        train = np.array([True, True, True, False, False])
        test = ~train
        (name, tm, ts, em, es, sh), = oca.distribution_report(X, ['c'], train, test)
        self.assertAlmostEqual(tm, 2.0)
        self.assertAlmostEqual(em, 6.0)
        self.assertAlmostEqual(sh, (6.0 - 2.0) / ts)


class MetricsTest(unittest.TestCase):

    def test_logloss_brier_and_ece_on_known_values(self):
        P = np.array([[0.7, 0.2, 0.1], [0.1, 0.2, 0.7]])
        y = np.array([0, 2])
        self.assertAlmostEqual(float(oca.per_match_logloss(P, y).mean()), -np.log(0.7))
        self.assertAlmostEqual(oca.brier(P, y), (0.3 ** 2 + 0.2 ** 2 + 0.1 ** 2))
        # perfectly calibrated degenerate case: predicted == observed rate in every bin
        Pc = np.tile([0.5, 0.3, 0.2], (10, 1))
        yc = np.array([0] * 5 + [1] * 3 + [2] * 2)
        for ece, mp, orate in oca.ece_per_class(Pc, yc, bins=1):
            self.assertAlmostEqual(ece, 0.0)

    def test_bootstrap_ci_is_deterministic_and_brackets_the_mean(self):
        diff = np.array([-0.1, 0.05, -0.02, 0.0, -0.07, 0.03, -0.04, -0.01])
        a = oca.paired_bootstrap_ci(diff)
        self.assertEqual(a, oca.paired_bootstrap_ci(diff))
        self.assertLess(a[0], diff.mean())
        self.assertGreater(a[1], diff.mean())


if __name__ == '__main__':
    unittest.main()
