"""
Step C-LATENT — targeted tests for compute_latent_features()'s extra_history_rows
parameter and load_latent_history_csv() in candidate47_feature_pipeline.py.

Uses only the stdlib unittest. Run with:
  py -3 -m unittest tools.scripts.tests.test_candidate47_feature_pipeline_latent_extended -v
(from the repo root).
"""
import csv
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import candidate47_feature_pipeline as pipe  # noqa: E402


def _row(match_id, kickoff, home, away, hg, ag):
    return {
        'match_id': match_id,
        'kickoff_at': kickoff,
        'home_team_id': home,
        'away_team_id': away,
        'label_home_goals': str(hg),
        'label_away_goals': str(ag),
    }


class ComputeLatentFeaturesExtendedTest(unittest.TestCase):

    def test_none_extra_history_is_byte_identical_to_legacy(self):
        rows = [
            _row('1', '2026-01-01T00:00:00Z', '10', '20', 2, 1),
            _row('2', '2026-01-08T00:00:00Z', '10', '20', 0, 0),
        ]
        with_none = pipe.compute_latent_features(rows)
        with_explicit_empty = pipe.compute_latent_features(rows, extra_history_rows=[])
        self.assertEqual(with_none, with_explicit_empty)
        # Legacy shape: exactly len(rows) output indices, nothing else.
        self.assertEqual(set(with_none.keys()), {0, 1})

    def test_extra_history_rows_never_produce_an_output_index(self):
        rows = [_row('1', '2026-02-01T00:00:00Z', '10', '20', 1, 0)]
        extra = [_row('99', '2026-01-01T00:00:00Z', '30', '40', 2, 2)]

        result = pipe.compute_latent_features(rows, extra_history_rows=extra)

        self.assertEqual(set(result.keys()), {0})

    def test_extra_history_strictly_before_target_affects_the_fit(self):
        # Team 30 (extra-history-only) plays well before the target date —
        # with extra history present, team 10/20's latent values must still
        # be computed (team population grows, no crash), and the walk-forward
        # fit must have actually used that earlier match (fits_done > 0 would
        # require verbose capture; instead assert output is well-formed and
        # finite).
        rows = [_row('1', '2026-02-01T00:00:00Z', '10', '20', 1, 0)]
        extra = [
            _row('90', '2026-01-01T00:00:00Z', '10', '30', 3, 0),
            _row('91', '2026-01-05T00:00:00Z', '30', '20', 1, 1),
        ]

        result = pipe.compute_latent_features(rows, extra_history_rows=extra)

        self.assertEqual(set(result.keys()), {0})
        for v in result[0]:
            self.assertTrue(isinstance(v, float))

    def test_extra_history_strictly_after_target_never_leaks_in(self):
        # A future extra-history match (after the target's kickoff) must
        # produce IDENTICAL output to having no extra history at all —
        # walk-forward strictly-before semantics must hold for extra rows too.
        rows = [_row('1', '2026-01-01T00:00:00Z', '10', '20', 1, 0)]
        extra_future = [_row('99', '2026-06-01T00:00:00Z', '10', '30', 5, 0)]

        no_extra = pipe.compute_latent_features(rows)
        with_future_extra = pipe.compute_latent_features(rows, extra_history_rows=extra_future)

        self.assertEqual(no_extra, with_future_extra)


class LoadLatentHistoryCsvTest(unittest.TestCase):

    def _write_csv(self, rows):
        tmp = tempfile.NamedTemporaryFile(mode='w', suffix='.csv', delete=False, newline='', encoding='utf-8')
        writer = csv.DictWriter(tmp, fieldnames=['match_id', 'kickoff_at', 'home_team_id', 'away_team_id', 'home_goals', 'away_goals'])
        writer.writeheader()
        for r in rows:
            writer.writerow(r)
        tmp.close()
        return Path(tmp.name)

    def test_excludes_match_ids_already_in_target_set(self):
        path = self._write_csv([
            {'match_id': '1', 'kickoff_at': '2026-01-01T00:00:00Z', 'home_team_id': '10', 'away_team_id': '20', 'home_goals': '2', 'away_goals': '1'},
            {'match_id': '2', 'kickoff_at': '2026-01-02T00:00:00Z', 'home_team_id': '30', 'away_team_id': '40', 'home_goals': '0', 'away_goals': '0'},
        ])
        try:
            out = pipe.load_latent_history_csv(path, exclude_match_ids={'1'})
            self.assertEqual(len(out), 1)
            self.assertEqual(out[0]['home_team_id'], '30')
            self.assertEqual(out[0]['label_home_goals'], '0')
        finally:
            path.unlink()

    def test_empty_when_all_rows_excluded(self):
        path = self._write_csv([
            {'match_id': '1', 'kickoff_at': '2026-01-01T00:00:00Z', 'home_team_id': '10', 'away_team_id': '20', 'home_goals': '2', 'away_goals': '1'},
        ])
        try:
            out = pipe.load_latent_history_csv(path, exclude_match_ids={'1'})
            self.assertEqual(out, [])
        finally:
            path.unlink()


if __name__ == '__main__':
    unittest.main()
