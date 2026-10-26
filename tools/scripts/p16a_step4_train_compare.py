"""
P16A — Candidate44 vs Candidate48 (Candidate44 + historical Structural Strength).

TRAIN: 2024/25 season_ids (28,29,30,31,32)
TEST:  2025/26 season_ids (23,24,25,26,27)
FWD:   2026/27 — only if point-in-time Structural is available for those dates
       (dcaribou snapshot covers up to 2026-07-06, FWD kickoffs are Aug+ 2026:
       expected UNAVAILABLE, checked explicitly below, never forced).

Both models: MISSING30 gate on rest_days + Bivariate Poisson score matrix (lambda3=0.15),
fixed, not tuned. No grid search, no new variants.
"""
import csv
import json
import math
import statistics
from pathlib import Path

import numpy as np
from scipy.optimize import minimize
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[2]
SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')

TRAIN_SEASONS = {'28', '29', '30', '31', '32'}
TEST_SEASONS = {'23', '24', '25', '26', '27'}

LAMBDA3 = 0.15
MISSING30_THRESHOLD = 30
MAX_GOALS = 10

# ─── Load base 39-feature artifact for feature list ─────────────────────────
art39 = json.loads((ROOT / 'tools/models/prediction_engine_no_e9_no_e10.json').read_text())
FEATS39 = art39['features']
ELO_H = 'core_elo_home_pre_match_elo'
ELO_A = 'core_elo_away_pre_match_elo'
REST_H_IDX = FEATS39.index('core_schedule_home_rest_days')
REST_A_IDX = FEATS39.index('core_schedule_away_rest_days')
LATENT_NAMES = ['latent_attack_home', 'latent_defence_home', 'latent_attack_away', 'latent_defence_away']

# ─── Load dataset rows (TRAIN+TEST) ─────────────────────────────────────────
with open(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv', newline='', encoding='utf-8') as f:
    all_rows_raw = list(csv.DictReader(f))


def valid(r):
    return (r.get('label_home_goals', '') != '' and r.get('label_away_goals', '') != '' and r.get(ELO_H, '') != '')


rows = sorted([r for r in all_rows_raw if valid(r)], key=lambda r: r['kickoff_at'])
print(f"Rows (TRAIN+TEST): {len(rows)}")

# ─── Structural features (from step 3) ──────────────────────────────────────
structural_by_match = {}
with open(SCRATCH / 'structural_features.csv', encoding='utf-8') as f:
    for r in csv.DictReader(f):
        structural_by_match[r['match_id']] = r

n_missing_struct = sum(1 for r in rows if structural_by_match.get(r['match_id'], {}).get('structural_home', '') in ('', 'None', None))
print(f"Rows missing structural data: {n_missing_struct} / {len(rows)}")

# ─── Walk-forward latent Attack/Defence (identical methodology to P9K/C44) ──
team_ids = sorted(set(r['home_team_id'] for r in rows) | set(r['away_team_id'] for r in rows))
team_idx = {t: i for i, t in enumerate(team_ids)}
N_TEAMS = len(team_ids)


def expand(free):
    return np.concatenate([free, [-free.sum()]])


def fit_latent(matches, prior_n=3, x0=None, maxiter=300):
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


from collections import defaultdict
date_groups = defaultdict(list)
for idx, r in enumerate(rows):
    date_groups[r['kickoff_at'][:10]].append((idx, r))
sorted_dates = sorted(date_groups.keys())

prior_matches_list = []
latent_feats = {}
current_params = None
for date in sorted_dates:
    group = date_groups[date]
    if len(prior_matches_list) == 0:
        bh, ba, att, def_ = fit_latent([], x0=current_params)
    else:
        m_arr = [(team_idx[m[0]], team_idx[m[1]], m[2], m[3]) for m in prior_matches_list]
        bh, ba, att, def_ = fit_latent(m_arr, x0=current_params)
    current_params = (bh, ba, att, def_)
    for idx, r in group:
        hi, ai = team_idx.get(r['home_team_id']), team_idx.get(r['away_team_id'])
        latent_feats[idx] = (0.0, 0.0, 0.0, 0.0) if hi is None or ai is None else \
            (float(att[hi]), float(def_[hi]), float(att[ai]), float(def_[ai]))
    for idx, r in group:
        prior_matches_list.append((r['home_team_id'], r['away_team_id'],
                                    int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))
print("Walk-forward latent fit done.")

# ─── Build feature matrices ──────────────────────────────────────────────────
FEATS44 = FEATS39 + ['elo_gap_signed_square'] + LATENT_NAMES
FEATS48 = FEATS44 + ['structural_home', 'structural_away', 'structural_gap', 'structural_gap_signed_square']

N = len(rows)
X44 = np.zeros((N, 44))
X48extra = np.zeros((N, 4))
y_h = np.zeros(N)
y_a = np.zeros(N)
season_id = []
struct_available = np.zeros(N, dtype=bool)

for i, r in enumerate(rows):
    for j, f in enumerate(FEATS39):
        raw = r.get(f, '')
        if raw == '':
            X44[i, j] = np.nan
        else:
            val = float(raw)
            if j in (REST_H_IDX, REST_A_IDX) and val > MISSING30_THRESHOLD:
                X44[i, j] = np.nan
            else:
                X44[i, j] = val
    elo_h = float(r[ELO_H])
    elo_a = float(r[ELO_A])
    gap = elo_h - elo_a
    X44[i, 39] = math.copysign(gap * gap, gap)
    lf = latent_feats[i]
    X44[i, 40], X44[i, 41], X44[i, 42], X44[i, 43] = lf

    sr = structural_by_match.get(r['match_id'], {})
    sh = sr.get('structural_home', '')
    sa = sr.get('structural_away', '')
    if sh not in ('', 'None', None) and sa not in ('', 'None', None):
        sh_v, sa_v = float(sh), float(sa)
        sgap = sh_v - sa_v
        X48extra[i] = [sh_v, sa_v, sgap, math.copysign(sgap * sgap, sgap)]
        struct_available[i] = True
    else:
        X48extra[i] = [np.nan, np.nan, np.nan, np.nan]

    y_h[i] = float(r['label_home_goals'])
    y_a[i] = float(r['label_away_goals'])
    season_id.append(r['season_id'])

season_id = np.array(season_id)
train_mask = np.isin(season_id, list(TRAIN_SEASONS))
test_mask = np.isin(season_id, list(TEST_SEASONS))
print(f"TRAIN rows: {train_mask.sum()}  TEST rows: {test_mask.sum()}")
print(f"Structural coverage overall: {struct_available.mean():.1%}  "
      f"TRAIN: {struct_available[train_mask].mean():.1%}  TEST: {struct_available[test_mask].mean():.1%}")


def fit_and_predict(X_full, feat_names, train_mask, test_mask):
    medians = np.nanmedian(X_full[train_mask], axis=0)
    X_imp = np.where(np.isnan(X_full), medians, X_full)
    scaler = StandardScaler()
    X_sc_train = scaler.fit_transform(X_imp[train_mask])
    X_sc_all = scaler.transform(X_imp)

    model_h = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc_train, y_h[train_mask])
    model_a = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc_train, y_a[train_mask])

    lam_h = model_h.predict(X_sc_all)
    lam_a = model_a.predict(X_sc_all)
    return lam_h, lam_a


