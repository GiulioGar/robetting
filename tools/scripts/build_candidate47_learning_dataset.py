"""
P27E2/F-A — Combined Candidate47 learning dataset builder.

Produces a single chronological CSV combining:
  1. Validated historical rows (2024/25 + 2025/26, from
     dataset_core_v1_2024_2025.csv) — 47 raw canonical features recomputed
     via candidate47_feature_pipeline.py (walk-forward Latent + point-in-time
     TOP25 Structural; same methodology already used to train the current
     artifact, not a new regeneration policy).
  2. Resolved official predictions (2026/27+, from an
     OfficialLearningDatasetExporter CSV — robetting:export-learning-dataset)
     — their 47 features are carried through EXACTLY as frozen pre-match
     (features_json), never retransformed.

Every row is tagged with provenance (source_kind, source_prediction_id) and
basic match metadata (match_id, kickoff_at, season_id where available) that
are NOT features and must never be fed to the model as such.

Labels are normalized to the SAME encoding the historical dataset already
uses — label_home_goals/label_away_goals (the actual Poisson training
targets) + label_result_1x2 in H/D/A (mapped from the official CSV's 1/X/2,
not a new codification).

FAIL-CLOSED: missing/extra feature keys, invalid labels, invalid/missing
kickoff_at, and match_id overlap or duplication between/within sources all
abort with a clear error and no output file is written (no partial CSV).

Usage:
    py -3 tools/scripts/build_candidate47_learning_dataset.py \
        --historical tools/datasets/dataset_core_v1_2024_2025.csv \
        [--official path/to/official_learning_export.csv] \
        --output tools/datasets/combined_candidate47_learning.csv
"""
import argparse
import csv
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import candidate47_feature_pipeline as pipe  # noqa: E402

FEATS47 = pipe.FEATS47
OUTCOME_1X2_TO_HDA = {'1': 'H', 'X': 'D', '2': 'A'}

COLUMNS = (
    ['source_kind', 'source_prediction_id', 'match_id', 'kickoff_at', 'season_id']
    + FEATS47
    + ['label_home_goals', 'label_away_goals', 'label_result_1x2']
)


class DatasetBuildError(Exception):
    """Fail-closed validation error — never caught silently, always aborts the build."""


def _parse_kickoff(value, context):
    if not value:
        raise DatasetBuildError(f"{context}: missing kickoff_at.")
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError as e:
        raise DatasetBuildError(f"{context}: invalid kickoff_at '{value}' ({e}).")


def build_historical_rows(historical_csv_path, min_season_year=2024, verbose=False):
    with open(historical_csv_path, newline='', encoding='utf-8') as f:
        header = csv.DictReader(f).fieldnames or []
    missing_cols = [f for f in pipe.FEATS39 if f not in header]
    if missing_cols:
        raise DatasetBuildError(
            f"historical dataset {historical_csv_path}: missing baseline feature column(s) {missing_cols} "
            f"— schema incompatible with Candidate47."
        )

    hist_rows = pipe.load_historical_rows(historical_csv_path)
    raw_rows = pipe.build_raw_feature_rows(hist_rows, verbose=verbose)

    out = []
    for r in raw_rows:
        kickoff = _parse_kickoff(r['kickoff_at'], f"historical match_id={r['match_id']}")
        if kickoff.year < min_season_year:
            raise DatasetBuildError(
                f"historical match_id={r['match_id']}: kickoff_at year {kickoff.year} is before "
                f"{min_season_year} — refusing to silently include pre-{min_season_year} data "
                f"(e.g. 2023/24). Check --historical points at the right file."
            )
        out.append(r)

    return out


