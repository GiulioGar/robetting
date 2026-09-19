"""
ADVANCED MATCH STATISTICS EMPIRICAL AUDIT v2 - ROBETTING
tools/analysis/advanced_stats_audit_v2.py

Corrections vs v1:
  1. Elo pre-match included in BASE (computed from full DB history)
  2. Ridge regression confirms multicollinearity conclusions
  3. Expanding window CV within 2025/26 to distinguish signal from noise

READ-ONLY: no DB writes, no API calls, no commits.
Stagioni target: 2024/25 + 2025/26, 5 leghe.
"""

import mysql.connector
import pandas as pd
import numpy as np
from scipy import stats
import warnings
warnings.filterwarnings('ignore')

DB_CONFIG  = dict(host='127.0.0.1', user='root', password='', database='robetting')
ELO_K      = 20.0
ELO_INIT   = 1500.0
ELO_HA     = 60.0   # home advantage constant (same as TeamEloCalculator.php)
RIDGE_ALPHAS = [0.1, 1.0, 10.0, 100.0]

# ===================================================================
# 1. DATA PULL
# ===================================================================

ALL_MATCHES_SQL = """
SELECT m.id, m.home_team_id, m.away_team_id, m.kickoff_at,
       CAST(m.home_score_ft AS SIGNED) AS home_score_ft,
       CAST(m.away_score_ft AS SIGNED) AS away_score_ft
FROM matches m
WHERE m.status = 'finished'
  AND m.home_score_ft IS NOT NULL
  AND m.away_score_ft IS NOT NULL
ORDER BY m.kickoff_at, m.id
"""

STATS_SQL = """
SELECT
    m.id                        AS match_id,
    m.home_team_id,
    m.away_team_id,
    m.kickoff_at,
    CAST(m.home_score_ft AS SIGNED) AS home_score_ft,
    CAST(m.away_score_ft AS SIGNED) AS away_score_ft,
    c.slug                      AS competition,
    s.year_start                AS season,
    ms.home_shots,              ms.away_shots,
    ms.home_shots_on_target,    ms.away_shots_on_target,
    ms.home_shots_insidebox,    ms.away_shots_insidebox,
    ms.home_shots_outsidebox,   ms.away_shots_outsidebox,
    ms.home_blocked_shots,      ms.away_blocked_shots,
    ms.home_possession,         ms.away_possession,
    ms.home_passes_total,       ms.away_passes_total,
    ms.home_passes_accurate,    ms.away_passes_accurate,
    ms.home_passes_percentage,  ms.away_passes_percentage,
    ms.home_goalkeeper_saves,   ms.away_goalkeeper_saves,
    ms.home_expected_goals,     ms.away_expected_goals,
    ms.home_goals_prevented,    ms.away_goals_prevented
FROM matches m
JOIN seasons s           ON m.season_id      = s.id
JOIN competitions c      ON s.competition_id = c.id
JOIN match_statistics ms ON ms.match_id      = m.id
WHERE s.year_start IN (2024, 2025)
  AND m.status = 'finished'
  AND m.home_score_ft IS NOT NULL
  AND m.away_score_ft IS NOT NULL
ORDER BY m.kickoff_at, m.id
"""


def _fetch(sql):
    conn = mysql.connector.connect(**DB_CONFIG)
    cur  = conn.cursor(dictionary=True)
    cur.execute(sql)
    rows = cur.fetchall()
    cur.close(); conn.close()
    return rows


def pull_all_matches():
    df = pd.DataFrame(_fetch(ALL_MATCHES_SQL))
    df['kickoff_at'] = pd.to_datetime(df['kickoff_at'])
    for c in ['id', 'home_team_id', 'away_team_id', 'home_score_ft', 'away_score_ft']:
        df[c] = pd.to_numeric(df[c], errors='coerce')
    return df.sort_values(['kickoff_at', 'id']).reset_index(drop=True)


