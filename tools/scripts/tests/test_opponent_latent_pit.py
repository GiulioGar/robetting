"""
Step 73 — targeted tests for tools/scripts/opponent_latent_pit.py.

Stdlib unittest only. Run from the repo root:
  py -3 -m unittest tools.scripts.tests.test_opponent_latent_pit -v
"""
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import latent_anchored as anchored  # noqa: E402
import opponent_latent_pit as pit  # noqa: E402


def _row(day, home, away, hg, ag):
    return {
        'kickoff_at': day + 'T18:00:00+00:00',
        'home_team_id': home,
        'away_team_id': away,
        'label_home_goals': str(hg),
        'label_away_goals': str(ag),
    }


def _core():
    return [
        _row('2026-01-01', '10', '20', 2, 1),
        _row('2026-01-08', '20', '30', 0, 1),
        _row('2026-01-15', '30', '10', 1, 1),
        _row('2026-02-01', '10', '20', 3, 0),
        _row('2026-02-08', '30', '20', 2, 2),
        _row('2026-03-01', '20', '10', 0, 2),
    ]


def _uefa():
    return [
        _row('2026-02-10', '10', '99', 1, 0),   # 99 directly connected to core
        _row('2026-02-20', '99', '98', 2, 1),   # 98 connected through 99
        _row('2026-02-25', '97', '96', 1, 1),   # 96/97 form a disconnected component
    ]


class AntiLeakageTest(unittest.TestCase):

    def test_future_results_never_change_an_earlier_estimate(self):
        req = [('10', '2026-02-15'), ('20', '2026-02-15'), ('99', '2026-02-15'), ('98', '2026-03-05')]
        base = pit.build_pit_lookup(req, _core(), _uefa())
        future_core = [r for r in _core() if r['kickoff_at'][:10] < '2026-02-15'] + [
            _row('2026-02-15', '10', '20', 9, 0),     # same day: must not be used
            _row('2026-04-01', '10', '20', 7, 0),     # later: must not be used
        ]
        future_uefa = _uefa() + [_row('2026-02-15', '99', '10', 5, 0), _row('2026-05-01', '99', '10', 0, 6)]
        perturbed = pit.build_pit_lookup([('10', '2026-02-15'), ('20', '2026-02-15'), ('99', '2026-02-15')], future_core, future_uefa)
        for key in [('10', '2026-02-15'), ('20', '2026-02-15'), ('99', '2026-02-15')]:
            self.assertEqual(base[key], perturbed[key], key)

    def test_same_day_results_are_excluded_from_support(self):
        core = _core() + [_row('2026-03-10', '10', '20', 1, 1)]
        td = pit._team_dates(core, [])
        self.assertEqual(pit.support_matches(td, '10', '2026-03-10'), 4)   # the 10-03 match itself is not counted
        self.assertEqual(pit.support_matches(td, '10', '2026-03-11'), 5)

    def test_estimate_for_a_team_uses_only_its_matches_before_the_date(self):
        # 99's only connection to core is on 2026-02-10: not estimable on 2026-02-10, estimable the day after.
        before = pit.build_pit_lookup([('99', '2026-02-10'), ('99', '2026-02-11')], _core(), _uefa())
        self.assertIsNone(before[('99', '2026-02-10')])
        self.assertIsNotNone(before[('99', '2026-02-11')])


class MissingValuesTest(unittest.TestCase):

    def test_unknown_disconnected_and_unseen_teams_are_none_not_zero(self):
        req = [('777', '2026-03-05'), ('97', '2026-03-05'), ('98', '2026-02-15')]
        lk = pit.build_pit_lookup(req, _core(), _uefa())
        self.assertIsNone(lk[('777', '2026-03-05')])   # never seen anywhere
        self.assertIsNone(lk[('97', '2026-03-05')])    # disconnected component
        self.assertIsNone(lk[('98', '2026-02-15')])    # no history yet before this date

    def test_core_team_without_prior_core_match_is_missing(self):
        lk = pit.build_pit_lookup([('20', '2026-01-01')], _core(), [])
        self.assertIsNone(lk[('20', '2026-01-01')])

    def test_window_means_only_over_covered_opponents(self):
        windows = {'w': [('2026-03-05', '10'), ('2026-03-05', '99'), ('2026-03-05', '777'), ('2026-03-05', '97')]}
        res = pit.compute_opponent_latent_features(windows, _core(), _uefa())['w']
        self.assertEqual(res['matches_total'], 4)
        self.assertEqual(res['matches_covered'], 2)
        self.assertCountEqual(res['missing_opponent_ids'], ['777', '97'])
        lk = pit.build_pit_lookup([('10', '2026-03-05'), ('99', '2026-03-05')], _core(), _uefa())
        self.assertAlmostEqual(res['opp_latent_attack_mean'], (lk[('10', '2026-03-05')][0] + lk[('99', '2026-03-05')][0]) / 2)
        self.assertAlmostEqual(res['opp_latent_defence_mean'], (lk[('10', '2026-03-05')][1] + lk[('99', '2026-03-05')][1]) / 2)
        self.assertEqual(len(res['opp_support_matches']), 2)

    def test_fully_missing_window_has_none_means_and_zero_coverage(self):
        res = pit.compute_opponent_latent_features({'w': [('2026-03-05', '777'), ('2026-03-05', '97')]}, _core(), _uefa())['w']
        self.assertEqual((res['matches_total'], res['matches_covered']), (2, 0))
        for k in ('opp_latent_attack_mean', 'opp_latent_defence_mean', 'opp_support_min', 'opp_support_mean'):
            self.assertIsNone(res[k])

    def test_empty_core_history_is_rejected(self):
        with self.assertRaises(ValueError):
            pit.build_pit_lookup([('1', '2026-01-01')], [], [])


