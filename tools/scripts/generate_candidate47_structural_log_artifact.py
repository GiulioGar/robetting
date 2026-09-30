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

Train: ALL 3511 rows from dataset_core_v1_2024_2025.csv (2024/25 + 2025/26),
same population as prediction_engine_candidate44_bp.json — production artifacts
in this project are trained on the full available history, not a held-out split
(the TRAIN/TEST split was used only for the P16A ablation comparison).

Recomputes from persistent repo inputs (fully reproducible offline):
  - point-in-time TOP25 structural values via tools/structural/historical_top25.py
    (tools/datasets/structural/p16a_team_club_map.tsv +
     tools/datasets/structural/player_valuations_extract.csv.gz) — P16A methodology
  - walk-forward latent Attack/Defence fit (hard sum-to-zero + analytic jac),
    same methodology as generate_candidate44_bp_artifact.py

Output: tools/models/prediction_engine_candidate47_structural_log.json
        tests/Fixtures/Prediction/candidate47_structural_log_golden.json
        (Python-computed lambdas/probabilities for PHP train/runtime parity tests)
Does NOT overwrite any other artifact.
"""

import json
import csv
import math
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from scipy.optimize import minimize
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/structural'))
import historical_top25  # noqa: E402

art39 = json.loads((ROOT / 'tools/models/prediction_engine_no_e9_no_e10.json').read_text())
FEATS39 = art39['features']
FEATS40 = FEATS39 + ['elo_gap_signed_square']
LATENT_NAMES = ['latent_attack_home', 'latent_defence_home', 'latent_attack_away', 'latent_defence_away']
STRUCTURAL_NAMES = ['structural_log_home', 'structural_log_away', 'structural_log_ratio']
FEATS44 = FEATS40 + LATENT_NAMES
FEATS47 = FEATS44 + STRUCTURAL_NAMES

ELO_H = 'core_elo_home_pre_match_elo'
ELO_A = 'core_elo_away_pre_match_elo'
LAMBDA3 = 0.15
MISSING30_THRESHOLD = 30
MISSING30_FEATS = {'core_schedule_home_rest_days', 'core_schedule_away_rest_days'}
LATENT_PRIOR_N = 3
MAXITER_WF = 300

REST_H_IDX = FEATS39.index('core_schedule_home_rest_days')
REST_A_IDX = FEATS39.index('core_schedule_away_rest_days')

with open(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv', newline='', encoding='utf-8') as f:
    all_rows_raw = list(csv.DictReader(f))


def valid(r):
    return (r.get('label_home_goals', '') != '' and r.get('label_away_goals', '') != '' and r.get(ELO_H, '') != '')


rows = sorted([r for r in all_rows_raw if valid(r)], key=lambda r: r['kickoff_at'])
assert len(rows) == 3511, f"Expected 3511 rows, got {len(rows)}"
print(f"Training rows: {len(rows)}")

# ─── Structural features: point-in-time TOP25 recomputed from repo inputs ───
# Values are per-match point-in-time (only valuations before kickoff), so the
# result does not depend on which other matches are swept alongside.
structural_by_match = {
    mid: {k: ('' if v is None else str(v)) for k, v in vals.items()}
    for mid, vals in historical_top25.top25_point_in_time(
        rows, historical_top25.load_valuation_events(), historical_top25.load_team_club_map()
    ).items()
}

n_missing_struct = sum(1 for r in rows if structural_by_match.get(r['match_id'], {}).get('structural_home', '') in ('', 'None'))
print(f"Rows missing structural data (median-imputed): {n_missing_struct} / {len(rows)}")

# ─── WALK-FORWARD LATENT FIT (identical to generate_candidate44_bp_artifact.py) ──

team_ids = sorted(set(r['home_team_id'] for r in rows) | set(r['away_team_id'] for r in rows))
team_idx = {t: i for i, t in enumerate(team_ids)}
N_TEAMS = len(team_ids)
print(f"Teams: {N_TEAMS}")


def expand(free):
    return np.concatenate([free, [-free.sum()]])


def fit_latent(matches, prior_n=LATENT_PRIOR_N, x0=None, maxiter=MAXITER_WF):
    n = len(matches)
    if n == 0:
        return 0.35, 0.20, np.zeros(N_TEAMS), np.zeros(N_TEAMS)
    homes = np.array([m[0] for m in matches], dtype=np.int64)
    aways = np.array([m[1] for m in matches], dtype=np.int64)
    xh = np.array([m[2] for m in matches], dtype=np.float64)
    xa = np.array([m[3] for m in matches], dtype=np.float64)

    def neg_wll(params):
        bh, ba = params[0], params[1]
        att = expand(params[2:2 + (N_TEAMS - 1)])
        def_ = expand(params[2 + (N_TEAMS - 1):2 + 2 * (N_TEAMS - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        wll = (xh * log_lh - lh_v + xa * log_la - la_v).sum()
        return -(wll) + prior_n * (att ** 2).sum() + prior_n * (def_ ** 2).sum()

    def analytic_gradient(params):
        bh, ba = params[0], params[1]
        att = expand(params[2:2 + (N_TEAMS - 1)])
        def_ = expand(params[2 + (N_TEAMS - 1):2 + 2 * (N_TEAMS - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        rh = xh - lh_v
        ra = xa - la_v
        g_bh = -rh.sum()
        g_ba = -ra.sum()
        g_att_full = -(np.bincount(homes, weights=rh, minlength=N_TEAMS) + np.bincount(aways, weights=ra, minlength=N_TEAMS)) + 2 * prior_n * att
        g_def_full = -(np.bincount(aways, weights=rh, minlength=N_TEAMS) + np.bincount(homes, weights=ra, minlength=N_TEAMS)) + 2 * prior_n * def_
        return np.concatenate([[g_bh, g_ba], g_att_full[:-1] - g_att_full[-1], g_def_full[:-1] - g_def_full[-1]])

    if x0 is None:
        x0v = np.zeros(2 + 2 * (N_TEAMS - 1))
        x0v[0] = 0.35
        x0v[1] = 0.20
    else:
        bh0, ba0, att0, def0 = x0
        x0v = np.concatenate([[bh0, ba0], att0[:-1], def0[:-1]])

    res = minimize(neg_wll, x0v, method='L-BFGS-B', jac=analytic_gradient,
                    options={'maxiter': maxiter, 'ftol': 1e-10, 'gtol': 1e-7})
    bh = float(res.x[0])
    ba = float(res.x[1])
    att = expand(res.x[2:2 + (N_TEAMS - 1)])
    def_ = expand(res.x[2 + (N_TEAMS - 1):2 + 2 * (N_TEAMS - 1)])
    return bh, ba, att, def_


date_groups = defaultdict(list)
for idx, r in enumerate(rows):
    date_groups[r['kickoff_at'][:10]].append((idx, r))
sorted_dates = sorted(date_groups.keys())

prior_matches_list = []
latent_feats = {}
current_params = None
fits_done = 0
import time
t0 = time.perf_counter()
for date in sorted_dates:
    group = date_groups[date]
    if len(prior_matches_list) == 0:
        bh, ba, att, def_ = fit_latent([], x0=current_params)
    else:
        m_arr = [(team_idx[m[0]], team_idx[m[1]], m[2], m[3]) for m in prior_matches_list]
        bh, ba, att, def_ = fit_latent(m_arr, x0=current_params)
        fits_done += 1
    current_params = (bh, ba, att, def_)
    for idx, r in group:
        hi, ai = team_idx.get(r['home_team_id']), team_idx.get(r['away_team_id'])
        latent_feats[idx] = (0.0, 0.0, 0.0, 0.0) if hi is None or ai is None else \
            (float(att[hi]), float(def_[hi]), float(att[ai]), float(def_[ai]))
    for idx, r in group:
        prior_matches_list.append((r['home_team_id'], r['away_team_id'],
                                    int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))
print(f"Walk-forward fits: {fits_done}  elapsed: {time.perf_counter()-t0:.2f}s")

# ─── FEATURE MATRIX (47 cols) with MISSING30 ────────────────────────────────

X = np.zeros((len(rows), 47), dtype=np.float64)
for i, r in enumerate(rows):
    for j, f in enumerate(FEATS39):
        raw = r.get(f, '')
        if raw == '':
            X[i, j] = np.nan
        else:
            val = float(raw)
            if j in (REST_H_IDX, REST_A_IDX) and val > MISSING30_THRESHOLD:
                X[i, j] = np.nan
            else:
                X[i, j] = val
    elo_h = float(r[ELO_H])
    elo_a = float(r[ELO_A])
    gap = elo_h - elo_a
    X[i, 39] = math.copysign(gap * gap, gap)
    lf = latent_feats[i]
    X[i, 40] = lf[0]
    X[i, 41] = lf[1]
    X[i, 42] = lf[2]
    X[i, 43] = lf[3]

    sr = structural_by_match.get(r['match_id'], {})
    sh = sr.get('structural_home', '')
    sa = sr.get('structural_away', '')
    if sh not in ('', 'None') and sa not in ('', 'None') and float(sh) > 0 and float(sa) > 0:
        sh_v, sa_v = float(sh), float(sa)
        X[i, 44] = math.log(sh_v)
        X[i, 45] = math.log(sa_v)
        X[i, 46] = math.log(sh_v / sa_v)
    else:
        X[i, 44] = X[i, 45] = X[i, 46] = np.nan

y_h = np.array([float(r['label_home_goals']) for r in rows])
y_a = np.array([float(r['label_away_goals']) for r in rows])

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
    'training_matches': len(rows),
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

out_path = ROOT / 'tools/models/prediction_engine_candidate47_structural_log.json'
out_path.write_text(json.dumps(artifact, indent=2))
print(f"\nSaved: {out_path}")
print(f"Features: {len(FEATS47)}  (39 baseline + 1 elo-derived + 4 latent + 3 structural log)")
print(f"Score matrix: BivariatePoissonKN  lambda3={LAMBDA3}")

# ─── GOLDEN FIXTURE for PHP train/runtime parity ─────────────────────────────
# Raw inputs exactly as the PHP runtime receives them (39 features pre-MISSING30,
# latent values, raw TOP25 euros) + the Python model's own predictions.

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


candidates = [i for i, r in enumerate(rows) if not np.isnan(X[i, 44])]
picked = [candidates[int(k * (len(candidates) - 1) / 5)] for k in range(6)]
lam_h_all = model_h.predict(X_sc)
lam_a_all = model_a.predict(X_sc)
cases = []
for i in picked:
    r = rows[i]
    sr = structural_by_match[r['match_id']]
    ph, pd, pa = bp_1x2(float(lam_h_all[i]), float(lam_a_all[i]))
    cases.append({
        'match_id': int(r['match_id']),
        'kickoff_at': r['kickoff_at'],
        'features39': {f: (None if r.get(f, '') == '' else float(r[f])) for f in FEATS39},
        'latent': dict(zip(LATENT_NAMES, [float(v) for v in latent_feats[i]])),
        'top25_home': float(sr['structural_home']),
        'top25_away': float(sr['structural_away']),
        'expected': {
            'structural_log_home': float(X[i, 44]),
            'structural_log_away': float(X[i, 45]),
            'structural_log_ratio': float(X[i, 46]),
            'lambda_home': float(lam_h_all[i]),
            'lambda_away': float(lam_a_all[i]),
            'probability_home': ph,
            'probability_draw': pd,
            'probability_away': pa,
        },
    })

golden_path = ROOT / 'tests/Fixtures/Prediction/candidate47_structural_log_golden.json'
golden_path.write_text(json.dumps({'artifact': out_path.name, 'cases': cases}, indent=2))
print(f"Saved golden fixture: {golden_path} ({len(cases)} cases)")
