"""
Step C-LATENT-ANCHORED — targeted tests for tools/scripts/latent_anchored.py.

Uses only the stdlib unittest. Run with:
  py -3 -m unittest tools.scripts.tests.test_latent_anchored -v
(from the repo root).
"""
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import candidate47_feature_pipeline as pipe  # noqa: E402
import latent_anchored as anchored  # noqa: E402


def _row(kickoff, home, away, hg, ag):
    return {
        'kickoff_at': kickoff,
        'home_team_id': home,
        'away_team_id': away,
        'label_home_goals': str(hg),
        'label_away_goals': str(ag),
    }


class ComputeLatentFeaturesAnchoredTest(unittest.TestCase):

    def test_core_values_identical_to_legacy(self):
        # Two core teams (10, 20) with a bit of history; one target match.
        core_rows = [
            _row('2026-01-01T00:00:00Z', '10', '20', 2, 1),
            _row('2026-01-08T00:00:00Z', '20', '10', 1, 1),
        ]
        target = [_row('2026-01-15T00:00:00Z', '10', '20', 0, 0)]

        legacy = pipe.compute_latent_features(core_rows + target)
        # Legacy index 2 is our target row (appended after the 2 history rows).
        legacy_target_feats = legacy[2]

        anchored_feats = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=None,
        )

        self.assertEqual(anchored_feats[0], legacy_target_feats)

    def test_directly_connected_noncore_is_estimated(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        # Non-core team 99 plays core team 10 before the target.
        uefa_rows = [_row('2026-02-01T00:00:00Z', '10', '99', 1, 0)]
        target = [_row('2026-03-01T00:00:00Z', '20', '99', 1, 1)]

        feats = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=uefa_rows,
        )

        att_home, def_home, att_away, def_away = feats[0]
        # Home (20) is core -> never None.
        self.assertIsNotNone(att_home)
        self.assertIsNotNone(def_home)
        # Away (99) is non-core but directly connected to core team 10 -> estimated.
        self.assertIsNotNone(att_away)
        self.assertIsNotNone(def_away)
        self.assertIsInstance(att_away, float)

    def test_indirectly_connected_noncore_is_estimated(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        uefa_rows = [
            _row('2026-02-01T00:00:00Z', '10', '98', 1, 0),   # 98 <-> core
            _row('2026-02-10T00:00:00Z', '98', '99', 0, 0),   # 99 <-> 98 (indirect to core)
        ]
        target = [_row('2026-03-01T00:00:00Z', '20', '99', 1, 1)]

        feats = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=uefa_rows,
        )

        _att_h, _def_h, att_away, def_away = feats[0]
        self.assertIsNotNone(att_away)
        self.assertIsNotNone(def_away)

    def test_disconnected_component_remains_none(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        # 77 and 78 only ever play each other — never connect to core.
        uefa_rows = [_row('2026-02-01T00:00:00Z', '77', '78', 1, 1)]
        target = [_row('2026-03-01T00:00:00Z', '20', '77', 1, 0)]

        feats = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=uefa_rows,
        )

        att_home, def_home, att_away, def_away = feats[0]
        self.assertIsNotNone(att_home)   # core side always present
        self.assertIsNotNone(def_home)
        self.assertIsNone(att_away)      # disconnected non-core -> None
        self.assertIsNone(def_away)

    def test_future_uefa_match_never_influences_an_earlier_target(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        # Connects team 99 to core, but AFTER the target's kickoff.
        uefa_rows_future = [_row('2026-06-01T00:00:00Z', '10', '99', 1, 0)]
        target = [_row('2026-03-01T00:00:00Z', '20', '99', 1, 1)]

        feats_with_future = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=uefa_rows_future,
        )
        feats_without_any_uefa = anchored.compute_latent_features_anchored(
            rows=target, core_history_rows=core_rows, uefa_history_rows=None,
        )

        # Must be identical: the future UEFA match must not leak into the past target.
        self.assertEqual(feats_with_future, feats_without_any_uefa)
        # And team 99 (not yet connected as of the target's date) stays None.
        _att_h, _def_h, att_away, def_away = feats_with_future[0]
        self.assertIsNone(att_away)
        self.assertIsNone(def_away)

    def test_legacy_path_untouched_by_this_module(self):
        # compute_latent_features() itself must remain byte-identical whether
        # or not latent_anchored has been imported alongside it.
        rows = [
            _row('2026-01-01T00:00:00Z', '10', '20', 2, 1),
            _row('2026-01-08T00:00:00Z', '20', '10', 1, 1),
        ]
        before = pipe.compute_latent_features(rows)
        _ = anchored.compute_latent_features_anchored(
            rows=rows, core_history_rows=rows, uefa_history_rows=None,
        )
        after = pipe.compute_latent_features(rows)
        self.assertEqual(before, after)


