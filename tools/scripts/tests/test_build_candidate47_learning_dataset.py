"""
P27E2/F-A — targeted tests for build_candidate47_learning_dataset.py and the
shared candidate47_feature_pipeline.py, plus a smoke test that
generate_candidate47_structural_log_artifact.py accepts --dataset.

Uses only the stdlib unittest (no pytest installed in this environment).
Run with:  py -3 -m unittest tools.scripts.tests.test_build_candidate47_learning_dataset -v
(from the repo root, or via run_tests.py in this same directory).

Historical fixtures use synthetic team ids never present in
tools/datasets/structural/p16a_team_club_map.tsv, so structural_log_* comes
back None for all synthetic historical rows (expected and harmless — handled
like any other missing feature by downstream median imputation, not a test
failure). No real production artifact or dataset file is ever written to.
"""
import csv
import json
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import candidate47_feature_pipeline as pipe  # noqa: E402
import build_candidate47_learning_dataset as builder  # noqa: E402

PY = sys.executable


def _historical_header():
    return (
        ['match_id', 'kickoff_at', 'season_id', 'home_team_id', 'away_team_id']
        + pipe.FEATS39
        + ['label_home_goals', 'label_away_goals', 'label_result_1x2']
    )


def _historical_row(match_id, kickoff_at, season_id, home_team_id, away_team_id, hg, ag):
    outcome = 'H' if hg > ag else ('D' if hg == ag else 'A')
    row = {
        'match_id': match_id,
        'kickoff_at': kickoff_at,
        'season_id': season_id,
        'home_team_id': home_team_id,
        'away_team_id': away_team_id,
        'label_home_goals': hg,
        'label_away_goals': ag,
        'label_result_1x2': outcome,
    }
    for f in pipe.FEATS39:
        if f == pipe.ELO_H:
            row[f] = '1550'
        elif f == pipe.ELO_A:
            row[f] = '1480'
        elif f in pipe.MISSING30_FEATURES:
            row[f] = '5'
        else:
            row[f] = '1.0'
    return row


def write_historical_csv(path, rows):
    with open(path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=_historical_header())
        writer.writeheader()
        for r in rows:
            writer.writerow(r)


def _official_header():
    return (
        ['prediction_id', 'match_id', 'model_key', 'model_version', 'feature_set_version', 'artifact_sha256',
         'generated_at', 'kickoff_at', 'competition_id']
        + pipe.FEATS47
        + ['lambda_home', 'lambda_away', 'lambda3', 'probability_home', 'probability_draw', 'probability_away']
        + ['home_goals', 'away_goals', 'outcome']
    )


def _official_row(prediction_id, match_id, kickoff_at, hg, ag, outcome, feature_value=0.5):
    row = {
        'prediction_id': prediction_id,
        'match_id': match_id,
        'model_key': 'candidate47_structural_log',
        'model_version': '1.0.0',
        'feature_set_version': 'core_v1_candidate47_structural_log',
        'artifact_sha256': 'a' * 64,
        'generated_at': '2026-08-01T00:00:00+00:00',
        'kickoff_at': kickoff_at,
        'competition_id': 1,
        'lambda_home': 1.4,
        'lambda_away': 1.1,
        'lambda3': 0.15,
        'probability_home': 0.45,
        'probability_draw': 0.28,
        'probability_away': 0.27,
        'home_goals': hg,
        'away_goals': ag,
        'outcome': outcome,
    }
    for i, f in enumerate(pipe.FEATS47):
        row[f] = feature_value + i * 0.001  # distinct, deterministic per-feature value
    return row


def write_official_csv(path, rows):
    with open(path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=_official_header())
        writer.writeheader()
        for r in rows:
            writer.writerow(r)


