"""
E12 Time Decay Analysis — Robetting
====================================
Empirical evaluation of time-decay configurations for recent team performance.

Rules:
- STRICTLY pre-match: for each target match M, only uses matches with kickoff_at < M.kickoff_at
- NO leakage
- NO production code modification
- Compares: no_decay, half-life 7/14/21/28/42 days
- Metrics: goal_diff, shot_diff, sot_diff (raw) + opponent-adjusted (E10 approximation)
- Results split by league and season
"""

import math
import warnings
import mysql.connector
import pandas as pd
import numpy as np
from scipy import stats
from collections import defaultdict

warnings.filterwarnings('ignore')

# ─────────────────────────────────────────
# 1. DB CONNECTION
# ─────────────────────────────────────────
DB_CFG = dict(host='localhost', user='root', password='', database='robetting')

def get_conn():
    return mysql.connector.connect(**DB_CFG)

# ─────────────────────────────────────────
# 2. DATA EXTRACTION
# ─────────────────────────────────────────

MATCHES_SQL = """
SELECT
    m.id            AS match_id,
    m.competition_id,
    m.season_id,
    c.slug          AS league,
    s.name          AS season,
    m.home_team_id,
    m.away_team_id,
    m.kickoff_at,
    m.home_score_ft,
    m.away_score_ft,
    ms.home_shots,
    ms.away_shots,
    ms.home_shots_on_target,
    ms.away_shots_on_target
FROM matches m
JOIN competitions c ON c.id = m.competition_id
JOIN seasons s      ON s.id = m.season_id
LEFT JOIN match_statistics ms ON ms.match_id = m.id
WHERE m.status = 'finished'
  AND m.home_score_ft IS NOT NULL
  AND m.away_score_ft IS NOT NULL
ORDER BY m.kickoff_at
"""

def load_data():
    conn = get_conn()
    df = pd.read_sql(MATCHES_SQL, conn, parse_dates=['kickoff_at'])
    conn.close()
    df['kickoff_at'] = pd.to_datetime(df['kickoff_at'])
    return df

# ─────────────────────────────────────────
# 3. DECAY FUNCTIONS
# ─────────────────────────────────────────

HALF_LIVES = [7, 14, 21, 28, 42]

def weight_no_decay(days_ago):
    return 1.0

def weight_exp(half_life):
    """weight = 0.5 ^ (days_ago / half_life)"""
    def _w(days_ago):
        return 0.5 ** (days_ago / half_life)
    return _w

DECAY_CONFIGS = {'no_decay': weight_no_decay}
for hl in HALF_LIVES:
    DECAY_CONFIGS[f'hl_{hl}'] = weight_exp(hl)

# ─────────────────────────────────────────
# 4. ELO ENGINE (replica minima di TeamEloCalculator)
# ─────────────────────────────────────────

BASE_ELO   = 1500.0
K_FACTOR   = 32.0
HOME_ADV   = 50.0

def expected_score(elo_a, elo_b):
    return 1.0 / (1.0 + 10 ** ((elo_b - elo_a) / 400.0))