X44_full = X44
X48_full = np.hstack([X44, X48extra])

lam_h_44, lam_a_44 = fit_and_predict(X44_full, FEATS44, train_mask, test_mask)
lam_h_48, lam_a_48 = fit_and_predict(X48_full, FEATS48, train_mask, test_mask)


# ─── Bivariate Poisson 1X2 (Karlis & Ntzoufras), lambda3 fixed ─────────────
_logfact = [0.0]
for k in range(1, MAX_GOALS + 1):
    _logfact.append(_logfact[-1] + math.log(k))


def bp_1x2(lh, la, l3=LAMBDA3):
    lh = max(lh, 1e-6)
    la = max(la, 1e-6)
    l3 = min(l3, min(lh, la) * 0.9999)
    l1 = lh - l3
    l2 = la - l3
    log_ratio = math.log(l3) - math.log(l1) - math.log(l2)
    const = -(l1 + l2 + l3)
    pH = pD = pA = 0.0
    for x in range(MAX_GOALS + 1):
        logpx = x * math.log(l1) - _logfact[x] if x > 0 else -_logfact[0]
        for y in range(MAX_GOALS + 1):
            logpy = y * math.log(l2) - _logfact[y] if y > 0 else -_logfact[0]
            inner = 1.0
            for k in range(1, min(x, y) + 1):
                logterm = (_logfact[x] - _logfact[k] - _logfact[x - k]) + \
                          (_logfact[y] - _logfact[k] - _logfact[y - k]) + \
                          _logfact[k] + k * log_ratio
                inner += math.exp(logterm)
            p = math.exp(const + logpx + logpy) * inner
            if x > y:
                pH += p
            elif x == y:
                pD += p
            else:
                pA += p
    total = pH + pD + pA
    return pH / total, pD / total, pA / total