class ComputeCurrentAnchoredSnapshotTest(unittest.TestCase):

    def test_core_values_match_legacy_final_state(self):
        core_rows = [
            _row('2026-01-01T00:00:00Z', '10', '20', 2, 1),
            _row('2026-01-08T00:00:00Z', '20', '10', 1, 1),
        ]
        snap = anchored.compute_current_anchored_snapshot(core_rows, uefa_history_rows=None)

        by_tid = {t['team_id']: t for t in snap['teams']}
        self.assertEqual(set(by_tid.keys()), {10, 20})

        # Cross-check against compute_latent_features_anchored(): a target
        # dated strictly AFTER the last core match must see the identical
        # frozen core state as this "current" snapshot.
        probe_target = [_row('2026-06-01T00:00:00Z', '10', '20', 0, 0)]
        probe = anchored.compute_latent_features_anchored(probe_target, core_rows, None)
        att_h, def_h, att_a, def_a = probe[0]

        self.assertAlmostEqual(by_tid[10]['attack'], att_h, places=12)
        self.assertAlmostEqual(by_tid[10]['defence'], def_h, places=12)
        self.assertAlmostEqual(by_tid[20]['attack'], att_a, places=12)
        self.assertAlmostEqual(by_tid[20]['defence'], def_a, places=12)

    def test_identified_noncore_is_added_without_touching_core(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        uefa_rows = [_row('2026-02-01T00:00:00Z', '10', '99', 1, 0)]

        snap = anchored.compute_current_anchored_snapshot(core_rows, uefa_rows)
        by_tid = {t['team_id']: t for t in snap['teams']}

        self.assertIn(99, by_tid)
        self.assertEqual(snap['noncore_identified'], ['99'])
        self.assertEqual(set(snap['core_team_ids']), {'10', '20'})

        # Core-only snapshot (no UEFA history at all) must give IDENTICAL
        # core values — adding an identified non-core team never perturbs core.
        core_only_snap = anchored.compute_current_anchored_snapshot(core_rows, None)
        core_only_by_tid = {t['team_id']: t for t in core_only_snap['teams']}
        self.assertEqual(by_tid[10], core_only_by_tid[10])
        self.assertEqual(by_tid[20], core_only_by_tid[20])

    def test_disconnected_noncore_is_absent_not_null(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        uefa_rows = [_row('2026-02-01T00:00:00Z', '77', '78', 1, 1)]  # never touches core

        snap = anchored.compute_current_anchored_snapshot(core_rows, uefa_rows)
        by_tid = {t['team_id']: t for t in snap['teams']}

        self.assertNotIn(77, by_tid)
        self.assertNotIn(78, by_tid)
        self.assertEqual(snap['noncore_identified'], [])

    def test_schema_matches_latent_strength_current_shape(self):
        core_rows = [_row('2026-01-01T00:00:00Z', '10', '20', 2, 1)]
        uefa_rows = [_row('2026-02-01T00:00:00Z', '10', '99', 1, 0)]

        snap = anchored.compute_current_anchored_snapshot(core_rows, uefa_rows)

        for entry in snap['teams']:
            self.assertEqual(set(entry.keys()), {'team_id', 'attack', 'defence'})
            self.assertIsInstance(entry['team_id'], int)
            self.assertIsInstance(entry['attack'], float)
            self.assertIsInstance(entry['defence'], float)


if __name__ == '__main__':
    unittest.main()
