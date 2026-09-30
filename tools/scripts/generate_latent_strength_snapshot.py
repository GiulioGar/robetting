"""
Generate the CURRENT Latent Attack/Defence strength snapshot.

Single static fit (NOT walk-forward) over ALL finished matches available
(kickoff < generated_at), using the final Latent Attack/Defence Poisson
fitter: hard sum-to-zero reparametrization + analytic jacobian, xi=0
(uniform weights, no time decay). Verified in P9H/P9I/P9J (gradient check
PASS, L-BFGS-B converges cleanly, ~0.03s per fit).

Output: tools/models/latent_strength_current.json
  generated_at            ISO8601 UTC timestamp of this run
  last_match_included_at  max kickoff_at among matches used
  model_version           fitter identifier
  matches_used            count
  teams: [{team_id, attack, defence}, ...]

This snapshot is for CURRENT/FUTURE match inference only (Candidate44 BP in
admin). It is NOT point-in-time per arbitrary historical kickoff — anti-
leakage for its actual use is enforced at read time in PHP
(CandidateModelService): a match may only use this snapshot if
generated_at < match.kickoff_at.

Re-run this script periodically (e.g. before each matchday) to refresh.
"""

import csv
import json
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from scipy.optimize import minimize

ROOT = Path(__file__).resolve().parents[2]
TRAIN_CSV = ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv'
FWD_CSV   = ROOT / 'tools/datasets/dataset_core_v1_2026.csv'
OUT_PATH  = ROOT / 'tools/models/latent_strength_current.json'

ELO_H = 'core_elo_home_pre_match_elo'
PRIOR_N = 3
MAXITER = 300
MODEL_VERSION = 'latent_ad_v1_hardsumzero_analyticjac'


def load_csv(path):
    with open(path, newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def valid(r):
    return (r.get('label_home_goals', '') != ''
            and r.get('label_away_goals', '') != ''
            and r.get(ELO_H, '') != '')


raw_train = load_csv(TRAIN_CSV)
raw_fwd = load_csv(FWD_CSV)
rows = [r for r in raw_train if valid(r)] + [r for r in raw_fwd if valid(r)]
rows.sort(key=lambda r: r['kickoff_at'])

print(f"Finished matches available: {len(rows)}")
last_kickoff = rows[-1]['kickoff_at']
print(f"Last match kickoff: {last_kickoff}")

team_ids = sorted(set(int(r['home_team_id']) for r in rows) | set(int(r['away_team_id']) for r in rows))
team_idx = {t: i for i, t in enumerate(team_ids)}
N = len(team_ids)
print(f"Teams: {N}")

homes = np.array([team_idx[int(r['home_team_id'])] for r in rows], dtype=np.int64)
aways = np.array([team_idx[int(r['away_team_id'])] for r in rows], dtype=np.int64)
xh = np.array([float(r['label_home_goals']) for r in rows], dtype=np.float64)
xa = np.array([float(r['label_away_goals']) for r in rows], dtype=np.float64)


def expand(free):
    return np.concatenate([free, [-free.sum()]])


def neg_wll(params):
    bh, ba = params[0], params[1]
    att = expand(params[2:2 + (N - 1)])
    def_ = expand(params[2 + (N - 1):2 + 2 * (N - 1)])
    log_lh = bh + att[homes] + def_[aways]
    log_la = ba + att[aways] + def_[homes]
    lh_v = np.exp(np.clip(log_lh, -10, 5))
    la_v = np.exp(np.clip(log_la, -10, 5))
    wll = (xh * log_lh - lh_v + xa * log_la - la_v).sum()
    return -(wll) + PRIOR_N * (att ** 2).sum() + PRIOR_N * (def_ ** 2).sum()


def analytic_gradient(params):
    bh, ba = params[0], params[1]
    att = expand(params[2:2 + (N - 1)])
    def_ = expand(params[2 + (N - 1):2 + 2 * (N - 1)])
    log_lh = bh + att[homes] + def_[aways]
    log_la = ba + att[aways] + def_[homes]
    lh_v = np.exp(np.clip(log_lh, -10, 5))
    la_v = np.exp(np.clip(log_la, -10, 5))
    rh = xh - lh_v
    ra = xa - la_v
    g_bh = -rh.sum()
    g_ba = -ra.sum()
    g_att_full = -(np.bincount(homes, weights=rh, minlength=N) + np.bincount(aways, weights=ra, minlength=N)) + 2 * PRIOR_N * att
    g_def_full = -(np.bincount(aways, weights=rh, minlength=N) + np.bincount(homes, weights=ra, minlength=N)) + 2 * PRIOR_N * def_
    return np.concatenate([[g_bh, g_ba], g_att_full[:-1] - g_att_full[-1], g_def_full[:-1] - g_def_full[-1]])


x0 = np.zeros(2 + 2 * (N - 1))
x0[0] = 0.35
x0[1] = 0.20

import time
t0 = time.perf_counter()
res = minimize(neg_wll, x0, method='L-BFGS-B', jac=analytic_gradient,
                options={'maxiter': MAXITER, 'ftol': 1e-10, 'gtol': 1e-7})
elapsed = time.perf_counter() - t0

print(f"Fit success={res.success}  nit={res.nit}  elapsed={elapsed:.3f}s")
if not res.success:
    raise RuntimeError(f"Latent fit did not converge: {res.message}")

att = expand(res.x[2:2 + (N - 1)])
def_ = expand(res.x[2 + (N - 1):2 + 2 * (N - 1)])

generated_at = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

snapshot = {
    'generated_at': generated_at,
    'last_match_included_at': last_kickoff,
    'model_version': MODEL_VERSION,
    'matches_used': len(rows),
    'teams': [
        {'team_id': tid, 'attack': float(att[team_idx[tid]]), 'defence': float(def_[team_idx[tid]])}
        for tid in team_ids
    ],
}

OUT_PATH.write_text(json.dumps(snapshot, indent=2))
print(f"\nSaved: {OUT_PATH}")
print(f"generated_at: {generated_at}")
print(f"teams: {N}")