def eval_model(lam_h, lam_a, mask):
    idx = np.where(mask)[0]
    logloss = 0.0
    brier = 0.0
    correct = 0
    preds = {}
    for i in idx:
        pH, pD, pA = bp_1x2(lam_h[i], lam_a[i])
        hg, ag = y_h[i], y_a[i]
        outcome = 'H' if hg > ag else ('D' if hg == ag else 'A')
        p_map = {'H': pH, 'D': pD, 'A': pA}
        p_true = max(p_map[outcome], 1e-12)
        logloss += -math.log(p_true)
        one_hot = {'H': (1, 0, 0), 'D': (0, 1, 0), 'A': (0, 0, 1)}[outcome]
        brier += (pH - one_hot[0]) ** 2 + (pD - one_hot[1]) ** 2 + (pA - one_hot[2]) ** 2
        pred = 'H' if (pH >= pD and pH >= pA) else ('A' if pA >= pD else 'D')
        if pred == outcome:
            correct += 1
        preds[i] = (pH, pD, pA)
    n = len(idx)
    return {
        'logloss': logloss / n,
        'brier': brier / n,
        'accuracy': correct / n,
        'n': n,
    }, preds


metrics_44, preds_44 = eval_model(lam_h_44, lam_a_44, test_mask)
metrics_48, preds_48 = eval_model(lam_h_48, lam_a_48, test_mask)

# Restrict to rows with structural data available for a fair head-to-head too
test_struct_mask = test_mask & struct_available
metrics_44_covered, _ = eval_model(lam_h_44, lam_a_44, test_struct_mask)
metrics_48_covered, preds_48_covered = eval_model(lam_h_48, lam_a_48, test_struct_mask)

print("\n=== TEST (2025/26) — ALL rows (missing structural -> median-imputed) ===")
print(f"C44: logloss={metrics_44['logloss']:.4f} brier={metrics_44['brier']:.4f} acc={metrics_44['accuracy']:.4f} n={metrics_44['n']}")
print(f"C48: logloss={metrics_48['logloss']:.4f} brier={metrics_48['brier']:.4f} acc={metrics_48['accuracy']:.4f} n={metrics_48['n']}")

print(f"\n=== TEST (2025/26) — rows WITH structural coverage only ===")
print(f"C44: logloss={metrics_44_covered['logloss']:.4f} brier={metrics_44_covered['brier']:.4f} acc={metrics_44_covered['accuracy']:.4f} n={metrics_44_covered['n']}")
print(f"C48: logloss={metrics_48_covered['logloss']:.4f} brier={metrics_48_covered['brier']:.4f} acc={metrics_48_covered['accuracy']:.4f} n={metrics_48_covered['n']}")

dLL = metrics_48_covered['logloss'] - metrics_44_covered['logloss']
dBrier = metrics_48_covered['brier'] - metrics_44_covered['brier']
dAcc = metrics_48_covered['accuracy'] - metrics_44_covered['accuracy']
print(f"\nDelta (C48-C44, covered rows): dLogLoss={dLL:+.5f}  dBrier={dBrier:+.5f}  dAcc={dAcc*100:+.2f}pp")

# ─── FWD (2026/27) structural availability check ────────────────────────────
fwd_mask_struct = []
with open(SCRATCH / 'structural_features.csv', encoding='utf-8') as f:
    for r in csv.DictReader(f):
        pass  # already loaded above as structural_by_match

with open(ROOT / 'tools/datasets/dataset_core_v1_2026.csv', newline='', encoding='utf-8') as f:
    fwd_rows = list(csv.DictReader(f))
fwd_covered = 0
for r in fwd_rows:
    sr = structural_by_match.get(r['match_id'], {})
    if sr.get('structural_home', '') not in ('', 'None', None) and sr.get('n_home', '0') not in ('0',):
        fwd_covered += 1
print(f"\nFWD 2026/27: {len(fwd_rows)} matches, structural mapped-club coverage rows: {fwd_covered}")
print("(mapped != trustworthy point-in-time: dcaribou snapshot cutoff ~2026-07-06, "
      "so any 'coverage' here is a stale pre-season valuation carried forward, not a fresh in-season one)")

# ─── Save diagnostics for 5 example matches ─────────────────────────────────
diag = {
    'metrics': {'C44_all': metrics_44, 'C48_all': metrics_48,
                'C44_covered': metrics_44_covered, 'C48_covered': metrics_48_covered},
    'coverage': {
        'overall': float(struct_available.mean()),
        'train': float(struct_available[train_mask].mean()),
        'test': float(struct_available[test_mask].mean()),
    },
}
with open(SCRATCH / 'p16a_metrics.json', 'w') as f:
    json.dump(diag, f, indent=2)

# Save per-row test predictions for the diagnostic examples step
import pickle
with open(SCRATCH / 'p16a_preds.pkl', 'wb') as f:
    pickle.dump({
        'rows': rows,
        'test_mask': test_mask,
        'struct_available': struct_available,
        'X48extra': X48extra,
        'preds_44': preds_44,
        'preds_48': preds_48,
        'lam_h_44': lam_h_44, 'lam_a_44': lam_a_44,
        'lam_h_48': lam_h_48, 'lam_a_48': lam_a_48,
    }, f)

print("\nSaved diagnostics -> p16a_metrics.json, p16a_preds.pkl")
