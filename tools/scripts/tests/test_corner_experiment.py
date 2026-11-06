"""
Step 83 — targeted tests for tools/scripts/corner_experiment.py (point-in-time features, missing values, NB density).

Run from the repo root:
  py -3 -m unittest tools.scripts.tests.test_corner_experiment -v
"""
import math
import sys
import unittest
from pathlib import Path

import numpy as np
from scipy.stats import nbinom

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import corner_experiment as ce  # noqa: E402


def _m(i, ko, home, away, hc=None, ac=None, comp=1, season=1, year=2024):
    return {'id': i, 'ko': ko, 'comp': comp, 'season': season, 'year': year, 'home': home, 'away': away, 'hc': hc, 'ac': ac}


MATCHES = [
    _m(1, '2024-08-01 18:00:00', 10, 20, 6, 3),
    _m(2, '2024-08-08 18:00:00', 20, 10, 4, 7),
    _m(3, '2024-08-15 18:00:00', 10, 30, None, None),          # no statistics: missing, not 0
    _m(4, '2024-08-22 18:00:00', 30, 10, 5, 5),
    _m(5, '2024-08-29 18:00:00', 10, 20),                        # target
]


class WindowTest(unittest.TestCase):

    def setUp(self):
        self.idx = ce.build_indexes(MATCHES)
        self.team, self.team_ko = self.idx[0], self.idx[1]

    def test_window_is_strictly_before_and_for_against_are_from_the_teams_view(self):
        # team 10 before 29/08: m1 (for 6, ag 3), m2 (for 7, ag 4), m3 missing, m4 (for 5, ag 5)
        f, a, n = ce.window_stats(self.team[10], self.team_ko[10], '2024-08-29 18:00:00', 10)
        self.assertEqual(n, 3)
        self.assertAlmostEqual(f, (6 + 7 + 5) / 3)
        self.assertAlmostEqual(a, (3 + 4 + 5) / 3)

    def test_same_kickoff_and_future_matches_are_excluded(self):
        f, a, n = ce.window_stats(self.team[10], self.team_ko[10], '2024-08-08 18:00:00', 10)
        self.assertEqual((n, f, a), (1, 6.0, 3.0))   # only m1; m2 is at the same kickoff

    def test_window_counts_matches_not_matches_with_data(self):
        # last 2 matches before 29/08 are m3 (no data) and m4 -> a single match with data
        f, a, n = ce.window_stats(self.team[10], self.team_ko[10], '2024-08-29 18:00:00', 2)
        self.assertEqual((n, f, a), (1, 5.0, 5.0))

    def test_no_data_is_none_never_zero(self):
        f, a, n = ce.window_stats(self.team[10], self.team_ko[10], '2024-08-01 18:00:00', 5)
        self.assertEqual((f, a, n), (None, None, 0))
        f, a, n = ce.window_stats(self.team[30], self.team_ko[30], '2024-08-16 18:00:00', 5)   # only the match without stats
        self.assertEqual((f, a, n), (None, None, 0))

    def test_venue_window_only_uses_the_requested_venue(self):
        f, a, n = ce.window_stats(self.team[10], self.team_ko[10], '2024-08-29 18:00:00', 10, venue='H')
        self.assertEqual((n, f, a), (1, 6.0, 3.0))   # home matches of team 10 with data: m1 only (m3 has none)


class LeagueMeanTest(unittest.TestCase):

    def test_league_mean_uses_only_earlier_matches_with_data(self):
        feats = ce.corner_features(MATCHES[4], ce.build_indexes(MATCHES))
        self.assertEqual(feats['lg_n'], 3)                              # m1, m2, m4 (m3 has no data)
        self.assertAlmostEqual(feats['lg_home'], (6 + 4 + 5) / 3)
        self.assertAlmostEqual(feats['lg_away'], (3 + 7 + 5) / 3)

    def test_first_match_has_no_league_mean(self):
        feats = ce.corner_features(MATCHES[0], ce.build_indexes(MATCHES))
        self.assertEqual(feats['lg_n'], 0)
        self.assertIsNone(feats['lg_home'])
        self.assertEqual(feats['h_n10'], 0)

    def test_dataset_excludes_matches_without_corner_target(self):
        ms = [m for m in MATCHES]
        rows = ce.build_dataset(ms, {1})
        self.assertEqual([r['id'] for r in rows], [1, 2, 4])


class DensityTest(unittest.TestCase):

    def test_nb_logpmf_matches_scipy(self):
        mu, phi = 5.3, 0.3
        r = 1 / phi
        for y in (0, 3, 7, 15):
            self.assertAlmostEqual(float(ce.nb_logpmf(np.array([y]), np.array([mu]), phi)[0]), float(nbinom.logpmf(y, r, r / (r + mu))), places=10)

    def test_nb_with_small_dispersion_approaches_poisson(self):
        y, mu = np.array([4.0]), np.array([5.0])
        self.assertAlmostEqual(float(ce.logpmf(y, mu, 1e-6)[0]), float(ce.logpmf(y, mu, None)[0]), places=4)

    def test_bins_sum_to_one(self):
        for phi in (None, 0.2):
            self.assertAlmostEqual(float(ce.bin_probs(np.array([5.0]), phi).sum()), 1.0, places=6)


class ShotFeaturesTest(unittest.TestCase):
    """Step 85: shot-volume windows follow the same point-in-time and missing-value rules as the corner windows."""

    def _idx(self):
        ms = [dict(m, hs=None, **{'as': None}) for m in MATCHES]
        ms[0].update(hs=14, **{'as': 9})        # m1: team 10 home -> shots for 14, against 9
        ms[1].update(hs=8, **{'as': 12})        # m2: team 20 home, team 10 away -> team 10 shots for 12, against 8
        ms[2].update(hs=11, **{'as': 7})        # m3 has shots but NO corners: shot window must still use it
        return ms, ce.build_indexes(ms)

    def test_window_uses_shots_independently_of_corner_availability_and_stays_strictly_before(self):
        ms, idx = self._idx()
        f = ce.shot_features(ms[4], idx)        # target m5, team 10 at home
        self.assertEqual(f['h_sn10'], 3)        # m1, m2, m3 (m4 has no shot data)
        self.assertAlmostEqual(f['h_sfor10'], (14 + 12 + 11) / 3)
        self.assertAlmostEqual(f['h_sag10'], (9 + 8 + 7) / 3)
        f0 = ce.shot_features(ms[0], idx)       # first match: nothing before
        self.assertEqual((f0['h_sn5'], f0['h_sfor5']), (0, None))

    def test_dataset_rows_carry_shot_features_with_nan_for_missing(self):
        ms, _ = self._idx()
        rows = ce.build_dataset(ms, {1})
        self.assertEqual(len(rows[0]['xs']), len(ce.SHOT_FEATURES))
        self.assertTrue(np.isnan(rows[0]['xs'][ce.SHOT_FEATURES.index('h_sfor5')]))   # no history yet: NaN, not 0

    def test_corner_features_are_unchanged_by_the_shot_columns(self):
        ms, idx = self._idx()
        plain = ce.corner_features(MATCHES[4], ce.build_indexes(MATCHES))
        withshots = ce.corner_features(ms[4], idx)
        self.assertEqual(plain, withshots)


if __name__ == '__main__':
    unittest.main()
