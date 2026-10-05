"""
Generate the "ROBETTING CANDIDATE V2 LOG" artifact (P18D, validated in P18C).

Candidate47 = Candidate44 (Elo + Latent Attack/Defence + Recent + Context +
              MISSING30 + Bivariate Poisson lambda3=0.15)
            + 3 LOG Structural Strength features (same P16A point-in-time
              TOP25 market value as Candidate48, only the representation changes):
              structural_log_home  = ln(top25_home)
              structural_log_away  = ln(top25_away)
              structural_log_ratio = ln(top25_home / top25_away)

Same pipeline as generate_candidate48_structural_artifact.py except the 4 raw
structural columns are replaced by the 3 log columns and the structural values
are recomputed from repo inputs instead of a session scratchpad. No tuning.

Default (no --dataset): trains on ALL 3511 rows from dataset_core_v1_2024_2025.csv
(2024/25 + 2025/26), same population as prediction_engine_candidate44_bp.json —
production artifacts in this project are trained on the full available history,
not a held-out split (the TRAIN/TEST split was used only for the P16A ablation
comparison). Row construction (39 raw baseline features + walk-forward Latent
Attack/Defence + point-in-time TOP25 Structural) now lives in
candidate47_feature_pipeline.py (P27E2/F-A refactor, zero methodology change —
verbatim ported, see that module's docstring) instead of being duplicated here.

--dataset <path> (P27E2/F-A): train on an already-built combined dataset CSV
(tools/scripts/build_candidate47_learning_dataset.py output — historical rows
+ resolved official predictions' frozen pre-match features) instead. Skips the
historical_top25/walk-forward recomputation entirely (those 47 raw feature
values are already present in the file for every row, regardless of source).
MISSING30 gating / median imputation / StandardScaler / Poisson GLM fit are
UNCHANGED and applied identically either way, directly on the raw feature
rows — this is the only behavior-preserving requirement of this refactor.

Output: tools/models/prediction_engine_candidate47_structural_log.json
        tests/Fixtures/Prediction/candidate47_structural_log_golden.json
        (Python-computed lambdas/probabilities for PHP train/runtime parity tests)
Does NOT overwrite any other artifact.
"""

import argparse
import json
import math
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import candidate47_feature_pipeline as pipe  # noqa: E402

FEATS47 = pipe.FEATS47
LATENT_NAMES = pipe.LATENT_NAMES
STRUCTURAL_NAMES = pipe.STRUCTURAL_NAMES
LAMBDA3 = pipe.LAMBDA3
MISSING30_THRESHOLD = pipe.MISSING30_THRESHOLD
MISSING30_FEATS = set(pipe.MISSING30_FEATURES)

parser = argparse.ArgumentParser()
parser.add_argument('--dataset', default=None,
                     help='Optional pre-built combined dataset CSV (P27E2/F-A, '
                          'build_candidate47_learning_dataset.py output). If omitted, '
                          'recomputes from tools/datasets/dataset_core_v1_2024_2025.csv as before.')
parser.add_argument('--output-artifact', default=None,
                     help='Override the artifact output path (for tests only — default is the real '
                          'production path, tools/models/prediction_engine_candidate47_structural_log.json).')
parser.add_argument('--golden-fixture', default=None,
                     help='Override the golden fixture output path (for tests only).')
args = parser.parse_args()

if args.dataset:
    raw_rows = pipe.load_combined_dataset(args.dataset)
    print(f"Training rows (from --dataset {args.dataset}): {len(raw_rows)}")
    by_source = {}
    for r in raw_rows:
        by_source[r['source_kind']] = by_source.get(r['source_kind'], 0) + 1
    print(f"By source: {by_source}")
else:
    hist_rows = pipe.load_historical_rows(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv')
    assert len(hist_rows) == 3511, f"Expected 3511 rows, got {len(hist_rows)}"
    print(f"Training rows: {len(hist_rows)}")
    raw_rows = pipe.build_raw_feature_rows(hist_rows, verbose=True)

    n_missing_struct = sum(1 for r in raw_rows if r['structural_log_home'] is None)
    print(f"Rows missing structural data (median-imputed): {n_missing_struct} / {len(raw_rows)}")

# ─── FEATURE MATRIX (47 cols) with MISSING30, applied uniformly regardless of source ──

X = np.zeros((len(raw_rows), 47), dtype=np.float64)
for i, r in enumerate(raw_rows):
    for j, f in enumerate(FEATS47):
        v = r[f]
        if v is None:
            X[i, j] = np.nan
        elif f in MISSING30_FEATS and float(v) > MISSING30_THRESHOLD:
            X[i, j] = np.nan
        else:
            X[i, j] = float(v)

y_h = np.array([r['label_home_goals'] for r in raw_rows], dtype=np.float64)
y_a = np.array([r['label_away_goals'] for r in raw_rows], dtype=np.float64)

medians = np.nanmedian(X, axis=0)
X_imp = np.where(np.isnan(X), medians, X)

scaler = StandardScaler()
X_sc = scaler.fit_transform(X_imp)

print("Fitting home model...")
model_h = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc, y_h)
print("Fitting away model...")
model_a = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc, y_a)

print(f"Home model converged: {model_h.n_iter_} iters")
print(f"Away model converged: {model_a.n_iter_} iters")