class BuildCandidate47LearningDatasetTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def _write_historical(self, rows=None):
        rows = rows or [
            _historical_row('1001', '2024-08-01T18:00:00+00:00', '30', '9001', '9002', 2, 0),
            _historical_row('1002', '2024-08-08T18:00:00+00:00', '30', '9002', '9001', 1, 1),
        ]
        path = self.tmp_path / 'historical.csv'
        write_historical_csv(path, rows)
        return path

    def _write_official(self, rows=None):
        rows = rows or [
            _official_row('500', '2001', '2026-08-01T18:00:00+00:00', 2, 1, '1'),
            _official_row('501', '2002', '2026-08-08T18:00:00+00:00', 0, 0, 'X'),
        ]
        path = self.tmp_path / 'official.csv'
        write_official_csv(path, rows)
        return path

    # 1. historical-only valid
    def test_historical_only_valid(self):
        hist_path = self._write_historical()
        rows = builder.build_historical_rows(hist_path)
        self.assertEqual(2, len(rows))
        for r in rows:
            self.assertEqual('historical', r['source_kind'])

    # 2. historical + official valid
    def test_historical_plus_official_valid(self):
        hist_rows = builder.build_historical_rows(self._write_historical())
        off_rows = builder.load_official_rows(self._write_official())
        combined = builder.combine(hist_rows, off_rows)
        self.assertEqual(4, len(combined))

    # 3. 47 feature exact
    def test_exactly_47_features_per_row(self):
        hist_rows = builder.build_historical_rows(self._write_historical())
        off_rows = builder.load_official_rows(self._write_official())
        for r in hist_rows + off_rows:
            present = [f for f in pipe.FEATS47 if f in r]
            self.assertEqual(47, len(present))

    # 4. ordine feature deterministico dall'artifact
    def test_feature_order_matches_artifact(self):
        artifact = json.loads((ROOT / 'tools/models/prediction_engine_candidate47_structural_log.json').read_text())
        self.assertEqual(artifact['features'], pipe.FEATS47)
        self.assertEqual(47, len(artifact['features']))

    # 5. provenance corretta
    def test_provenance_fields(self):
        hist_rows = builder.build_historical_rows(self._write_historical())
        off_rows = builder.load_official_rows(self._write_official())

        for r in hist_rows:
            self.assertEqual('historical', r['source_kind'])
            self.assertIsNone(r['source_prediction_id'])

        for r in off_rows:
            self.assertEqual('official_prospective', r['source_kind'])
            self.assertIsNotNone(r['source_prediction_id'])

    # 6. official feature già trasformate non ritrasformate (exact passthrough)
    def test_official_features_are_not_retransformed(self):
        official_path = self._write_official([_official_row('500', '2001', '2026-08-01T18:00:00+00:00', 2, 1, '1', feature_value=7.0)])
        off_rows = builder.load_official_rows(official_path)
        row = off_rows[0]
        for i, f in enumerate(pipe.FEATS47):
            self.assertAlmostEqual(7.0 + i * 0.001, row[f], places=9)

    # 7. label mapping corretto (1/X/2 -> H/D/A, goals carried through)
    def test_label_mapping(self):
        official_path = self._write_official([
            _official_row('500', '2001', '2026-08-01T18:00:00+00:00', 2, 1, '1'),
            _official_row('501', '2002', '2026-08-02T18:00:00+00:00', 1, 1, 'X'),
            _official_row('502', '2003', '2026-08-03T18:00:00+00:00', 0, 2, '2'),
        ])
        rows = builder.load_official_rows(official_path)
        mapping = {r['match_id']: r['label_result_1x2'] for r in rows}
        self.assertEqual({'2001': 'H', '2002': 'D', '2003': 'A'}, mapping)
        self.assertEqual(2.0, rows[0]['label_home_goals'])
        self.assertEqual(1.0, rows[0]['label_away_goals'])

    # 8. ordine cronologico
    def test_chronological_order(self):
        hist_rows = builder.build_historical_rows(self._write_historical())
        off_rows = builder.load_official_rows(self._write_official())
        combined = builder.combine(hist_rows, off_rows)
        kickoffs = [r['kickoff_at'] for r in combined]
        self.assertEqual(sorted(kickoffs), kickoffs)
        self.assertEqual('historical', combined[0]['source_kind'])
        self.assertEqual('official_prospective', combined[-1]['source_kind'])

    # 9. schema mismatch -> fail (official CSV missing a canonical feature column)
    def test_schema_mismatch_official_missing_column_fails(self):
        path = self.tmp_path / 'official_bad.csv'
        header = [c for c in _official_header() if c != pipe.FEATS47[0]]
        with open(path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=header, extrasaction='ignore')
            writer.writeheader()
            writer.writerow(_official_row('500', '2001', '2026-08-01T18:00:00+00:00', 2, 1, '1'))

        with self.assertRaises(builder.DatasetBuildError):
            builder.load_official_rows(path)

    # 10. feature mancante -> fail (historical CSV missing a baseline feature column)
    def test_missing_feature_historical_schema_fails(self):
        header = [c for c in _historical_header() if c != pipe.FEATS39[0]]
        path = self.tmp_path / 'historical_bad.csv'
        with open(path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=header, extrasaction='ignore')
            writer.writeheader()
            writer.writerow(_historical_row('1001', '2024-08-01T18:00:00+00:00', '30', '9001', '9002', 2, 0))

        with self.assertRaises(builder.DatasetBuildError):
            builder.build_historical_rows(path)

    # 11. label invalida -> fail (outcome not in 1/X/2)
    def test_invalid_label_fails(self):
        path = self._write_official([_official_row('500', '2001', '2026-08-01T18:00:00+00:00', 2, 1, 'INVALID')])
        with self.assertRaises(builder.DatasetBuildError):
            builder.load_official_rows(path)

    # 12. overlap/duplicato -> fail (same match_id in both sources)
    def test_overlap_duplicate_match_id_fails(self):
        hist_rows = builder.build_historical_rows(self._write_historical([
            _historical_row('9999', '2024-08-01T18:00:00+00:00', '30', '9001', '9002', 2, 0),
        ]))
        off_rows = builder.load_official_rows(self._write_official([
            _official_row('500', '9999', '2026-08-01T18:00:00+00:00', 1, 0, '1'),
        ]))
        with self.assertRaises(builder.DatasetBuildError):
            builder.combine(hist_rows, off_rows)

    # pre-2024 historical row is rejected (never silently includes 2023/24)
    def test_pre_2024_historical_row_fails(self):
        with self.assertRaises(builder.DatasetBuildError):
            builder.build_historical_rows(self._write_historical([
                _historical_row('1001', '2023-08-01T18:00:00+00:00', '29', '9001', '9002', 2, 0),
            ]))

    # 13. training script accepts --dataset and uses only the 47 canonical features
    def test_training_script_accepts_dataset_option(self):
        hist_rows = builder.build_historical_rows(self._write_historical())
        off_rows = builder.load_official_rows(self._write_official())
        combined = builder.combine(hist_rows, off_rows)

        dataset_path = self.tmp_path / 'combined.csv'
        builder.write_csv(combined, dataset_path)

        artifact_out = self.tmp_path / 'artifact_test_output.json'
        golden_out = self.tmp_path / 'golden_test_output.json'

        result = subprocess.run(
            [PY, str(ROOT / 'tools/scripts/generate_candidate47_structural_log_artifact.py'),
             '--dataset', str(dataset_path),
             '--output-artifact', str(artifact_out),
             '--golden-fixture', str(golden_out)],
            capture_output=True, text=True, cwd=str(ROOT),
        )

        self.assertEqual(0, result.returncode, msg=result.stdout + result.stderr)
        self.assertTrue(artifact_out.exists())

        artifact = json.loads(artifact_out.read_text())
        self.assertEqual(pipe.FEATS47, artifact['features'])
        self.assertEqual(47, len(artifact['features']))
        self.assertEqual(4, artifact['training_matches'])
        # Real production artifact must never be touched by this test.
        self.assertFalse(golden_out.exists())


if __name__ == '__main__':
    unittest.main()