class BatchCorrectnessTest(unittest.TestCase):

    def test_batch_equals_one_by_one(self):
        req = [('10', '2026-02-15'), ('99', '2026-03-05'), ('98', '2026-03-05'), ('30', '2026-02-12')]
        batch = pit.build_pit_lookup(req, _core(), _uefa())
        for r in req:
            self.assertEqual(batch[(r[0], r[1])], pit.build_pit_lookup([r], _core(), _uefa())[(r[0], r[1])], r)

    def test_noncore_estimate_matches_the_production_snapshot_function(self):
        # Parity: PIT at a date after all history == compute_current_anchored_snapshot() on the same history.
        snap = anchored.compute_current_anchored_snapshot(_core(), _uefa())
        by_id = {str(t['team_id']): t for t in snap['teams']}
        day = '2026-06-01'
        lk = pit.build_pit_lookup([(t, day) for t in ('10', '20', '30', '99', '98', '97')], _core(), _uefa())
        for t in ('10', '20', '30', '99', '98'):
            self.assertAlmostEqual(lk[(t, day)][0], by_id[t]['attack'], places=12, msg=t)
            self.assertAlmostEqual(lk[(t, day)][1], by_id[t]['defence'], places=12, msg=t)
        self.assertIsNone(lk[('97', day)])
        self.assertNotIn('97', by_id)

    def test_isolated_noncore_team_is_not_zeroed_by_other_noncore_opponents(self):
        # 98 only played non-core 99: a target-only call would give 0.0; the probe rows must give the joint fit.
        lk = pit.build_pit_lookup([('98', '2026-03-05')], _core(), _uefa())
        att, de = lk[('98', '2026-03-05')]
        self.assertNotEqual((att, de), (0.0, 0.0))

    def test_support_counts_direct_history_matches(self):
        res = pit.compute_opponent_latent_features({'w': [('2026-03-05', '99'), ('2026-03-05', '10')]}, _core(), _uefa())['w']
        self.assertEqual(sorted(res['opp_support_matches']), [2, 5])  # 99: 2 UEFA matches; 10: 4 core + 1 UEFA


class TrainingPredictionSharedLogicTest(unittest.TestCase):

    def test_recent_window_is_strictly_before_and_newest_first(self):
        tm = [{'kickoff_at': '2026-03-01T18:00:00+00:00', 'opponent_id': 10},
              {'kickoff_at': '2026-02-01T18:00:00+00:00', 'opponent_id': 99},
              {'kickoff_at': '2026-03-10T18:00:00+00:00', 'opponent_id': 20}]
        self.assertEqual(pit.recent_window(tm, '2026-03-10 18:00:00', n=2), [('2026-03-01', '10'), ('2026-02-01', '99')])
        self.assertEqual(pit.recent_window(tm, '2026-03-10T18:00:01', n=1), [('2026-03-10', '20')])

    def test_features_of_a_past_example_do_not_change_when_later_history_is_added(self):
        win = {'w': [('2026-02-15', '99'), ('2026-02-12', '10')]}
        a = pit.compute_opponent_latent_features(win, _core(), _uefa())
        later_core = _core() + [_row('2026-05-01', '10', '30', 4, 0)]
        later_uefa = _uefa() + [_row('2026-05-02', '99', '10', 0, 5)]
        b = pit.compute_opponent_latent_features(win, later_core, later_uefa)
        self.assertEqual(a, b)


if __name__ == '__main__':
    unittest.main()