artifact = {
    'model_version': '1.0.0',
    'feature_set_version': 'core_v1_candidate47_structural_log',
    'trained_seasons': [2024, 2025],
    'training_matches': len(raw_rows),
    'trained_at': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'algorithm': 'PoissonRegressor',
    'alpha': 1.0,
    'score_matrix': 'BivariatePoissonKN',
    'lambda3': LAMBDA3,
    'missing30_threshold': MISSING30_THRESHOLD,
    'missing30_features': sorted(MISSING30_FEATS),
    'features': FEATS47,
    'latent_features': LATENT_NAMES,
    'structural_features': STRUCTURAL_NAMES,
    'derived_features': {
        'elo_gap_signed_square': 'copysign((elo_home - elo_away)^2, elo_home - elo_away)',
        'structural_log_home': 'ln(top25_home)',
        'structural_log_away': 'ln(top25_away)',
        'structural_log_ratio': 'ln(top25_home / top25_away)',
    },
    'structural_definition': 'TOP25 sum of market_value_in_eur for the club roster point-in-time '
                              '(P16A methodology, dcaribou/transfermarkt-datasets player_valuations).',
    'imputer': {
        'strategy': 'median',
        'values': medians.tolist(),
    },
    'scaler': {
        'type': 'StandardScaler',
        'mean': scaler.mean_.tolist(),
        'scale': scaler.scale_.tolist(),
    },
    'home_model': {
        'intercept': float(model_h.intercept_),
        'coefficients': model_h.coef_.tolist(),
    },
    'away_model': {
        'intercept': float(model_a.intercept_),
        'coefficients': model_a.coef_.tolist(),
    },
}

out_path = Path(args.output_artifact) if args.output_artifact else ROOT / 'tools/models/prediction_engine_candidate47_structural_log.json'
out_path.write_text(json.dumps(artifact, indent=2))
print(f"\nSaved: {out_path}")
print(f"Features: {len(FEATS47)}  (39 baseline + 1 elo-derived + 4 latent + 3 structural log)")
print(f"Score matrix: BivariatePoissonKN  lambda3={LAMBDA3}")

# ─── GOLDEN FIXTURE for PHP train/runtime parity ─────────────────────────────
# Raw inputs exactly as the PHP runtime receives them (39 features pre-MISSING30,
# latent values, raw TOP25 euros) + the Python model's own predictions.
# Only meaningful/generated for the historical (no --dataset) path, since it
# needs the raw pre-log TOP25 euro values that the combined CSV does not carry.

MAX_GOALS = 10
_lf = [0.0]
for k in range(1, MAX_GOALS + 1):
    _lf.append(_lf[-1] + math.log(k))


def bp_1x2(lh, la, l3=LAMBDA3):
    l3 = min(l3, min(lh, la) * 0.9999)
    l1, l2 = lh - l3, la - l3
    lr = math.log(l3) - math.log(l1) - math.log(l2)
    const = -(l1 + l2 + l3)
    p = [0.0, 0.0, 0.0]
    for x in range(MAX_GOALS + 1):
        for y in range(MAX_GOALS + 1):
            inner = 1.0
            for k in range(1, min(x, y) + 1):
                inner += math.exp((_lf[x] - _lf[k] - _lf[x - k]) + (_lf[y] - _lf[k] - _lf[y - k]) + _lf[k] + k * lr)
            v = math.exp(const + x * math.log(l1) - _lf[x] + y * math.log(l2) - _lf[y]) * inner
            p[0 if x > y else (1 if x == y else 2)] += v
    s = sum(p)
    return [q / s for q in p]


if not args.dataset:
    candidates = [i for i, r in enumerate(raw_rows) if r['structural_log_home'] is not None]
    picked = [candidates[int(k * (len(candidates) - 1) / 5)] for k in range(6)]
    lam_h_all = model_h.predict(X_sc)
    lam_a_all = model_a.predict(X_sc)
    cases = []
    for i in picked:
        r = raw_rows[i]
        ph, pd, pa = bp_1x2(float(lam_h_all[i]), float(lam_a_all[i]))
        cases.append({
            'match_id': int(r['match_id']),
            'kickoff_at': r['kickoff_at'],
            'features39': {f: r[f] for f in pipe.FEATS39},
            'latent': {name: r[name] for name in LATENT_NAMES},
            'top25_home': math.exp(r['structural_log_home']),
            'top25_away': math.exp(r['structural_log_away']),
            'expected': {
                'structural_log_home': r['structural_log_home'],
                'structural_log_away': r['structural_log_away'],
                'structural_log_ratio': r['structural_log_ratio'],
                'lambda_home': float(lam_h_all[i]),
                'lambda_away': float(lam_a_all[i]),
                'probability_home': ph,
                'probability_draw': pd,
                'probability_away': pa,
            },
        })

    golden_path = Path(args.golden_fixture) if args.golden_fixture else ROOT / 'tests/Fixtures/Prediction/candidate47_structural_log_golden.json'
    golden_path.write_text(json.dumps({'artifact': out_path.name, 'cases': cases}, indent=2))
    print(f"Saved golden fixture: {golden_path} ({len(cases)} cases)")
else:
    print("Skipped golden fixture regeneration (--dataset path).")