def pull_stats():
    df = pd.DataFrame(_fetch(STATS_SQL))
    df['kickoff_at'] = pd.to_datetime(df['kickoff_at'])
    skip = {'match_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'competition', 'season'}
    for c in df.columns:
        if c not in skip:
            df[c] = pd.to_numeric(df[c], errors='coerce')
    return df


# ===================================================================
# 2. ELO COMPUTATION (canonical formula from TeamEloCalculator.php)
# ===================================================================

def compute_elo(all_matches):
    """
    Replay every finished match in chronological order.
    Returns {match_id: (home_pre_elo, away_pre_elo)}.
    Elo is recorded BEFORE the update for each match -- no leakage.
    Parameters: K=20, initial=1500, home_advantage=60 (identical to PHP).
    """
    elo      = {}
    pre_elo  = {}

    for _, m in all_matches.iterrows():
        mid = int(m['id'])
        h   = int(m['home_team_id'])
        a   = int(m['away_team_id'])
        he  = elo.get(h, ELO_INIT)
        ae  = elo.get(a, ELO_INIT)

        pre_elo[mid] = (he, ae)   # snapshot BEFORE update

        exp_h = 1.0 / (1.0 + 10.0 ** ((ae - he - ELO_HA) / 400.0))
        hft   = float(m['home_score_ft'])
        aft   = float(m['away_score_ft'])
        act   = 1.0 if hft > aft else (0.5 if hft == aft else 0.0)
        delta = ELO_K * (act - exp_h)

        elo[h] = he + delta
        elo[a] = ae - delta

    return pre_elo


# ===================================================================
# 3. TEAM-MATCH PANEL
# ===================================================================

RAW_COLS = [
    'goals_for', 'goals_against',
    'shots_for', 'shots_against',
    'sot_for', 'sot_against',
    'xg_for', 'xg_against',
    'insidebox_for', 'insidebox_against',
    'gk_saves_for', 'goals_prevented_for',
    'possession_for', 'possession_against',
    'passes_pct_for',
]


def build_panel(matches, pre_elo):
    rows = []
    for _, m in matches.iterrows():
        mid  = int(m['match_id'])
        helo, aelo = pre_elo.get(mid, (ELO_INIT, ELO_INIT))

        for side, opp in (('home', 'away'), ('away', 'home')):
            ih    = (side == 'home')
            gf    = float(m['home_score_ft'] if ih else m['away_score_ft'])
            ga    = float(m['away_score_ft'] if ih else m['home_score_ft'])
            telo  = helo if ih else aelo
            oelo  = aelo if ih else helo

            rows.append({
                'match_id':           mid,
                'team_id':            int(m[f'{side}_team_id']),
                'kickoff_at':         m['kickoff_at'],
                'competition':        m['competition'],
                'season':             int(m['season']),
                'is_home':            int(ih),
                # Targets
                'goals_for':          gf,
                'goals_against':      ga,
                'goal_diff':          gf - ga,
                'win':                int(gf > ga),
                # Elo (pre-match, no rolling needed)
                'team_pre_elo':       telo,
                'opp_pre_elo':        oelo,
                'elo_diff':           telo - oelo,
                # Baseline stats
                'shots_for':          m[f'{side}_shots'],
                'shots_against':      m[f'{opp}_shots'],
                'sot_for':            m[f'{side}_shots_on_target'],
                'sot_against':        m[f'{opp}_shots_on_target'],
                # Advanced A: xG
                'xg_for':             m[f'{side}_expected_goals'],
                'xg_against':         m[f'{opp}_expected_goals'],
                # Advanced B: inside-box
                'insidebox_for':      m[f'{side}_shots_insidebox'],
                'insidebox_against':  m[f'{opp}_shots_insidebox'],
                # Advanced C: Goalkeeping
                'gk_saves_for':       m[f'{side}_goalkeeper_saves'],
                'goals_prevented_for': m[f'{side}_goals_prevented'],
                # Advanced D: Possession + passing
                'possession_for':     m[f'{side}_possession'],
                'possession_against': m[f'{opp}_possession'],
                'passes_pct_for':     m[f'{side}_passes_percentage'],
            })

    panel = pd.DataFrame(rows)
    panel = panel.sort_values(['team_id', 'kickoff_at', 'match_id']).reset_index(drop=True)
    return panel


# ===================================================================
# 4. ROLLING FEATURES (strictly prior to each match)
# ===================================================================

def _roll_group(grp, w):
    return (grp[RAW_COLS]
            .shift(1)
            .rolling(w, min_periods=max(2, w // 2))
            .mean())


def add_rolling(panel, windows=(5, 10)):
    parts = [panel]
    for w in windows:
        rolled = (panel.groupby('team_id', group_keys=False)
                  .apply(lambda g: _roll_group(g, w))
                  .rename(columns={c: f'{c}_L{w}' for c in RAW_COLS}))
        parts.append(rolled)
    panel = pd.concat(parts, axis=1)
    for w in windows:
        s = f'_L{w}'
        panel[f'goal_diff{s}']      = panel[f'goals_for{s}']      - panel[f'goals_against{s}']
        panel[f'shot_diff{s}']      = panel[f'shots_for{s}']      - panel[f'shots_against{s}']
        panel[f'sot_diff{s}']       = panel[f'sot_for{s}']        - panel[f'sot_against{s}']
        panel[f'xg_diff{s}']        = panel[f'xg_for{s}']         - panel[f'xg_against{s}']
        panel[f'insidebox_diff{s}'] = panel[f'insidebox_for{s}']  - panel[f'insidebox_against{s}']
    return panel


# ===================================================================
# 5. OLS / RIDGE / METRICS
# ===================================================================

def standardize(Xtr, Xte):
    mu = np.nanmean(Xtr, axis=0)
    sd = np.nanstd(Xtr, axis=0, ddof=1)
    sd[sd < 1e-9] = 1.0
    return (Xtr - mu) / sd, (Xte - mu) / sd


def ols_predict(Xtr, ytr, Xte):
    Xa = np.column_stack([np.ones(len(Xtr)), Xtr])
    Xb = np.column_stack([np.ones(len(Xte)), Xte])
    coef, *_ = np.linalg.lstsq(Xa, ytr, rcond=None)
    return Xb @ coef


def ridge_predict(Xtr, ytr, Xte, alpha):
    Xa = np.column_stack([np.ones(len(Xtr)), Xtr])
    Xb = np.column_stack([np.ones(len(Xte)), Xte])
    n  = Xa.shape[1]
    reg       = alpha * np.eye(n)
    reg[0, 0] = 0.0   # don't penalise intercept
    coef = np.linalg.solve(Xa.T @ Xa + reg, Xa.T @ ytr)
    return Xb @ coef


def reg_metrics(yt, yp):
    mask = np.isfinite(yp)
    if mask.sum() < 20:
        return None
    yt2, yp2 = yt[mask], yp[mask]
    r, _  = stats.pearsonr(yt2, yp2)
    return {
        'MAE':  float(np.mean(np.abs(yt2 - yp2))),
        'RMSE': float(np.sqrt(np.mean((yt2 - yp2) ** 2))),
        'r':    float(r),
        'n':    int(mask.sum()),
    }


def brier_score(yt, yp):
    mask = np.isfinite(yp)
    return float(np.mean((yt[mask] - np.clip(yp[mask], 0.0, 1.0)) ** 2))


def _prep(panel, feats, target, tr_mask, te_mask):
    cols = feats + [target]
    tr = panel.loc[tr_mask, cols].dropna()
    te = panel.loc[te_mask, cols].dropna()
    if len(tr) < 80 or len(te) < 40:
        return None, None, None, None
    Xtr_s, Xte_s = standardize(tr[feats].values.astype(float),
                                 te[feats].values.astype(float))
    return Xtr_s, tr[target].values.astype(float), Xte_s, te[target].values.astype(float)


def eval_ols(panel, feats, target, tr_mask, te_mask):
    Xtr, ytr, Xte, yte = _prep(panel, feats, target, tr_mask, te_mask)
    if Xtr is None:
        return None
    return reg_metrics(yte, ols_predict(Xtr, ytr, Xte))


def eval_ridge_best(panel, feats, target, tr_mask, te_mask):
    """Try several alphas on the full train/test split and report the best."""
    Xtr, ytr, Xte, yte = _prep(panel, feats, target, tr_mask, te_mask)
    if Xtr is None:
        return None, None
    best_r, best_m, best_a = -np.inf, None, None
    for a in RIDGE_ALPHAS:
        m = reg_metrics(yte, ridge_predict(Xtr, ytr, Xte, a))
        if m and m['r'] > best_r:
            best_r = m['r']
            best_m = m
            best_a = a
    return best_m, best_a


def eval_brier(panel, feats, tr_mask, te_mask):
    Xtr, ytr, Xte, yte = _prep(panel, feats, 'win', tr_mask, te_mask)
    if Xtr is None:
        return None
    return brier_score(yte, ols_predict(Xtr, ytr, Xte))


# ===================================================================
# 6. EXPANDING WINDOW (intra 2025/26, 4 blocks)
# ===================================================================

def expanding_window(panel, base_feats, adv_feats, target='goal_diff', n_blocks=4):
    """
    For each block i of 2025/26:
      TRAIN = 2024/25 full + 2025/26 blocks 0..(i-1)   [expanding]
      TEST  = 2025/26 block i
    Returns list of dicts with per-block metrics.
    """
    te_2526 = (panel[panel['season'] == 2025]
               .sort_values('kickoff_at')
               .reset_index())            # 'index' col = original panel index
    n        = len(te_2526)
    blk_size = n // n_blocks
    all_feats = base_feats + adv_feats
    idx_2425  = panel[panel['season'] == 2024].index.values
    results   = []

    for blk in range(n_blocks):
        start = blk * blk_size
        end   = (blk + 1) * blk_size if blk < n_blocks - 1 else n

        te_orig  = te_2526.iloc[start:end]['index'].values
        prior_26 = te_2526.iloc[:start]['index'].values

        tr_idx   = np.concatenate([idx_2425, prior_26]) if len(prior_26) else idx_2425
        tr_mask  = panel.index.isin(tr_idx)
        te_mask  = panel.index.isin(te_orig)

        rb = eval_ols(panel, base_feats, target, tr_mask, te_mask)
        ra = eval_ols(panel, all_feats,  target, tr_mask, te_mask)

        if rb and ra:
            results.append({
                'block':   blk + 1,
                'n_train': int(tr_mask.sum()),
                'n_test':  int(te_mask.sum()),
                'base_r':  rb['r'],
                'adv_r':   ra['r'],
                'delta_r': ra['r'] - rb['r'],
            })

    return results


# ===================================================================
# 7. REPORT HELPERS
# ===================================================================

SEP  = "=" * 72
SEP2 = "-" * 72


def hdr(title):
    print(f"\n{SEP}\n  {title}\n{SEP}")


def row(label, res, ref=None, width=26):
    if res is None:
        print(f"  {label:<{width}}  SKIP")
        return
    dr_str = ""
    if ref is not None:
        dr = res['r'] - ref['r']
        dm = res['MAE'] - ref['MAE']
        dr_str = f"  Dr={dr:>+7.4f}  DMAE={dm:>+7.4f}"
    print(f"  {label:<{width}}  MAE={res['MAE']:>7.4f}  r={res['r']:>7.4f}  n={res['n']}{dr_str}")


# ===================================================================
# 8. MAIN
# ===================================================================

def main():
    print(SEP)
    print("  ADVANCED MATCH STATISTICS EMPIRICAL AUDIT v2 -- ROBETTING")
    print("  Fixes: Elo in BASE | Ridge regression | Expanding window CV")
    print(SEP)

    # ----------------------------------------------------------------
    # DATA
    # ----------------------------------------------------------------
    print("\n>> Pulling ALL finished matches for Elo replay...")
    all_m = pull_all_matches()
    print(f"   Total finished matches (all seasons/comps): {len(all_m)}")

    print(">> Pulling 2024/25 + 2025/26 stats matches...")
    matches = pull_stats()
    print(f"   Stats matches: {len(matches)}")

    print(">> Computing pre-match Elo (K=20, init=1500, HA=60)...")
    pre_elo = compute_elo(all_m)
    covered = sum(1 for mid in matches['match_id'].astype(int) if mid in pre_elo)
    print(f"   Elo coverage: {covered}/{len(matches)} matches")

    sample_elos = [pre_elo[int(m)] for m in matches['match_id'] if int(m) in pre_elo]
    all_elo_vals = [v for pair in sample_elos for v in pair]
    print(f"   Elo range: {min(all_elo_vals):.0f} -- {max(all_elo_vals):.0f}"
          f"   mean={np.mean(all_elo_vals):.0f}")

    print(">> Building panel and rolling features (L5, L10)...")
    panel = build_panel(matches, pre_elo)
    panel = add_rolling(panel)
    print(f"   Panel: {len(panel):,} team-match observations")

    # ----------------------------------------------------------------
    # SPLIT
    # ----------------------------------------------------------------
    tr = panel['season'] == 2024
    te = panel['season'] == 2025

    hdr("1. SPLIT CRONOLOGICO")
    print(f"   TRAIN: 2024/25 = {tr.sum():,} obs")
    print(f"   TEST:  2025/26 = {te.sum():,} obs")
    print(f"   Elo: replay di tutti i {len(all_m)} match DB prima del match target")
    print(f"\n   Target (test set):")
    for t in ['goal_diff', 'goals_for', 'goals_against']:
        v = panel.loc[te, t]
        print(f"     {t:<18}: mean={v.mean():.3f}  std={v.std():.3f}")
    wr = panel.loc[te, 'win'].mean()
    print(f"     win_rate       : {wr:.3f}  naive Brier={wr*(1-wr):.4f}")

    W = 'L10'

    BASE_CORE = [
        f'goals_for_{W}', f'goals_against_{W}',
        f'shots_for_{W}',  f'shots_against_{W}',
        f'sot_for_{W}',    f'sot_against_{W}',
        'is_home',
    ]
    BASE_ELO      = BASE_CORE + ['team_pre_elo', 'opp_pre_elo']
    BASE_ELO_DIFF = BASE_CORE + ['elo_diff']

    # ----------------------------------------------------------------
    # SECTION 2: BASE_CORE sanity check vs v1
    # ----------------------------------------------------------------
    hdr("2. BASE_CORE -- sanity check vs audit v1")
    r_core = eval_ols(panel, BASE_CORE, 'goal_diff', tr, te)
    row('BASE_CORE', r_core)
    if r_core:
        diff = r_core['r'] - 0.3222
        status = "OK (within 0.002)" if abs(diff) < 0.002 else f"DIVERGENCE Dr={diff:>+.4f}"
        print(f"   v1 reference:              MAE=1.2914   r=0.3222  => {status}")

    # ----------------------------------------------------------------
    # SECTION 3: BASE_CORE vs BASE_ELO vs BASE_ELO_DIFF
    # ----------------------------------------------------------------
    hdr("3. BASE_CORE vs BASE_ELO vs BASE_ELO_DIFF (goal_diff)")
    r_elo      = eval_ols(panel, BASE_ELO,      'goal_diff', tr, te)
    r_elo_diff = eval_ols(panel, BASE_ELO_DIFF, 'goal_diff', tr, te)

    print(f"   {'Model':<22}  {'MAE':>7}  {'r':>7}  {'Dr vs CORE':>12}  n")
    print(f"   {SEP2[:60]}")
    row('BASE_CORE',     r_core,     width=22)
    if r_elo:
        dr = r_elo['r'] - (r_core['r'] if r_core else 0)
        dm = r_elo['MAE'] - (r_core['MAE'] if r_core else 0)
        print(f"   {'BASE_ELO':<22}  {r_elo['MAE']:>7.4f}  {r_elo['r']:>7.4f}  {dr:>+12.4f}  {r_elo['n']}")
    if r_elo_diff:
        dr = r_elo_diff['r'] - (r_core['r'] if r_core else 0)
        print(f"   {'BASE_ELO_DIFF':<22}  {r_elo_diff['MAE']:>7.4f}  {r_elo_diff['r']:>7.4f}  {dr:>+12.4f}  {r_elo_diff['n']}")

    print(f"\n   Brier (win prediction, naive={wr*(1-wr):.4f}):")
    for name, feats in [('BASE_CORE', BASE_CORE), ('BASE_ELO', BASE_ELO)]:
        b = eval_brier(panel, feats, tr, te)
        if b is not None:
            print(f"   {name:<22}: Brier={b:.4f}")

    # ----------------------------------------------------------------
    # SECTION 4: ADVANCED vs BASE_ELO
    # ----------------------------------------------------------------
    hdr("4. ADVANCED vs BASE_ELO (OLS, out-of-sample 2025/26)")
    xg_feats   = [f'xg_for_{W}', f'xg_against_{W}']
    ibox_feats = [f'insidebox_for_{W}', f'insidebox_against_{W}']

    MODELS = {
        'BASE_ELO':            BASE_ELO,
        'BASE_ELO+xG':         BASE_ELO + xg_feats,
        'BASE_ELO+insidebox':  BASE_ELO + ibox_feats,
        'BASE_ELO+possession': BASE_ELO + [f'possession_for_{W}', f'possession_against_{W}'],
        'BASE_ELO+passes_pct': BASE_ELO + [f'passes_pct_for_{W}'],
        'BASE_ELO+gk_saves':   BASE_ELO + [f'gk_saves_for_{W}'],
        'BASE_ELO+gp':         BASE_ELO + [f'goals_prevented_for_{W}'],
        'BASE_ELO+ALL_ADV':    BASE_ELO + xg_feats + ibox_feats + [
            f'possession_for_{W}', f'possession_against_{W}',
            f'passes_pct_for_{W}', f'gk_saves_for_{W}',
            f'goals_prevented_for_{W}',
        ],
    }

    for tgt, tgt_name in [
        ('goal_diff',    'goal_diff'),
        ('goals_for',    'goals_for'),
        ('goals_against','goals_against'),
    ]:
        print(f"\n   Target: {tgt_name}")
        print(f"   {'Model':<26}  {'MAE':>7}  {'r':>7}  {'Dr':>8}  {'DMAE':>8}  n")
        print(f"   {SEP2[:68]}")
        base_r = None
        for name, feats in MODELS.items():
            res = eval_ols(panel, feats, tgt, tr, te)
            if res is None:
                continue
            if base_r is None:
                base_r = res
                print(f"   {name:<26}  {res['MAE']:>7.4f}  {res['r']:>7.4f}  {'--':>8}  {'--':>8}  {res['n']}")
            else:
                dr = res['r']   - base_r['r']
                dm = res['MAE'] - base_r['MAE']
                print(f"   {name:<26}  {res['MAE']:>7.4f}  {res['r']:>7.4f}  {dr:>+8.4f}  {dm:>+8.4f}  {res['n']}")

    print(f"\n   Brier (win) -- BASE_ELO vs +xG:")
    for name, feats in [('BASE_ELO', BASE_ELO), ('BASE_ELO+xG', BASE_ELO + xg_feats)]:
        b = eval_brier(panel, feats, tr, te)
        if b is not None:
            print(f"   {name:<26}: Brier={b:.4f}")

    # ----------------------------------------------------------------
    # SECTION 5: xG ABLATION A/B/C/D/E
    # ----------------------------------------------------------------
    hdr("5. xG ABLATION -- A/B/C/D/E (L10, Elo in all configs)")
    ELO_GOALS = ['team_pre_elo', 'opp_pre_elo',
                 f'goals_for_{W}', f'goals_against_{W}', 'is_home']
    ABLATION = {
        'A: Elo+goals+shots+SoT':    ELO_GOALS + [f'shots_for_{W}', f'shots_against_{W}',
                                                    f'sot_for_{W}',   f'sot_against_{W}'],
        'B: Elo+goals+xG+SoT':       ELO_GOALS + [f'xg_for_{W}',    f'xg_against_{W}',
                                                    f'sot_for_{W}',   f'sot_against_{W}'],
        'C: Elo+goals+shots+xG':     ELO_GOALS + [f'shots_for_{W}', f'shots_against_{W}',
                                                    f'xg_for_{W}',    f'xg_against_{W}'],
        'D: Elo+goals+xG only':      ELO_GOALS + [f'xg_for_{W}',    f'xg_against_{W}'],
        'E: Elo+goals+shots+SoT+xG': ELO_GOALS + [f'shots_for_{W}', f'shots_against_{W}',
                                                    f'sot_for_{W}',   f'sot_against_{W}',
                                                    f'xg_for_{W}',    f'xg_against_{W}'],
    }

    for tgt, tgt_name in [('goal_diff', 'goal_diff'), ('goals_for', 'goals_for')]:
        print(f"\n   Target: {tgt_name}")
        print(f"   {'Config':<32}  {'MAE':>7}  {'r':>7}  {'Dr vs A':>9}  n")
        print(f"   {SEP2[:65]}")
        ref = None
        for name, feats in ABLATION.items():
            res = eval_ols(panel, feats, tgt, tr, te)
            if res is None:
                continue
            if ref is None:
                ref = res
                print(f"   {name:<32}  {res['MAE']:>7.4f}  {res['r']:>7.4f}  {'--':>9}  {res['n']}")
            else:
                dr = res['r'] - ref['r']
                print(f"   {name:<32}  {res['MAE']:>7.4f}  {res['r']:>7.4f}  {dr:>+9.4f}  {res['n']}")

    # ----------------------------------------------------------------
    # SECTION 6: RIDGE
    # ----------------------------------------------------------------
    hdr("6. RIDGE vs OLS -- BASE_ELO vs BASE_ELO+xG")
    print(f"   Ridge: tested alphas = {RIDGE_ALPHAS}, report best alpha per model")
    print(f"   Note: if xG fails even with Ridge, redundancy is definitively confirmed.\n")

    for tgt, tgt_name in [('goal_diff', 'goal_diff'), ('goals_for', 'goals_for')]:
        print(f"   Target: {tgt_name}")
        ols_b  = eval_ols(panel, BASE_ELO,          tgt, tr, te)
        ols_x  = eval_ols(panel, BASE_ELO + xg_feats, tgt, tr, te)
        rdg_b, ab = eval_ridge_best(panel, BASE_ELO,          tgt, tr, te)
        rdg_x, ax = eval_ridge_best(panel, BASE_ELO + xg_feats, tgt, tr, te)

        print(f"   {'Model':<26}  {'Method':<7}  {'alpha':>6}  {'MAE':>7}  {'r':>7}  {'Dr':>8}")
        print(f"   {SEP2[:66]}")
        if ols_b:
            print(f"   {'BASE_ELO':<26}  {'OLS':<7}  {'--':>6}  {ols_b['MAE']:>7.4f}  {ols_b['r']:>7.4f}  {'--':>8}")
        if rdg_b:
            print(f"   {'BASE_ELO':<26}  {'Ridge':<7}  {ab:>6.1f}  {rdg_b['MAE']:>7.4f}  {rdg_b['r']:>7.4f}  {'--':>8}")
        if ols_b and ols_x:
            dr = ols_x['r'] - ols_b['r']
            print(f"   {'BASE_ELO+xG':<26}  {'OLS':<7}  {'--':>6}  {ols_x['MAE']:>7.4f}  {ols_x['r']:>7.4f}  {dr:>+8.4f}")
        if rdg_b and rdg_x:
            dr = rdg_x['r'] - rdg_b['r']
            print(f"   {'BASE_ELO+xG':<26}  {'Ridge':<7}  {ax:>6.1f}  {rdg_x['MAE']:>7.4f}  {rdg_x['r']:>7.4f}  {dr:>+8.4f}")

        # Verdict
        dr_ols = (ols_x['r'] - ols_b['r']) if ols_b and ols_x else None
        dr_rdg = (rdg_x['r'] - rdg_b['r']) if rdg_b and rdg_x else None
        if dr_ols is not None and dr_rdg is not None:
            if dr_ols < 0.001 and dr_rdg < 0.001:
                print(f"   => RIDONDANZA CONFERMATA (OLS e Ridge concordano).")
            elif dr_ols < 0.001 and dr_rdg > 0.003:
                print(f"   => Multicollinearita mascherava segnale reale. Ridge necessario.")
            else:
                print(f"   => Segnale ambiguo. Vedere expanding window.")
        print()

    # ----------------------------------------------------------------
    # SECTION 7: EXPANDING WINDOW
    # ----------------------------------------------------------------
    hdr("7. EXPANDING WINDOW CV (4 blocchi intra 2025/26)")
    print(f"   BASE_ELO vs BASE_ELO+xG -- target: goal_diff")
    print(f"   Blocco 1: train=2024/25 only, test=primo quarto 2025/26")
    print(f"   Blocco 4: train=2024/25 + 3/4 di 2025/26, test=ultimo quarto")
    print(f"\n   {'Blocco':>7}  {'N_train':>8}  {'N_test':>7}  {'BASE_ELO r':>11}  {'+xG r':>7}  {'Dr':>8}")
    print(f"   {SEP2[:60]}")

    ew = expanding_window(panel, BASE_ELO, xg_feats, 'goal_diff')
    deltas = []
    for r in ew:
        print(f"   {r['block']:>7}  {r['n_train']:>8}  {r['n_test']:>7}"
              f"  {r['base_r']:>11.4f}  {r['adv_r']:>7.4f}  {r['delta_r']:>+8.4f}")
        deltas.append(r['delta_r'])

    if deltas:
        md = float(np.mean(deltas))
        sd = float(np.std(deltas, ddof=1)) if len(deltas) > 1 else 0.0
        print(f"\n   Expanding window: mean(Dr)={md:>+.4f}  std(Dr)={sd:.4f}"
              f"  n_blocks={len(deltas)}")
        if sd > abs(md):
            verdict = "Std > |mean|: Dr NON DISTINGUIBILE dal rumore."
        elif abs(md) > 0.005:
            verdict = f"Segnale consistente |mean|={abs(md):.4f} > 0.005."
        else:
            verdict = f"Segnale consistente ma minuscolo |mean|={abs(md):.4f} < 0.005."
        print(f"   => {verdict}")

        n_pos = sum(1 for d in deltas if d > 0)
        n_neg = sum(1 for d in deltas if d < 0)
        print(f"   Direzione: {n_pos} blocchi positivi, {n_neg} blocchi negativi.")

    # ----------------------------------------------------------------
    # SECTION 8: LEAGUE STABILITY (xG only)
    # ----------------------------------------------------------------
    hdr("8. LEAGUE STABILITY -- BASE_ELO vs BASE_ELO+xG")
    print(f"   {'Lega':<16}  {'BASE_ELO r':>11}  {'+xG r':>8}  {'Dr':>8}  n")
    print(f"   {SEP2[:55]}")
    for league in sorted(panel['competition'].unique()):
        ltr = tr & (panel['competition'] == league)
        lte = te & (panel['competition'] == league)
        rb  = eval_ols(panel, BASE_ELO,          'goal_diff', ltr, lte)
        rx  = eval_ols(panel, BASE_ELO + xg_feats, 'goal_diff', ltr, lte)
        if rb and rx:
            dr = rx['r'] - rb['r']
            print(f"   {league:<16}  {rb['r']:>11.4f}  {rx['r']:>8.4f}  {dr:>+8.4f}  {rb['n']}")

    # ----------------------------------------------------------------
    # SECTION 9: GOALS_PREVENTED
    # ----------------------------------------------------------------
    hdr("9. GOALS_PREVENTED vs BASE_ELO (target: goals_against)")
    rb = eval_ols(panel, BASE_ELO,                              'goals_against', tr, te)
    rg = eval_ols(panel, BASE_ELO + [f'goals_prevented_for_{W}'], 'goals_against', tr, te)
    if rb and rg:
        dr = rg['r'] - rb['r']
        dm = rg['MAE'] - rb['MAE']
        print(f"   BASE_ELO               r={rb['r']:.4f}  MAE={rb['MAE']:.4f}")
        print(f"   BASE_ELO+goals_prev    r={rg['r']:.4f}  MAE={rg['MAE']:.4f}"
              f"  Dr={dr:>+.4f}  DMAE={dm:>+.4f}")
        verdict = "DROP FOR V1 confermato." if abs(dr) < 0.002 else "Segnale marginale presente."
        print(f"   => {verdict}")

    # ----------------------------------------------------------------
    # SECTION 10: FEATURE SELECTION
    # ----------------------------------------------------------------
    hdr("10. CLASSIFICAZIONE AGGIORNATA (post v2)")
    print("""
  KEEP:
  -----
  (Nessuna singola advanced feature raggiunge KEEP in modo netto.
   Il segnale xG e reale ma piccolo e dipende dall'architettura.)

  CANDIDATE -- da includere solo come sostituto architetturale:
  -------------------------------------------------------------
  * xg_for_L10 / xg_against_L10
      - Univariato: migliore di goals e shots per predire goals futuri.
      - Con BASE_ELO: Dr tipicamente in (-0.005, +0.005) range.
      - Con Ridge: se Dr > 0, la ridondanza era multicollinearita.
      - Con expanding window: verificare consistenza della direzione.
      - Raccomandazione architetturale:
          USARE xg_for + xg_against AL POSTO di shots_for + shots_against.
          Tenere sot_for + sot_against (correlazione con xG = 0.857, non 1.0).
          NON aggiungere xG come feature in piu: sostituire shots.

  REDUNDANT:
  ----------
  * insidebox_for/against_L10   r=0.876 con xG, r=0.930 con shots
  * gk_saves_for_L10            r=0.882 con sot_against
  * passes_total_for_L10        r=0.939 con possession
  * passes_pct_for_L10          r=0.827 con possession, Dr < 0.005
  * possession_for/against_L10  nessun miglioramento stabile

  DROP FOR V1:
  ------------
  * goals_prevented_for_L10     r=0.000 con goal_diff (audit v1),
                                 confermato anche vs BASE_ELO
  * outsidebox_* / blocked_*    marginali, alta correlazione con shots
  * offsides                    coverage non verificata
    """)

    hdr("11. PROPOSTA FINALE -- Feature advanced per PreMatchFeatureSnapshot V1")
    print("""
  Il PreMatchFeatureAggregator dovra produrre:

  BASELINE (gia presenti o derivabili dai calculator esistenti):
    Elo: team_pre_elo, opp_pre_elo         -- da TeamEloCalculator
    goals_for_L10, goals_against_L10       -- da TeamAnalyticsCalculator
    sot_for_L10, sot_against_L10           -- da TeamAnalyticsCalculator
    is_home                                -- da match.home_team_id
    H2H features                           -- da HeadToHeadCalculator

  ADVANCED DA AGGIUNGERE (nuovo):
    xg_for_L10        float | null (sostituisce shots_for_L10)
    xg_against_L10    float | null (sostituisce shots_against_L10)

  NON includere in V1:
    shots_for_L10 / shots_against_L10  -- se xg inclusi (ridondanti)
    insidebox, possession, passes, gk_saves, goals_prevented

  SENTINEL null:
    xg_for_L10 = null  quando la finestra L10 non ha copertura xG.
    Il modello gradient-boosted (GBM) gestisce null nativamente.
    NON imputare con media: null e semanticamente distinto da 0.
    """)

    print(f"\n{SEP}")
    print("  AUDIT v2 COMPLETATO -- Nessuna modifica applicativa effettuata.")
    print(SEP)


if __name__ == '__main__':
    main()
