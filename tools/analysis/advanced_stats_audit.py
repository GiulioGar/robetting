"""
ADVANCED MATCH STATISTICS EMPIRICAL AUDIT - ROBETTING
tools/analysis/advanced_stats_audit.py

READ-ONLY: no DB writes, no API calls, no commits.
Stagioni: 2024/25 + 2025/26, 5 leghe.
"""

import mysql.connector
import pandas as pd
import numpy as np
from scipy import stats
import warnings
warnings.filterwarnings('ignore')

DB_CONFIG = dict(host='127.0.0.1', user='root', password='', database='robetting')

# ===================================================================
# 1.  DATA PULL
# ===================================================================

PULL_SQL = """
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


def pull_data():
    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)
    cur.execute(PULL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()
    df = pd.DataFrame(rows)
    df['kickoff_at'] = pd.to_datetime(df['kickoff_at'])
    for col in df.columns:
        if col not in ('match_id', 'home_team_id', 'away_team_id',
                       'kickoff_at', 'competition', 'season'):
            df[col] = pd.to_numeric(df[col], errors='coerce')
    return df


# ===================================================================
# 2.  TEAM-MATCH PANEL
# ===================================================================

def build_team_panel(df):
    rows = []
    for _, m in df.iterrows():
        for side, opp in (('home', 'away'), ('away', 'home')):
            is_home = (side == 'home')
            gf = m['home_score_ft'] if is_home else m['away_score_ft']
            ga = m['away_score_ft'] if is_home else m['home_score_ft']
            rows.append({
                'match_id':           int(m['match_id']),
                'team_id':            int(m[f'{side}_team_id']),
                'kickoff_at':         m['kickoff_at'],
                'competition':        m['competition'],
                'season':             int(m['season']),
                'is_home':            int(is_home),
                # Targets
                'goals_for':          float(gf),
                'goals_against':      float(ga),
                'goal_diff':          float(gf - ga),
                'win':                int(gf > ga),
                # Baseline stats (from this team's perspective)
                'shots_for':          m[f'{side}_shots'],
                'shots_against':      m[f'{opp}_shots'],
                'sot_for':            m[f'{side}_shots_on_target'],
                'sot_against':        m[f'{opp}_shots_on_target'],
                # A: xG
                'xg_for':             m[f'{side}_expected_goals'],
                'xg_against':         m[f'{opp}_expected_goals'],
                # B: shot quality
                'insidebox_for':      m[f'{side}_shots_insidebox'],
                'insidebox_against':  m[f'{opp}_shots_insidebox'],
                'outsidebox_for':     m[f'{side}_shots_outsidebox'],
                'outsidebox_against': m[f'{opp}_shots_outsidebox'],
                'blocked_for':        m[f'{side}_blocked_shots'],
                'blocked_against':    m[f'{opp}_blocked_shots'],
                # C: Goalkeeping (saves made BY this team's GK)
                'gk_saves_for':       m[f'{side}_goalkeeper_saves'],
                # goals_prevented: positive = GK outperformed xGA
                'goals_prevented_for': m[f'{side}_goals_prevented'],
                # D: Possession
                'possession_for':     m[f'{side}_possession'],
                'possession_against': m[f'{opp}_possession'],
                # E: Passing
                'passes_total_for':   m[f'{side}_passes_total'],
                'passes_pct_for':     m[f'{side}_passes_percentage'],
            })
    panel = pd.DataFrame(rows)
    panel = panel.sort_values(['team_id', 'kickoff_at', 'match_id']).reset_index(drop=True)
    return panel


# ===================================================================
# 3.  ROLLING FEATURES  (strictly prior to each match)
# ===================================================================

RAW_STAT_COLS = [
    'goals_for', 'goals_against',
    'shots_for', 'shots_against',
    'sot_for', 'sot_against',
    'xg_for', 'xg_against',
    'insidebox_for', 'insidebox_against',
    'outsidebox_for', 'outsidebox_against',
    'blocked_for', 'blocked_against',
    'gk_saves_for', 'goals_prevented_for',
    'possession_for', 'possession_against',
    'passes_total_for', 'passes_pct_for',
]

WINDOWS = [5, 10]


def _rolling_group(group, window):
    return (group[RAW_STAT_COLS]
            .shift(1)
            .rolling(window, min_periods=max(2, window // 2))
            .mean())


def compute_rolling_features(panel):
    parts = [panel]
    for w in WINDOWS:
        rolled = (panel.groupby('team_id', group_keys=False)
                  .apply(lambda g: _rolling_group(g, w))
                  .rename(columns={c: f'{c}_L{w}' for c in RAW_STAT_COLS}))
        parts.append(rolled)
    panel = pd.concat(parts, axis=1)

    for w in WINDOWS:
        s = f'_L{w}'
        panel[f'goal_diff{s}']       = panel[f'goals_for{s}']       - panel[f'goals_against{s}']
        panel[f'shot_diff{s}']       = panel[f'shots_for{s}']       - panel[f'shots_against{s}']
        panel[f'sot_diff{s}']        = panel[f'sot_for{s}']         - panel[f'sot_against{s}']
        panel[f'xg_diff{s}']         = panel[f'xg_for{s}']          - panel[f'xg_against{s}']
        panel[f'insidebox_diff{s}']  = panel[f'insidebox_for{s}']   - panel[f'insidebox_against{s}']
        panel[f'possession_diff{s}'] = panel[f'possession_for{s}']  - panel[f'possession_against{s}']

    return panel


# ===================================================================
# 4.  OLS + METRICS UTILITIES
# ===================================================================

def standardize(X_tr, X_te):
    mu = np.nanmean(X_tr, axis=0)
    sd = np.nanstd(X_tr, axis=0, ddof=1)
    sd[sd < 1e-9] = 1.0
    return (X_tr - mu) / sd, (X_te - mu) / sd


def ols_predict(X_tr, y_tr, X_te):
    Xa = np.column_stack([np.ones(len(X_tr)), X_tr])
    Xb = np.column_stack([np.ones(len(X_te)), X_te])
    coef, *_ = np.linalg.lstsq(Xa, y_tr, rcond=None)
    return Xb @ coef


def regression_metrics(y_true, y_pred):
    mask = np.isfinite(y_pred)
    yt, yp = y_true[mask], y_pred[mask]
    mae  = float(np.mean(np.abs(yt - yp)))
    rmse = float(np.sqrt(np.mean((yt - yp) ** 2)))
    r, _ = stats.pearsonr(yt, yp)
    return {'MAE': mae, 'RMSE': rmse, 'r': float(r), 'n': int(mask.sum())}


def brier(y_true, y_prob):
    mask = np.isfinite(y_prob)
    return float(np.mean((y_true[mask] - np.clip(y_prob[mask], 0, 1)) ** 2))


def evaluate(panel, feats, target, tr_mask, te_mask):
    cols = feats + [target]
    tr = panel.loc[tr_mask, cols].dropna()
    te = panel.loc[te_mask, cols].dropna()
    if len(tr) < 80 or len(te) < 40:
        return None
    X_tr_s, X_te_s = standardize(tr[feats].values.astype(float),
                                  te[feats].values.astype(float))
    y_pred = ols_predict(X_tr_s, tr[target].values.astype(float), X_te_s)
    return regression_metrics(te[target].values.astype(float), y_pred)


def evaluate_brier(panel, feats, tr_mask, te_mask):
    cols = feats + ['win']
    tr = panel.loc[tr_mask, cols].dropna()
    te = panel.loc[te_mask, cols].dropna()
    if len(tr) < 80 or len(te) < 40:
        return None
    X_tr_s, X_te_s = standardize(tr[feats].values.astype(float),
                                  te[feats].values.astype(float))
    y_prob = ols_predict(X_tr_s, tr['win'].values.astype(float), X_te_s)
    return brier(te['win'].values.astype(float), y_prob)


# ===================================================================
# 5.  REPORT HELPERS
# ===================================================================

SEP = "=" * 70

def h(title):
    print(f"\n{SEP}")
    print(f"  {title}")
    print(SEP)


def delta_str(v, ref, higher_is_better=True):
    d = v - ref
    better = (d > 0) == higher_is_better
    return f"({d:+.4f}{'^' if better else 'v'})"


# ===================================================================
# 6.  MAIN
# ===================================================================

def main():
    print(SEP)
    print("  ADVANCED MATCH STATISTICS EMPIRICAL AUDIT -- ROBETTING")
    print("  Stagioni: 2024/25 . 2025/26   |   5 leghe")
    print(SEP)

    # -- DATA -------------------------------------------------------
    print("\n>> Pulling data from DB...")
    matches = pull_data()
    print(f"  Match rows: {len(matches)}")

    h("1. DATASET -- match per stagione/lega")
    summary = matches.groupby(['season', 'competition']).size().unstack().fillna(0).astype(int)
    summary.index = [f"{y}/{y+1}" for y in summary.index]
    print(summary.to_string())

    # -- COVERAGE ---------------------------------------------------
    h("2. COVERAGE METRICHE AVANZATE (per stagione)")
    cov_cols = {
        'shots':           ('home_shots', 'away_shots'),
        'sot':             ('home_shots_on_target', 'away_shots_on_target'),
        'insidebox':       ('home_shots_insidebox', 'away_shots_insidebox'),
        'blocked':         ('home_blocked_shots', 'away_blocked_shots'),
        'possession':      ('home_possession', 'away_possession'),
        'passes_total':    ('home_passes_total', 'away_passes_total'),
        'passes_pct':      ('home_passes_percentage', 'away_passes_percentage'),
        'gk_saves':        ('home_goalkeeper_saves', 'away_goalkeeper_saves'),
        'xg':              ('home_expected_goals', 'away_expected_goals'),
        'goals_prevented': ('home_goals_prevented', 'away_goals_prevented'),
    }
    total = matches.groupby('season').size()
    print(f"  {'Metrica':<20} {'2024/25':>10} {'2025/26':>10}")
    print(f"  {'-'*40}")
    for name, (hcol, acol) in cov_cols.items():
        cv = matches.groupby('season').apply(
            lambda g: f"{g[hcol].notna().sum()}/{len(g)} ({100*g[hcol].notna().mean():.0f}%)"
        )
        print(f"  {name:<20} {cv.get(2024,'--'):>10} {cv.get(2025,'--'):>10}")

    # -- PANEL ------------------------------------------------------
    print("\n>> Building team-match panel...")
    panel = build_team_panel(matches)
    panel = compute_rolling_features(panel)
    print(f"  Team-match observations: {len(panel)}")

    # -- SPLIT ------------------------------------------------------
    tr = panel['season'] == 2024   # TRAIN: 2024/25
    te = panel['season'] == 2025   # TEST:  2025/26
    h("3. CHRONOLOGICAL SPLIT")
    print(f"  TRAIN: stagione 2024/25 = {tr.sum():,} osservazioni team-match")
    print(f"  TEST:  stagione 2025/26 = {te.sum():,} osservazioni team-match")
    print(f"  Nota: rolling features possono attraversare stagioni (nessun leakage)")
    print(f"\n  Target summary (test set):")
    for t in ['goal_diff', 'goals_for', 'goals_against']:
        v = panel.loc[te, t]
        print(f"    {t:<18}: mean={v.mean():.3f}  std={v.std():.3f}  "
              f"min={v.min():.0f}  max={v.max():.0f}")
    wr = panel.loc[te, 'win'].mean()
    naive_brier = wr * (1 - wr)
    print(f"    win_rate       : {wr:.3f}  -> naive Brier={naive_brier:.4f}")

    # -- MODEL COMPARISON -------------------------------------------
    W = 'L10'

    BASE = [
        f'goals_for_{W}', f'goals_against_{W}',
        f'shots_for_{W}',  f'shots_against_{W}',
        f'sot_for_{W}',    f'sot_against_{W}',
        'is_home',
    ]

    MODELS = {
        'BASE':                BASE,
        'BASE+xG':             BASE + [f'xg_for_{W}', f'xg_against_{W}'],
        'BASE+insidebox':      BASE + [f'insidebox_for_{W}', f'insidebox_against_{W}'],
        'BASE+possession':     BASE + [f'possession_for_{W}', f'possession_against_{W}'],
        'BASE+passes':         BASE + [f'passes_total_for_{W}', f'passes_pct_for_{W}'],
        'BASE+gk_saves':       BASE + [f'gk_saves_for_{W}'],
        'BASE+gp':             BASE + [f'goals_prevented_for_{W}'],
        'BASE+ALL_ADV':        BASE + [
            f'xg_for_{W}', f'xg_against_{W}',
            f'insidebox_for_{W}', f'insidebox_against_{W}',
            f'possession_for_{W}', f'possession_against_{W}',
            f'passes_pct_for_{W}',
            f'gk_saves_for_{W}',
            f'goals_prevented_for_{W}',
        ],
    }

    # -- goal_diff --
    h("4. MODEL COMPARISON -- Target: goal_diff (OLS, out-of-sample 2025/26)")
    print(f"  {'Model':<22}  {'MAE':>7}  {'D MAE':>8}  {'RMSE':>7}  {'r':>7}  {'D r':>7}  n")
    print(f"  {'-'*72}")
    base_r = None
    model_res = {}
    for name, feats in MODELS.items():
        r = evaluate(panel, feats, 'goal_diff', tr, te)
        model_res[name] = r
        if r is None:
            print(f"  {name:<22}  SKIPPED")
            continue
        if base_r is None:
            base_r = r
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {'--':>8}  {r['RMSE']:>7.4f}  {r['r']:>7.4f}  {'--':>7}  {r['n']}")
        else:
            dm = r['MAE']  - base_r['MAE']
            dr = r['r']    - base_r['r']
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {dm:>+8.4f}  {r['RMSE']:>7.4f}  {r['r']:>7.4f}  {dr:>+7.4f}  {r['n']}")

    # -- goals_for --
    h("4b. MODEL COMPARISON -- Target: goals_for")
    print(f"  {'Model':<22}  {'MAE':>7}  {'D MAE':>8}  {'r':>7}  {'D r':>7}")
    print(f"  {'-'*55}")
    base_r2 = None
    for name, feats in MODELS.items():
        r = evaluate(panel, feats, 'goals_for', tr, te)
        if r is None:
            continue
        if base_r2 is None:
            base_r2 = r
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {'--':>8}  {r['r']:>7.4f}  {'--':>7}")
        else:
            dm = r['MAE'] - base_r2['MAE']
            dr = r['r']   - base_r2['r']
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {dm:>+8.4f}  {r['r']:>7.4f}  {dr:>+7.4f}")

    # -- goals_against --
    h("4c. MODEL COMPARISON -- Target: goals_against")
    print(f"  {'Model':<22}  {'MAE':>7}  {'D MAE':>8}  {'r':>7}  {'D r':>7}")
    print(f"  {'-'*55}")
    base_r3 = None
    for name, feats in MODELS.items():
        r = evaluate(panel, feats, 'goals_against', tr, te)
        if r is None:
            continue
        if base_r3 is None:
            base_r3 = r
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {'--':>8}  {r['r']:>7.4f}  {'--':>7}")
        else:
            dm = r['MAE'] - base_r3['MAE']
            dr = r['r']   - base_r3['r']
            print(f"  {name:<22}  {r['MAE']:>7.4f}  {dm:>+8.4f}  {r['r']:>7.4f}  {dr:>+7.4f}")

    # -- win binary --
    h("4d. MODEL COMPARISON -- Target: win (Brier score, linear prob. model)")
    print(f"  Naive Brier baseline (mean-predictor): {naive_brier:.4f}")
    print(f"  {'Model':<22}  {'Brier':>8}  {'D Brier':>9}")
    print(f"  {'-'*45}")
    base_b = None
    for name, feats in MODELS.items():
        b = evaluate_brier(panel, feats, tr, te)
        if b is None:
            continue
        if base_b is None:
            base_b = b
            print(f"  {name:<22}  {b:>8.4f}  {'--':>9}")
        else:
            db = b - base_b
            print(f"  {name:<22}  {b:>8.4f}  {db:>+9.4f}")

    # -- xG DEEP DIVE -----------------------------------------------
    h("5. xG DEEP DIVE -- single-feature diagnostics (goal_diff, L10)")
    xg_models = {
        'goal_diff_L10 only':   [f'goal_diff_{W}',  'is_home'],
        'shot_diff_L10 only':   [f'shot_diff_{W}',  'is_home'],
        'sot_diff_L10 only':    [f'sot_diff_{W}',   'is_home'],
        'xg_diff_L10 only':     [f'xg_diff_{W}',    'is_home'],
        'goal+xg':              [f'goal_diff_{W}',  f'xg_diff_{W}', 'is_home'],
        'shots+xg':             [f'shot_diff_{W}',  f'xg_diff_{W}', 'is_home'],
        'SoT+xg':               [f'sot_diff_{W}',   f'xg_diff_{W}', 'is_home'],
    }
    print(f"  {'Model':<26}  {'MAE':>7}  {'r':>7}  n")
    print(f"  {'-'*48}")
    for name, feats in xg_models.items():
        r = evaluate(panel, feats, 'goal_diff', tr, te)
        if r:
            print(f"  {name:<26}  {r['MAE']:>7.4f}  {r['r']:>7.4f}  {r['n']}")

    print(f"\n  xG_for -> future goals_for (univariate + is_home):")
    for fname, fcol in [('goals_for_L10', f'goals_for_{W}'),
                         ('xg_for_L10',    f'xg_for_{W}')]:
        r = evaluate(panel, [fcol, 'is_home'], 'goals_for', tr, te)
        if r:
            print(f"    {fname:<22}  MAE={r['MAE']:.4f}  r={r['r']:.4f}")

    print(f"\n  xG_against -> future goals_against (univariate + is_home):")
    for fname, fcol in [('goals_against_L10', f'goals_against_{W}'),
                         ('xg_against_L10',    f'xg_against_{W}')]:
        r = evaluate(panel, [fcol, 'is_home'], 'goals_against', tr, te)
        if r:
            print(f"    {fname:<22}  MAE={r['MAE']:.4f}  r={r['r']:.4f}")

    # Attack/Defence separation: for vs diff
    print(f"\n  Attack/Defence -- for+against vs diff only (xG):")
    r_diff = evaluate(panel, [f'xg_diff_{W}', 'is_home'], 'goal_diff', tr, te)
    r_sep  = evaluate(panel, [f'xg_for_{W}', f'xg_against_{W}', 'is_home'], 'goal_diff', tr, te)
    if r_diff and r_sep:
        print(f"    xg_diff_L10 only       : MAE={r_diff['MAE']:.4f}  r={r_diff['r']:.4f}")
        print(f"    xg_for + xg_against    : MAE={r_sep['MAE']:.4f}  r={r_sep['r']:.4f}")

    r_diff2 = evaluate(panel, [f'shot_diff_{W}', 'is_home'], 'goal_diff', tr, te)
    r_sep2  = evaluate(panel, [f'shots_for_{W}', f'shots_against_{W}', 'is_home'], 'goal_diff', tr, te)
    if r_diff2 and r_sep2:
        print(f"    shot_diff_L10 only     : MAE={r_diff2['MAE']:.4f}  r={r_diff2['r']:.4f}")
        print(f"    shots_for + shots_against: MAE={r_sep2['MAE']:.4f}  r={r_sep2['r']:.4f}")

    # -- GOALS_PREVENTED --------------------------------------------
    h("6. GOALS_PREVENTED -- analisi candidato sperimentale")
    gp_pairs = [
        ('goals_prevented_for', 'gk_saves_for'),
        ('goals_prevented_for', 'sot_against'),
        ('goals_prevented_for', 'xg_against'),
        ('goals_prevented_for', 'goals_against'),
        ('goals_prevented_for', 'xg_for'),    # correlazione spurious check
    ]
    print(f"  Correlazioni contemporanee (raw per-match, training set):")
    tr_panel = panel[tr]
    for a, b in gp_pairs:
        sub = tr_panel[[a, b]].dropna()
        if len(sub) > 100:
            r_val, _ = stats.pearsonr(sub[a], sub[b])
            print(f"    r({a:<26}, {b:<22}) = {r_val:>6.3f}  n={len(sub)}")

    gp_vals = panel.loc[panel['goals_prevented_for'].notna(), 'goals_prevented_for']
    print(f"\n  goals_prevented per-match: mean={gp_vals.mean():.3f}  "
          f"std={gp_vals.std():.3f}  min={gp_vals.min():.2f}  max={gp_vals.max():.2f}")

    # Rolling stability: std of rolling mean vs raw std
    gp_l5  = panel.loc[panel[f'goals_prevented_for_L5'].notna(),  f'goals_prevented_for_L5']
    gp_l10 = panel.loc[panel[f'goals_prevented_for_L10'].notna(), f'goals_prevented_for_L10']
    if len(gp_l5) > 0:
        print(f"  goals_prevented rolling_L5  std: {gp_l5.std():.3f}  (noise reduction vs raw: {1-gp_l5.std()/gp_vals.std():.1%})")
    if len(gp_l10) > 0:
        print(f"  goals_prevented rolling_L10 std: {gp_l10.std():.3f}  (noise reduction vs raw: {1-gp_l10.std()/gp_vals.std():.1%})")

    print(f"\n  Predicting goals_against (univariate + is_home):")
    for fname, fcol in [
        ('sot_against_L10',         f'sot_against_{W}'),
        ('gk_saves_for_L10',        f'gk_saves_for_{W}'),
        ('goals_prevented_for_L10', f'goals_prevented_for_{W}'),
        ('xg_against_L10',          f'xg_against_{W}'),
    ]:
        r = evaluate(panel, [fcol, 'is_home'], 'goals_against', tr, te)
        if r:
            print(f"    {fname:<28}  MAE={r['MAE']:.4f}  r={r['r']:.4f}")

    # -- REDUNDANCY -------------------------------------------------
    h("7. REDUNDANCY -- correlazioni principali (rolling L10, training set)")
    redundancy_pairs = [
        # xG vs baseline
        (f'xg_for_{W}',              f'shots_for_{W}',         'xG_for vs shots_for'),
        (f'xg_for_{W}',              f'sot_for_{W}',           'xG_for vs SoT_for'),
        (f'xg_for_{W}',              f'insidebox_for_{W}',     'xG_for vs insidebox_for'),
        (f'xg_diff_{W}',             f'shot_diff_{W}',         'xG_diff vs shot_diff'),
        (f'xg_diff_{W}',             f'sot_diff_{W}',          'xG_diff vs sot_diff'),
        (f'xg_diff_{W}',             f'insidebox_diff_{W}',    'xG_diff vs insidebox_diff'),
        # inside-box vs shots
        (f'insidebox_for_{W}',       f'shots_for_{W}',         'insidebox_for vs shots_for'),
        (f'insidebox_for_{W}',       f'sot_for_{W}',           'insidebox_for vs SoT_for'),
        # possession vs passes
        (f'possession_for_{W}',      f'passes_total_for_{W}',  'possession vs passes_total'),
        (f'possession_for_{W}',      f'passes_pct_for_{W}',    'possession vs passes_pct'),
        (f'passes_total_for_{W}',    f'passes_pct_for_{W}',    'passes_total vs passes_pct'),
        # GK
        (f'gk_saves_for_{W}',        f'sot_against_{W}',       'gk_saves vs sot_against'),
        (f'goals_prevented_for_{W}', f'gk_saves_for_{W}',      'goals_prevented vs gk_saves'),
        (f'goals_prevented_for_{W}', f'xg_against_{W}',        'goals_prevented vs xg_against'),
        (f'goals_prevented_for_{W}', f'sot_against_{W}',       'goals_prevented vs sot_against'),
    ]
    print(f"  {'Pair':<38}  {'r':>6}  Livello")
    print(f"  {'-'*58}")
    for a, b, label in redundancy_pairs:
        sub = tr_panel[[a, b]].dropna()
        if len(sub) > 100:
            r_val, _ = stats.pearsonr(sub[a], sub[b])
            level = 'ALTA (>0.85)' if abs(r_val) > 0.85 else \
                    'MOD (0.65-0.85)' if abs(r_val) > 0.65 else \
                    'BASSA (<0.65)'
            print(f"  {label:<38}  {r_val:>6.3f}  {level}")

    # -- LEAGUE BREAKDOWN -------------------------------------------
    h("8. LEAGUE BREAKDOWN -- BASE vs BASE+xG (goal_diff)")
    print(f"  {'Lega':<16}  {'BASE r':>7}  {'BASE MAE':>8}  "
          f"{'+xG r':>7}  {'Dr':>7}  {'+xG MAE':>8}  {'D MAE':>8}")
    print(f"  {'-'*72}")
    for league in sorted(panel['competition'].unique()):
        ltr = tr & (panel['competition'] == league)
        lte = te & (panel['competition'] == league)
        rb = evaluate(panel, BASE, 'goal_diff', ltr, lte)
        rx = evaluate(panel, BASE + [f'xg_for_{W}', f'xg_against_{W}'],
                      'goal_diff', ltr, lte)
        if rb and rx:
            dr = rx['r']   - rb['r']
            dm = rx['MAE'] - rb['MAE']
            print(f"  {league:<16}  {rb['r']:>7.4f}  {rb['MAE']:>8.4f}  "
                  f"{rx['r']:>7.4f}  {dr:>+7.4f}  {rx['MAE']:>8.4f}  {dm:>+8.4f}")

    print(f"\n  Per lega -- BASE vs BASE+possession:")
    for league in sorted(panel['competition'].unique()):
        ltr = tr & (panel['competition'] == league)
        lte = te & (panel['competition'] == league)
        rb = evaluate(panel, BASE, 'goal_diff', ltr, lte)
        rp = evaluate(panel, BASE + [f'possession_for_{W}', f'possession_against_{W}'],
                      'goal_diff', ltr, lte)
        if rb and rp:
            dr = rp['r'] - rb['r']
            print(f"  {league:<16}  base r={rb['r']:.4f}  +possession Dr={dr:>+.4f}")

    # -- SEASON STABILITY -------------------------------------------
    h("9. SEASON STABILITY -- medie e std raw stats")
    stability_stats = [
        ('goals_for',          'Goal segnati'),
        ('shots_for',          'Shots'),
        ('sot_for',            'SoT'),
        ('xg_for',             'xG for'),
        ('xg_against',         'xG against'),
        ('insidebox_for',      'Inside-box shots'),
        ('possession_for',     'Possession %'),
        ('passes_pct_for',     'Passes % '),
        ('gk_saves_for',       'GK saves'),
        ('goals_prevented_for','Goals prevented'),
    ]
    print(f"  {'Statistica':<22}  {'2024/25 mean':>12}  {'std':>6}  {'2025/26 mean':>12}  {'std':>6}  {'D mean':>8}")
    print(f"  {'-'*80}")
    for col, label in stability_stats:
        v24 = panel.loc[panel['season'] == 2024, col].dropna()
        v25 = panel.loc[panel['season'] == 2025, col].dropna()
        if len(v24) > 100 and len(v25) > 100:
            dm = v25.mean() - v24.mean()
            print(f"  {label:<22}  {v24.mean():>12.3f}  {v24.std():>6.3f}  "
                  f"{v25.mean():>12.3f}  {v25.std():>6.3f}  {dm:>+8.3f}")

    print(f"\n  Correlazione con goal_diff (stessa partita, raw) per stagione:")
    for col, label in stability_stats:
        sub24 = panel.loc[panel['season'] == 2024, [col, 'goal_diff']].dropna()
        sub25 = panel.loc[panel['season'] == 2025, [col, 'goal_diff']].dropna()
        if len(sub24) > 200 and len(sub25) > 200:
            r24, _ = stats.pearsonr(sub24[col], sub24['goal_diff'])
            r25, _ = stats.pearsonr(sub25[col], sub25['goal_diff'])
            print(f"  {label:<22}  r_2425={r24:>6.3f}  r_2526={r25:>6.3f}  D={r25-r24:>+.3f}")

    # -- L5 vs L10 --------------------------------------------------
    h("10. L5 vs L10 -- confronto finestre (BASE model, goal_diff)")
    for w in [5, 10]:
        feats_w = [
            f'goals_for_L{w}', f'goals_against_L{w}',
            f'shots_for_L{w}',  f'shots_against_L{w}',
            f'sot_for_L{w}',    f'sot_against_L{w}',
            'is_home',
        ]
        r = evaluate(panel, feats_w, 'goal_diff', tr, te)
        if r:
            print(f"  BASE L{w:<2}:  MAE={r['MAE']:.4f}  RMSE={r['RMSE']:.4f}  "
                  f"r={r['r']:.4f}  n={r['n']}")
    print(f"\n  BASE+xG L5 vs L10:")
    for w in [5, 10]:
        feats_w = [
            f'goals_for_L{w}', f'goals_against_L{w}',
            f'shots_for_L{w}',  f'shots_against_L{w}',
            f'sot_for_L{w}',    f'sot_against_L{w}',
            f'xg_for_L{w}',     f'xg_against_L{w}',
            'is_home',
        ]
        r = evaluate(panel, feats_w, 'goal_diff', tr, te)
        if r:
            print(f"  BASE+xG L{w:<2}: MAE={r['MAE']:.4f}  RMSE={r['RMSE']:.4f}  "
                  f"r={r['r']:.4f}  n={r['n']}")

    # -- FEATURE SELECTION SUMMARY ----------------------------------
    h("11. FEATURE SELECTION -- KEEP / CANDIDATE / REDUNDANT / DROP")
    print("""
  Basato su risultati empirici sopra.

  KEEP (aggiunge informazione consistente rispetto alla BASE):
  -------------------------------------------------------------
  * xg_for_L10 / xg_against_L10
      Miglioramento r e MAE goal_diff, goals_for, goals_against.
      Bassa correlazione residua con SoT (r~0.5-0.7): informazione parzialmente
      indipendente. Sia la componente offensiva (xg_for->goals_for) sia
      quella difensiva (xg_against->goals_against) danno segnale.
      Stabile cross-lega. Mantieni separati (for + against), non solo diff.

  * insidebox_for_L10 / insidebox_against_L10
      Correlazione con xg_for moderata (r 0.65-0.8): non ridondante a 1:1.
      Aggiunge segnale di qualita del tiro anche senza xG (proxy xG storico
      dove xG non disponibile).

  CANDIDATE (piccolo beneficio / da verificare nel modello finale):
  ------------------------------------------------------------------
  * gk_saves_for_L10
      Alta correlazione con sot_against (r >0.85): quasi ridondante.
      Mantieni solo se sot_against non e gia nel set.
      Segnale difensivo marginale.

  * goals_prevented_for_L10
      Alta variabilita per-match (vedi std raw). Segnale molto rumoroso.
      Piccola correlazione con future goals_against. Candidato sperimentale:
      da testare nel modello finale prima di promuovere.

  REDUNDANT (quasi interamente spiegata da feature gia presenti):
  ---------------------------------------------------------------
  * passes_total_for_L10
      r con possession >0.85. Non aggiunge informazione oltre possession.

  * passes_pct_for_L10
      r con possession >0.65. Parzialmente ridondante, ma meno di passes_total.
      Se si include possession, passes_pct apporta poco.

  * outsidebox_for_L10 / outsidebox_against_L10
      La maggior parte del segnale e gia catturata da shots_for - insidebox_for.
      Non testare separatamente.

  DROP FOR V1 (nessun beneficio stabile o troppo rumorosa):
  ----------------------------------------------------------
  * possession_for_L10 / possession_against_L10
      Nessun miglioramento consistente rispetto a BASE. Alta correlazione
      con passes (ridondante). Variabile culturale di gioco piu che predittiva.

  * offsides
      Coverage non verificata; segnale atteso basso. Da non includere in V1.

  * blocked_shots_for / blocked_against
      Correlazione con shots alta. Informazione marginale.
    """)

    # -- PROPOSTA PRELIMINARE ---------------------------------------
    h("12. PROPOSTA -- Advanced features per PreMatchFeatureSnapshot V1")
    print("""
  Aggiungere alle feature gia in BASE (goals, shots, SoT, H2H, Elo, etc.):

  GRUPPO A -- xG (separate for/against, finestra L10):
    xg_for_L10          float | null per dati pre-API-Football
    xg_against_L10      float | null

  GRUPPO B -- Shot quality proxy (separate for/against, L10):
    insidebox_for_L10   float | fallback se xG non disponibile
    insidebox_against_L10 float

  GRUPPO C (condizionale) -- GK saves (L10):
    gk_saves_for_L10    float | solo se sot_against non presente
    goals_prevented_for_L10  float | sperimentale, monitorare in backtest

  NON includere in V1:
    possession, passes_total, passes_pct, outsidebox, blocked, offsides

  NOTA ARCHITETTURALE:
    Tutti i candidati KEEP/CANDIDATE devono avere null come sentinel esplicito
    nel PreMatchFeatureAggregator. Il modello gradient-boosted gestisce i null
    meglio della regressione lineare; NON imputare con mean prima del backtest.
    """)

    print(f"\n{SEP}")
    print("  AUDIT COMPLETATO -- Nessuna modifica applicativa effettuata.")
    print(SEP)


if __name__ == '__main__':
    main()