def build_elo_timeline(df):
    """
    Replay all matches in chronological order, building:
      elo_before[match_id][team_id] = elo just before that match
    Also returns league_mean_elo[match_id] = mean elo of all teams
    in that competition+season just before that match.
    """
    elos = {}  # team_id -> current elo
    elo_before = {}  # match_id -> {home: float, away: float}

    # group by competition+season to compute league means
    league_teams = defaultdict(set)
    for _, row in df.iterrows():
        key = (row['competition_id'], row['season_id'])
        league_teams[key].add(row['home_team_id'])
        league_teams[key].add(row['away_team_id'])

    for _, row in df.sort_values('kickoff_at').iterrows():
        mid   = row['match_id']
        home  = row['home_team_id']
        away  = row['away_team_id']
        h_elo = elos.get(home, BASE_ELO)
        a_elo = elos.get(away, BASE_ELO)

        elo_before[mid] = {'home_elo': h_elo, 'away_elo': a_elo}

        # update after result
        hg = row['home_score_ft']
        ag = row['away_score_ft']
        if hg > ag:
            h_actual, a_actual = 1.0, 0.0
        elif hg == ag:
            h_actual, a_actual = 0.5, 0.5
        else:
            h_actual, a_actual = 0.0, 1.0

        h_exp = expected_score(h_elo + HOME_ADV, a_elo)
        a_exp = 1.0 - h_exp
        elos[home] = h_elo + K_FACTOR * (h_actual - h_exp)
        elos[away] = a_elo + K_FACTOR * (a_actual - a_exp)

    # compute per-match league mean elo (mean of all team elos BEFORE the match)
    # We use the elo_before of each team in the same competition+season
    # For simplicity: league_mean_elo[mid] = mean of all teams' pre-match elos
    # (approximated as mean over all seen matches so far in that league+season)
    league_mean = {}
    comp_season_elos = defaultdict(dict)  # (comp, season) -> {team_id: elo just before}

    for _, row in df.sort_values('kickoff_at').iterrows():
        mid  = row['match_id']
        key  = (row['competition_id'], row['season_id'])
        home = row['home_team_id']
        away = row['away_team_id']
        h_elo = elo_before[mid]['home_elo']
        a_elo = elo_before[mid]['away_elo']
        comp_season_elos[key][home] = h_elo
        comp_season_elos[key][away] = a_elo
        # compute mean from snapshot at this moment
        # use elo_before for this match's teams and stored values for others
        all_elos = list(comp_season_elos[key].values())
        league_mean[mid] = float(np.mean(all_elos)) if all_elos else BASE_ELO

    return elo_before, league_mean

# ─────────────────────────────────────────
# 5. BUILD TEAM-PERSPECTIVE ROWS
# ─────────────────────────────────────────

ADJ_COEFFS = {'goal_diff': 0.00764, 'shot_diff': 0.04170, 'sot_diff': 0.01709}

def build_team_rows(df, elo_before, league_mean):
    """
    For each match, create two rows: one home-perspective, one away-perspective.
    Each row has:
      match_id, team_id, opponent_id, kickoff_at, is_home, league, season,
      competition_id, season_id,
      goal_diff, shot_diff, sot_diff,        <- raw (from team perspective)
      adj_goal_diff, adj_shot_diff, adj_sot_diff  <- E10 opponent-adjusted
    """
    rows = []
    for _, r in df.iterrows():
        mid      = r['match_id']
        h_elo    = elo_before[mid]['home_elo']
        a_elo    = elo_before[mid]['away_elo']
        lg_mean  = league_mean[mid]
        opp_elo_delta_home = a_elo - lg_mean   # opponent of home team = away team
        opp_elo_delta_away = h_elo - lg_mean   # opponent of away team = home team

        # raw metrics (perspective: positive = team_scored_more)
        g_diff_h = r['home_score_ft'] - r['away_score_ft']
        g_diff_a = -g_diff_h

        h_shots = r['home_shots']
        a_shots = r['away_shots']
        h_sot   = r['home_shots_on_target']
        a_sot   = r['away_shots_on_target']

        sh_diff_h = (h_shots - a_shots) if pd.notna(h_shots) and pd.notna(a_shots) else None
        sh_diff_a = -sh_diff_h if sh_diff_h is not None else None
        st_diff_h = (h_sot - a_sot)   if pd.notna(h_sot)   and pd.notna(a_sot)   else None
        st_diff_a = -st_diff_h if st_diff_h is not None else None

        def adjusted(raw, metric, opp_delta):
            if raw is None:
                return None
            c = ADJ_COEFFS[metric]
            return raw + c * opp_delta

        for is_home, team_id, opp_id, g_diff, sh_diff, st_diff, opp_delta in [
            (True,  r['home_team_id'], r['away_team_id'], g_diff_h, sh_diff_h, st_diff_h, opp_elo_delta_home),
            (False, r['away_team_id'], r['home_team_id'], g_diff_a, sh_diff_a, st_diff_a, opp_elo_delta_away),
        ]:
            rows.append({
                'match_id':       mid,
                'team_id':        team_id,
                'opponent_id':    opp_id,
                'kickoff_at':     r['kickoff_at'],
                'is_home':        is_home,
                'league':         r['league'],
                'season':         r['season'],
                'competition_id': r['competition_id'],
                'season_id':      r['season_id'],
                # raw
                'goal_diff':      float(g_diff),
                'shot_diff':      float(sh_diff) if sh_diff is not None else np.nan,
                'sot_diff':       float(st_diff) if st_diff is not None else np.nan,
                # adj
                'adj_goal_diff':  adjusted(g_diff,  'goal_diff', opp_delta),
                'adj_shot_diff':  adjusted(sh_diff,  'shot_diff',  opp_delta) if sh_diff is not None else np.nan,
                'adj_sot_diff':   adjusted(st_diff,  'sot_diff',   opp_delta) if st_diff is not None else np.nan,
            })
    return pd.DataFrame(rows)