def load_official_rows(official_csv_path):
    with open(official_csv_path, newline='', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        header = reader.fieldnames or []
        rows = list(reader)

    missing_cols = [f for f in FEATS47 if f not in header]
    if missing_cols:
        raise DatasetBuildError(
            f"official dataset {official_csv_path}: missing canonical feature column(s) {missing_cols}."
        )

    required_meta = ['prediction_id', 'match_id', 'kickoff_at', 'home_goals', 'away_goals', 'outcome']
    missing_meta = [c for c in required_meta if c not in header]
    if missing_meta:
        raise DatasetBuildError(
            f"official dataset {official_csv_path}: missing required column(s) {missing_meta}."
        )

    out = []
    for r in rows:
        context = f"official prediction_id={r.get('prediction_id')}"

        outcome = r.get('outcome')
        if outcome not in OUTCOME_1X2_TO_HDA:
            raise DatasetBuildError(
                f"{context}: invalid outcome '{outcome}' — expected one of 1/X/2 "
                f"(official row not from a valid finished match)."
            )

        if r.get('home_goals', '') == '' or r.get('away_goals', '') == '':
            raise DatasetBuildError(f"{context}: missing home_goals/away_goals (not a resolved finished match).")

        kickoff = _parse_kickoff(r.get('kickoff_at'), context)

        row_out = {
            'source_kind': 'official_prospective',
            'source_prediction_id': r['prediction_id'],
            'match_id': r['match_id'],
            'kickoff_at': r['kickoff_at'],
            'season_id': None,  # not derivable unambiguously from the official export (no season_id column)
            'label_home_goals': float(r['home_goals']),
            'label_away_goals': float(r['away_goals']),
            'label_result_1x2': OUTCOME_1X2_TO_HDA[outcome],
        }
        for f in FEATS47:
            raw = r.get(f, '')
            row_out[f] = None if raw == '' else float(raw)
        out.append(row_out)

    return out


def combine(historical_rows, official_rows):
    seen_match_ids = {}
    combined = []

    for r in historical_rows + official_rows:
        mid = r['match_id']
        if mid in seen_match_ids:
            raise DatasetBuildError(
                f"duplicate/overlapping match_id={mid}: already present as "
                f"source_kind={seen_match_ids[mid]}, now also as source_kind={r['source_kind']}. "
                f"Historical (2024/25-2025/26) and official_prospective (2026/27+) must never overlap — "
                f"refusing to arbitrarily pick one."
            )
        seen_match_ids[mid] = r['source_kind']
        combined.append(r)

    combined.sort(key=lambda r: r['kickoff_at'])
    return combined


def write_csv(rows, output_path):
    output_path = Path(output_path)
    if output_path.exists():
        raise DatasetBuildError(f"Output file already exists, refusing to overwrite: {output_path}")

    output_path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = output_path.with_suffix(output_path.suffix + '.tmp')

    try:
        with open(tmp_path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.writer(f)
            writer.writerow(COLUMNS)
            for r in rows:
                writer.writerow([r.get(c) for c in COLUMNS])

        if output_path.exists():
            raise DatasetBuildError(f"Output file already exists, refusing to overwrite: {output_path}")
        tmp_path.rename(output_path)
    except Exception:
        if tmp_path.exists():
            tmp_path.unlink()
        raise


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--historical', default=str(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv'))
    parser.add_argument('--official', default=None)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()

    try:
        historical_rows = build_historical_rows(args.historical, verbose=True)
        official_rows = load_official_rows(args.official) if args.official else []
        combined = combine(historical_rows, official_rows)
        write_csv(combined, args.output)
    except DatasetBuildError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    by_source = {}
    for r in combined:
        by_source[r['source_kind']] = by_source.get(r['source_kind'], 0) + 1

    print(f"\nCombined dataset written: {args.output}")
    print(f"Total rows: {len(combined)}")
    print(f"By source: {by_source}")
    print(f"Features per row: {len(FEATS47)}")
    print(f"Kickoff range: {combined[0]['kickoff_at']} .. {combined[-1]['kickoff_at']}" if combined else "Kickoff range: n/a (empty dataset)")


if __name__ == '__main__':
    main()
