"""
Generate Candidate 40 artifact.

Candidate 40 = Candidate 39 (39 features) + elo_gap_signed_square (1 derived feature)
  elo_gap_signed_square = sign(elo_home - elo_away) * (elo_home - elo_away)^2

Train: all 3511 rows from dataset_core_v1_2024_2025.csv
alpha=1.0, same preprocessing as production.

Output: tools/models/prediction_engine_candidate40.json
"""

import json
import csv
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[2]

# ── Base feature list from Candidate 39 artifact ──────────────────────────────
art39  = json.loads((ROOT / 'tools/models/prediction_engine_no_e9_no_e10.json').read_text())
FEATS  = art39['features']           # 39 names
EXTRA  = 'elo_gap_signed_square'
FEATS40 = FEATS + [EXTRA]           # 40 names

ELO_H = 'core_elo_home_pre_match_elo'
ELO_A = 'core_elo_away_pre_match_elo'

# ── Load dataset ──────────────────────────────────────────────────────────────
with open(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv',
          newline='', encoding='utf-8') as f:
    all_rows = list(csv.DictReader(f))

rows = [r for r in all_rows
        if r.get('label_home_goals', '') != ''
        and r.get('label_away_goals', '') != ''
        and r.get(ELO_H, '') != '']

assert len(rows) == 3511, f"Expected 3511 rows, got {len(rows)}"
print(f"Training rows: {len(rows)}")

# ── Build feature matrix (40 cols) ─────────────────────────────────────────────
X = np.zeros((len(rows), 40), dtype=np.float64)
for i, r in enumerate(rows):
    # First 39: standard features
    for j, f in enumerate(FEATS):
        raw = r.get(f, '')
        X[i, j] = float(raw) if raw != '' else np.nan
    # 40th: derived from Elo
    elo_h = float(r[ELO_H])
    elo_a = float(r[ELO_A])
    gap   = elo_h - elo_a
    X[i, 39] = math.copysign(gap * gap, gap)

y_h = np.array([float(r['label_home_goals']) for r in rows])
y_a = np.array([float(r['label_away_goals']) for r in rows])

# ── Impute → Scale → Fit ───────────────────────────────────────────────────────
medians = np.nanmedian(X, axis=0)
X_imp   = np.where(np.isnan(X), medians, X)

scaler = StandardScaler()
X_sc   = scaler.fit_transform(X_imp)

print("Fitting home model...")
model_h = PoissonRegressor(alpha=1.0, max_iter=500)
model_h.fit(X_sc, y_h)

print("Fitting away model...")
model_a = PoissonRegressor(alpha=1.0, max_iter=500)
model_a.fit(X_sc, y_a)

print(f"Home model converged: {model_h.n_iter_} iters")
print(f"Away model converged: {model_a.n_iter_} iters")
print(f"elo_gap_signed_square coef: home={model_h.coef_[39]:+.6f}  away={model_a.coef_[39]:+.6f}")

# ── Serialize ─────────────────────────────────────────────────────────────────
artifact = {
    'model_version':       '1.0.0',
    'feature_set_version': 'core_v1_candidate40',
    'trained_seasons':     [2024, 2025],
    'training_matches':    len(rows),
    'trained_at':          datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'algorithm':           'PoissonRegressor',
    'alpha':               1.0,
    'features':            FEATS40,
    'derived_features': {
        EXTRA: 'copysign((elo_home - elo_away)^2, elo_home - elo_away)'
    },
    'imputer': {
        'strategy': 'median',
        'values':   medians.tolist(),
    },
    'scaler': {
        'type':  'StandardScaler',
        'mean':  scaler.mean_.tolist(),
        'scale': scaler.scale_.tolist(),
    },
    'home_model': {
        'intercept':    float(model_h.intercept_),
        'coefficients': model_h.coef_.tolist(),
    },
    'away_model': {
        'intercept':    float(model_a.intercept_),
        'coefficients': model_a.coef_.tolist(),
    },
}

out_path = ROOT / 'tools/models/prediction_engine_candidate40.json'
out_path.write_text(json.dumps(artifact, indent=2))
print(f"\nSaved: {out_path}")
print(f"Features: {len(FEATS40)}  (39 standard + 1 derived)")