# ─────────────────────────────────────────
# 6. WEIGHTED AVERAGE COMPUTATION
# ─────────────────────────────────────────

METRICS = ['goal_diff', 'shot_diff', 'sot_diff', 'adj_goal_diff', 'adj_shot_diff', 'adj_sot_diff']

def weighted_avg(values, weights):
    """Returns weighted average or nan if sum(weights)==0 or no valid pairs."""
    valid = [(v, w) for v, w in zip(values, weights) if not math.isnan(v)]
    if not valid:
        return np.nan
    sw = sum(w for _, w in valid)
    if sw == 0:
        return np.nan
    return sum(v * w for v, w in valid) / sw

def compute_weighted_features(team_rows, target_kickoff, team_id, weight_fn,
                               max_matches=None, max_horizon_days=None):
    """
    For a given target match kickoff, compute weighted metrics for a team.
    Uses only rows with kickoff_at < target_kickoff.
    Optionally limits to last N matches (max_matches) or within X days (max_horizon_days).
    """
    hist = team_rows[
        (team_rows['team_id'] == team_id) &
        (team_rows['kickoff_at'] < target_kickoff)
    ].sort_values('kickoff_at', ascending=False)  # most recent first

    if hist.empty:
        return {m: np.nan for m in METRICS}, 0

    if max_horizon_days is not None:
        cutoff_date = target_kickoff - pd.Timedelta(days=max_horizon_days)
        hist = hist[hist['kickoff_at'] >= cutoff_date]

    if max_matches is not None:
        hist = hist.head(max_matches)

    if hist.empty:
        return {m: np.nan for m in METRICS}, 0

    # compute days_ago for each historical match
    hist = hist.copy()
    hist['days_ago'] = (target_kickoff - hist['kickoff_at']).dt.total_seconds() / 86400.0
    hist['weight']   = hist['days_ago'].apply(weight_fn)

    result = {}
    for m in METRICS:
        result[m] = weighted_avg(hist[m].tolist(), hist['weight'].tolist())
    return result, len(hist)

# ─────────────────────────────────────────
# 7. BUILD FEATURE DATASET
# ─────────────────────────────────────────

def build_feature_dataset(df, team_rows):
    """
    For every target match, compute weighted features for home and away team
    under all decay configurations. Returns flat rows for correlation analysis.
    """
    # index team_rows by team_id for fast lookup
    team_rows_indexed = {tid: grp for tid, grp in team_rows.groupby('team_id')}

    records = []
    total = len(df)
    for i, (_, match) in enumerate(df.iterrows()):
        if (i + 1) % 200 == 0:
            print(f"  Processing match {i+1}/{total}...", flush=True)

        mid       = match['match_id']
        kickoff   = match['kickoff_at']
        home_id   = match['home_team_id']
        away_id   = match['away_team_id']

        # target outcomes
        g_diff_target = match['home_score_ft'] - match['away_score_ft']
        sh_diff_target = (match['home_shots'] - match['away_shots']
                          if pd.notna(match['home_shots']) and pd.notna(match['away_shots'])
                          else np.nan)
        st_diff_target = (match['home_shots_on_target'] - match['away_shots_on_target']
                          if pd.notna(match['home_shots_on_target']) and pd.notna(match['away_shots_on_target'])
                          else np.nan)

        base = {
            'match_id':        mid,
            'league':          match['league'],
            'season':          match['season'],
            'competition_id':  match['competition_id'],
            'season_id':       match['season_id'],
            'kickoff_at':      kickoff,
            # target (home perspective)
            'target_goal_diff': float(g_diff_target),
            'target_shot_diff': float(sh_diff_target) if not math.isnan(float(sh_diff_target if sh_diff_target is not None else float('nan'))) else np.nan,
            'target_sot_diff':  float(st_diff_target) if not math.isnan(float(st_diff_target if st_diff_target is not None else float('nan'))) else np.nan,
        }

        home_team_rows = team_rows_indexed.get(home_id, pd.DataFrame())
        away_team_rows = team_rows_indexed.get(away_id, pd.DataFrame())

        for cfg_name, weight_fn in DECAY_CONFIGS.items():
            h_feats, h_n = compute_weighted_features(
                home_team_rows if not home_team_rows.empty else team_rows.iloc[:0],
                kickoff, home_id, weight_fn, max_matches=10
            )
            a_feats, a_n = compute_weighted_features(
                away_team_rows if not away_team_rows.empty else team_rows.iloc[:0],
                kickoff, away_id, weight_fn, max_matches=10
            )
            for m in METRICS:
                base[f'home_{cfg_name}_{m}'] = h_feats[m]
                base[f'away_{cfg_name}_{m}'] = a_feats[m]
                # differential: home - away (same direction as target_goal_diff etc.)
                if not math.isnan(h_feats[m]) and not math.isnan(a_feats[m]):
                    base[f'diff_{cfg_name}_{m}'] = h_feats[m] - a_feats[m]
                else:
                    base[f'diff_{cfg_name}_{m}'] = np.nan
            base[f'{cfg_name}_home_n'] = h_n
            base[f'{cfg_name}_away_n'] = a_n

        records.append(base)

    return pd.DataFrame(records)

# ─────────────────────────────────────────
# 8. CORRELATION ANALYSIS
# ─────────────────────────────────────────

def pearson_r(x, y):
    mask = ~(np.isnan(x) | np.isnan(y))
    if mask.sum() < 10:
        return np.nan, np.nan, 0
    r, p = stats.pearsonr(x[mask], y[mask])
    return r, p, mask.sum()

def correlation_table(feat_df, target_col, metric_family):
    """
    For a given target column and metric family (goal_diff, shot_diff, sot_diff),
    compute Pearson r across all decay configs.
    Returns a summary dict.
    """
    results = {}
    for cfg in DECAY_CONFIGS:
        col = f'diff_{cfg}_{metric_family}'
        if col not in feat_df.columns:
            continue
        r, p, n = pearson_r(feat_df[col].values, feat_df[target_col].values)
        results[cfg] = {'r': r, 'p': p, 'n': n}
    return results

# ─────────────────────────────────────────
# 9. SMALL SAMPLE ANALYSIS
# ─────────────────────────────────────────

def small_sample_analysis(feat_df):
    """Analyse how correlation degrades with fewer historical matches."""
    print("\n" + "="*60)
    print("9. SMALL SAMPLE ANALYSIS — goal_diff, hl_14")
    print("="*60)
    for min_n in [1, 2, 3, 5, 10]:
        mask = (feat_df['no_decay_home_n'] >= min_n) & (feat_df['no_decay_away_n'] >= min_n)
        sub = feat_df[mask]
        r, p, n = pearson_r(
            sub['diff_hl_14_goal_diff'].values,
            sub['target_goal_diff'].values
        )
        r_nd, _, _ = pearson_r(
            sub['diff_no_decay_goal_diff'].values,
            sub['target_goal_diff'].values
        )
        print(f"  min_n >= {min_n:2d} | N={n:5d} | hl14 r={r:.4f} | no_decay r={r_nd:.4f}")

# ─────────────────────────────────────────
# 10. REDUNDANCY ANALYSIS
# ─────────────────────────────────────────

def redundancy_analysis(feat_df):
    """Pairwise correlations between: last5/last10/weighted (raw+adj) for goal_diff."""
    print("\n" + "="*60)
    print("10. REDUNDANCY — inter-feature correlation (goal_diff)")
    print("="*60)

    # need to compute last5 and last10 no-decay separately
    # We already have no_decay (last10). For last5 we need a separate pass.
    # Instead, show redundancy between raw vs adj for goal_diff across configs
    configs_to_compare = ['no_decay', 'hl_7', 'hl_14', 'hl_21', 'hl_28', 'hl_42']
    raw_cols = [f'diff_{c}_goal_diff' for c in configs_to_compare]
    adj_cols = [f'diff_{c}_adj_goal_diff' for c in configs_to_compare]
    labels   = configs_to_compare

    all_cols = raw_cols + adj_cols
    all_labels = [f'raw_{l}' for l in labels] + [f'adj_{l}' for l in labels]

    # Compute correlation matrix between raw no_decay and adj no_decay across configs
    print("\n  RAW vs ADJ — same decay config (all matches):")
    for cfg in configs_to_compare:
        rc = f'diff_{cfg}_goal_diff'
        ac = f'diff_{cfg}_adj_goal_diff'
        if rc in feat_df.columns and ac in feat_df.columns:
            r, _, n = pearson_r(feat_df[rc].values, feat_df[ac].values)
            print(f"    {cfg:12s}: r(raw,adj) = {r:.4f}  (n={n})")

    print("\n  Consecutive decay configs (raw goal_diff), r:")
    pairs = list(zip(configs_to_compare[:-1], configs_to_compare[1:]))
    for a, b in pairs:
        ca = f'diff_{a}_goal_diff'
        cb = f'diff_{b}_goal_diff'
        r, _, n = pearson_r(feat_df[ca].values, feat_df[cb].values)
        print(f"    {a:12s} vs {b:12s}: r = {r:.4f}")

# ─────────────────────────────────────────
# 11. MAIN
# ─────────────────────────────────────────

def fmt_r(r):
    return f"{r:.4f}" if not math.isnan(r) else "  N/A "

def print_correlation_block(label, corr_dict):
    print(f"\n  {label}:")
    print(f"    {'config':12s} | {'r':>7} | {'n':>6}")
    print(f"    {'-'*12}-+-{'-'*7}-+-{'-'*6}")
    for cfg, vals in corr_dict.items():
        print(f"    {cfg:12s} | {fmt_r(vals['r']):>7} | {vals['n']:>6}")

def main():
    print("=" * 60)
    print("E12 TIME DECAY ANALYSIS — Robetting")
    print("=" * 60)

    # ── Load ──
    print("\n[1/5] Loading data from DB...")
    df = load_data()
    print(f"  Loaded {len(df)} finished matches.")

    # ── Elo ──
    print("[2/5] Building Elo timeline (strictly pre-match)...")
    elo_before, league_mean = build_elo_timeline(df)

    # ── Team rows ──
    print("[3/5] Building team-perspective rows...")
    team_rows = build_team_rows(df, elo_before, league_mean)
    print(f"  Built {len(team_rows)} team-match rows.")

    # ── Features ──
    print("[4/5] Computing weighted features for all target matches...")
    feat_df = build_feature_dataset(df, team_rows)
    print(f"  Feature dataset: {len(feat_df)} rows.")

    # ── Save intermediate ──
    out_path = r"C:\xampp\htdocs\robetting\tools\analysis\e12_features.csv"
    feat_df.to_csv(out_path, index=False)
    print(f"  Saved features to {out_path}")

    # ── Analysis ──
    print("\n[5/5] Running analyses...")

    # ────────────────────────────────────────────────────────────────
    # A. GLOBAL CORRELATION TABLE
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("A. GLOBAL CORRELATION — diff_weighted vs target (all leagues, all seasons)")
    print("="*60)

    for target_col, metric_family, raw_metric, adj_metric in [
        ('target_goal_diff', 'goal_diff',  'goal_diff',  'adj_goal_diff'),
        ('target_shot_diff', 'shot_diff',  'shot_diff',  'adj_shot_diff'),
        ('target_sot_diff',  'sot_diff',   'sot_diff',   'adj_sot_diff'),
    ]:
        raw_corr = correlation_table(feat_df, target_col, raw_metric)
        adj_corr = correlation_table(feat_df, target_col, adj_metric)
        print(f"\n  TARGET: {target_col}")
        print_correlation_block("RAW", raw_corr)
        print_correlation_block("ADJ (E10)", adj_corr)

    # ────────────────────────────────────────────────────────────────
    # B. HALF-LIFE COMPARISON TABLE (compact)
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("B. HALF-LIFE TABLE — r(diff_cfg_metric, target) — ALL MATCHES")
    print("="*60)
    configs_order = ['no_decay', 'hl_7', 'hl_14', 'hl_21', 'hl_28', 'hl_42']
    targets = [
        ('target_goal_diff', 'goal_diff',     'adj_goal_diff'),
        ('target_shot_diff', 'shot_diff',     'adj_shot_diff'),
        ('target_sot_diff',  'sot_diff',      'adj_sot_diff'),
    ]
    # header
    header = f"  {'config':12s}"
    for tname, raw_m, adj_m in targets:
        short = tname.replace('target_','')[:8]
        header += f" | {short+'_raw':>10} | {short+'_adj':>10}"
    print(header)
    print("  " + "-"*80)
    for cfg in configs_order:
        row_str = f"  {cfg:12s}"
        for tname, raw_m, adj_m in targets:
            rc = f'diff_{cfg}_{raw_m}'
            ac = f'diff_{cfg}_{adj_m}'
            r_raw, _, n_raw = pearson_r(feat_df[rc].values, feat_df[tname].values)
            r_adj, _, _     = pearson_r(feat_df[ac].values, feat_df[tname].values)
            row_str += f" | {fmt_r(r_raw):>10} | {fmt_r(r_adj):>10}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # C. BY LEAGUE
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("C. STABILITY BY LEAGUE — r(diff_cfg_goal_diff, target_goal_diff) RAW")
    print("="*60)
    leagues = feat_df['league'].unique()
    print(f"  {'league':20s}" + "".join(f" | {c:12s}" for c in configs_order))
    print("  " + "-"*90)
    for league in sorted(leagues):
        sub = feat_df[feat_df['league'] == league]
        row_str = f"  {league:20s}"
        for cfg in configs_order:
            rc = f'diff_{cfg}_goal_diff'
            r, _, n = pearson_r(sub[rc].values, sub['target_goal_diff'].values)
            row_str += f" | {fmt_r(r):>12}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # D. BY SEASON
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("D. STABILITY BY SEASON — r(diff_cfg_goal_diff, target_goal_diff) RAW")
    print("="*60)
    seasons = sorted(feat_df['season'].unique())
    print(f"  {'season':10s}" + "".join(f" | {c:12s}" for c in configs_order))
    print("  " + "-"*90)
    for season in seasons:
        sub = feat_df[feat_df['season'] == season]
        row_str = f"  {season:10s}"
        for cfg in configs_order:
            rc = f'diff_{cfg}_goal_diff'
            r, _, n = pearson_r(sub[rc].values, sub['target_goal_diff'].values)
            row_str += f" | {fmt_r(r):>12}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # E. BY LEAGUE + ADJ
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("E. STABILITY BY LEAGUE — r(diff_cfg_adj_goal_diff, target_goal_diff) ADJ")
    print("="*60)
    print(f"  {'league':20s}" + "".join(f" | {c:12s}" for c in configs_order))
    print("  " + "-"*90)
    for league in sorted(leagues):
        sub = feat_df[feat_df['league'] == league]
        row_str = f"  {league:20s}"
        for cfg in configs_order:
            ac = f'diff_{cfg}_adj_goal_diff'
            r, _, n = pearson_r(sub[ac].values, sub['target_goal_diff'].values)
            row_str += f" | {fmt_r(r):>12}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # F. SHOT DIFF — by league
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("F. BY LEAGUE — r(diff_cfg_shot_diff, target_shot_diff) RAW")
    print("="*60)
    print(f"  {'league':20s}" + "".join(f" | {c:12s}" for c in configs_order))
    print("  " + "-"*90)
    for league in sorted(leagues):
        sub = feat_df[feat_df['league'] == league]
        row_str = f"  {league:20s}"
        for cfg in configs_order:
            rc = f'diff_{cfg}_shot_diff'
            r, _, n = pearson_r(sub[rc].values, sub['target_shot_diff'].values)
            row_str += f" | {fmt_r(r):>12}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # G. DECAY WEIGHT ILLUSTRATION
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("G. HALF-LIFE WEIGHT TABLE — what each half-life means")
    print("="*60)
    day_points = [0, 3, 7, 10, 14, 21, 28, 42, 60, 90]
    print(f"  {'days_ago':>8}" + "".join(f" | hl_{hl:>2}" for hl in HALF_LIVES))
    print("  " + "-"*70)
    for d in day_points:
        row_str = f"  {d:>8}"
        for hl in HALF_LIVES:
            w = 0.5 ** (d / hl)
            row_str += f" | {w:6.4f}"
        print(row_str)

    # ────────────────────────────────────────────────────────────────
    # H. SMALL SAMPLE
    # ────────────────────────────────────────────────────────────────
    small_sample_analysis(feat_df)

    # ────────────────────────────────────────────────────────────────
    # I. REDUNDANCY
    # ────────────────────────────────────────────────────────────────
    redundancy_analysis(feat_df)

    # ────────────────────────────────────────────────────────────────
    # J. VENUE SPLIT — home-only vs away-only vs combined
    # ────────────────────────────────────────────────────────────────
    print("\n" + "="*60)
    print("J. VENUE SPLIT — r home-only vs away-only weighted goal_diff")
    print("   (using hl_21 as representative)")
    print("="*60)

    # Build last5/last10 venue-specific features for home teams only
    # Proxy: look at team_rows is_home=True for home team, is_home=False for away
    team_rows_home = team_rows[team_rows['is_home'] == True]
    team_rows_away = team_rows[team_rows['is_home'] == False]

    home_venue_feats = []
    away_venue_feats = []
    wfn = weight_exp(21)

    total = len(df)
    print("  Computing venue-specific features...")
    for i, (_, match) in enumerate(df.iterrows()):
        kickoff = match['kickoff_at']
        home_id = match['home_team_id']
        away_id = match['away_team_id']

        # home team — home venue only
        hh = team_rows_home[team_rows_home['team_id'] == home_id]
        hh_feats, hh_n = compute_weighted_features(hh, kickoff, home_id, wfn, max_matches=5)
        # away team — away venue only
        aa = team_rows_away[team_rows_away['team_id'] == away_id]
        aa_feats, aa_n = compute_weighted_features(aa, kickoff, away_id, wfn, max_matches=5)

        home_venue_feats.append(hh_feats.get('goal_diff', np.nan))
        away_venue_feats.append(aa_feats.get('goal_diff', np.nan))

    feat_df['home_venue_goal_diff_hl21'] = home_venue_feats
    feat_df['away_venue_goal_diff_hl21'] = away_venue_feats
    feat_df['diff_venue_goal_diff_hl21'] = feat_df['home_venue_goal_diff_hl21'] - feat_df['away_venue_goal_diff_hl21']

    r_venue, _, n_venue = pearson_r(feat_df['diff_venue_goal_diff_hl21'].values, feat_df['target_goal_diff'].values)
    r_all,   _, n_all   = pearson_r(feat_df['diff_hl_21_goal_diff'].values,      feat_df['target_goal_diff'].values)
    print(f"  Venue-split last5 hl_21:   r = {fmt_r(r_venue)} (n={n_venue})")
    print(f"  All-venue  last10 hl_21:   r = {fmt_r(r_all)} (n={n_all})")

    # by league
    print("\n  By league (venue-split vs all-venue):")
    for league in sorted(leagues):
        sub = feat_df[feat_df['league'] == league]
        rv, _, nv = pearson_r(sub['diff_venue_goal_diff_hl21'].values, sub['target_goal_diff'].values)
        ra, _, na = pearson_r(sub['diff_hl_21_goal_diff'].values, sub['target_goal_diff'].values)
        print(f"    {league:20s}: venue={fmt_r(rv)} (n={nv})  all={fmt_r(ra)} (n={na})")

    print("\n" + "="*60)
    print("ANALYSIS COMPLETE")
    print("="*60)

if __name__ == '__main__':
    main()
