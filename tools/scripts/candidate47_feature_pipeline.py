"""
P27E2/F-A — shared Candidate47 Structural LOG raw-feature pipeline.

Single source of truth for:
  - FEATS39 (from prediction_engine_no_e9_no_e10.json) / FEATS47
  - walk-forward Latent Attack/Defence fit (date-grouped, hard sum-to-zero,
    analytic jacobian) — IDENTICAL methodology to
    generate_candidate44_bp_artifact.py / the original inline code in
    generate_candidate47_structural_log_artifact.py
  - point-in-time TOP25 Structural (via tools/structural/historical_top25.py)
  - raw (pre-MISSING30, pre-impute, pre-scale) 47-feature-per-row construction
    for historical rows, and loading of an already-built combined dataset CSV

Used by:
  - generate_candidate47_structural_log_artifact.py (historical-only default
    path now calls into this module instead of duplicating the logic inline)
  - build_candidate47_learning_dataset.py (P27E2/F-A combined dataset builder)

No methodology change: MISSING30 gating, median imputation and StandardScaler
scaling remain the CALLER's responsibility at training time (same gate, same
threshold as before) — this module only produces raw, ungated 47-feature
values + goal-count labels, one dict per match, identically for any source.
"""
import csv
import json
import math
import sys
import time
from collections import defaultdict
from pathlib import Path

import numpy as np
from scipy.optimize import minimize

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/structural'))
import historical_top25  # noqa: E402

_art39 = json.loads((ROOT / 'tools/models/prediction_engine_no_e9_no_e10.json').read_text())
FEATS39 = _art39['features']
LATENT_NAMES = ['latent_attack_home', 'latent_defence_home', 'latent_attack_away', 'latent_defence_away']
STRUCTURAL_NAMES = ['structural_log_home', 'structural_log_away', 'structural_log_ratio']
FEATS47 = FEATS39 + ['elo_gap_signed_square'] + LATENT_NAMES + STRUCTURAL_NAMES

ELO_H = 'core_elo_home_pre_match_elo'
ELO_A = 'core_elo_away_pre_match_elo'
LAMBDA3 = 0.15
MISSING30_THRESHOLD = 30
MISSING30_FEATURES = sorted({'core_schedule_home_rest_days', 'core_schedule_away_rest_days'})
LATENT_PRIOR_N = 3
MAXITER_WF = 300


def load_historical_rows(csv_path):
    """Valid (labeled, has core Elo), chronologically sorted rows from a dataset_core_v1_*.csv-shaped file."""
    with open(csv_path, newline='', encoding='utf-8') as f:
        all_rows = list(csv.DictReader(f))

    def valid(r):
        return (r.get('label_home_goals', '') != '' and r.get('label_away_goals', '') != '' and r.get(ELO_H, '') != '')

    return sorted([r for r in all_rows if valid(r)], key=lambda r: r['kickoff_at'])


def _expand(free):
    return np.concatenate([free, [-free.sum()]])


def _fit_latent(matches, n_teams, prior_n=LATENT_PRIOR_N, x0=None, maxiter=MAXITER_WF):
    """Identical math to generate_candidate44_bp_artifact.py's fit_latent()."""
    n = len(matches)
    if n == 0:
        return 0.35, 0.20, np.zeros(n_teams), np.zeros(n_teams)
    homes = np.array([m[0] for m in matches], dtype=np.int64)
    aways = np.array([m[1] for m in matches], dtype=np.int64)
    xh = np.array([m[2] for m in matches], dtype=np.float64)
    xa = np.array([m[3] for m in matches], dtype=np.float64)

    def neg_wll(params):
        bh, ba = params[0], params[1]
        att = _expand(params[2:2 + (n_teams - 1)])
        def_ = _expand(params[2 + (n_teams - 1):2 + 2 * (n_teams - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        wll = (xh * log_lh - lh_v + xa * log_la - la_v).sum()
        return -(wll) + prior_n * (att ** 2).sum() + prior_n * (def_ ** 2).sum()

    def analytic_gradient(params):
        bh, ba = params[0], params[1]
        att = _expand(params[2:2 + (n_teams - 1)])
        def_ = _expand(params[2 + (n_teams - 1):2 + 2 * (n_teams - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        rh = xh - lh_v
        ra = xa - la_v
        g_bh = -rh.sum()
        g_ba = -ra.sum()
        g_att_full = -(np.bincount(homes, weights=rh, minlength=n_teams) + np.bincount(aways, weights=ra, minlength=n_teams)) + 2 * prior_n * att
        g_def_full = -(np.bincount(aways, weights=rh, minlength=n_teams) + np.bincount(homes, weights=ra, minlength=n_teams)) + 2 * prior_n * def_
        return np.concatenate([[g_bh, g_ba], g_att_full[:-1] - g_att_full[-1], g_def_full[:-1] - g_def_full[-1]])

    if x0 is None:
        x0v = np.zeros(2 + 2 * (n_teams - 1))
        x0v[0] = 0.35
        x0v[1] = 0.20
    else:
        bh0, ba0, att0, def0 = x0
        x0v = np.concatenate([[bh0, ba0], att0[:-1], def0[:-1]])

    res = minimize(neg_wll, x0v, method='L-BFGS-B', jac=analytic_gradient,
                    options={'maxiter': maxiter, 'ftol': 1e-10, 'gtol': 1e-7})
    bh = float(res.x[0])
    ba = float(res.x[1])
    att = _expand(res.x[2:2 + (n_teams - 1)])
    def_ = _expand(res.x[2 + (n_teams - 1):2 + 2 * (n_teams - 1)])
    return bh, ba, att, def_


def compute_latent_features(rows, verbose=False):
    """
    Walk-forward Latent Attack/Defence, date-grouped, chronological — the
    fit used to produce row i's features only ever saw matches strictly
    before row i's date group (no leakage).

    @return dict row_index -> (attack_home, defence_home, attack_away, defence_away)
    """
    team_ids = sorted(set(r['home_team_id'] for r in rows) | set(r['away_team_id'] for r in rows))
    team_idx = {t: i for i, t in enumerate(team_ids)}
    n_teams = len(team_ids)

    date_groups = defaultdict(list)
    for idx, r in enumerate(rows):
        date_groups[r['kickoff_at'][:10]].append((idx, r))
    sorted_dates = sorted(date_groups.keys())

    prior_matches_list = []
    latent_feats = {}
    current_params = None
    fits_done = 0
    t0 = time.perf_counter()
    for date in sorted_dates:
        group = date_groups[date]
        if len(prior_matches_list) == 0:
            bh, ba, att, def_ = _fit_latent([], n_teams, x0=current_params)
        else:
            m_arr = [(team_idx[m[0]], team_idx[m[1]], m[2], m[3]) for m in prior_matches_list]
            bh, ba, att, def_ = _fit_latent(m_arr, n_teams, x0=current_params)
            fits_done += 1
        current_params = (bh, ba, att, def_)
        for idx, r in group:
            hi, ai = team_idx.get(r['home_team_id']), team_idx.get(r['away_team_id'])
            latent_feats[idx] = (0.0, 0.0, 0.0, 0.0) if hi is None or ai is None else \
                (float(att[hi]), float(def_[hi]), float(att[ai]), float(def_[ai]))
        for idx, r in group:
            prior_matches_list.append((r['home_team_id'], r['away_team_id'],
                                        int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))

    if verbose:
        print(f"Walk-forward fits: {fits_done}  elapsed: {time.perf_counter() - t0:.2f}s")

    return latent_feats


def compute_structural_log_features(rows):
    """@return dict match_id(str) -> (log_home, log_away, log_ratio), or None if unavailable/non-positive."""
    structural_by_match = historical_top25.top25_point_in_time(
        rows, historical_top25.load_valuation_events(), historical_top25.load_team_club_map()
    )

    out = {}
    for mid, vals in structural_by_match.items():
        sh, sa = vals.get('structural_home'), vals.get('structural_away')
        if sh is None or sa is None:
            out[mid] = None
            continue
        sh_v, sa_v = float(sh), float(sa)
        if sh_v <= 0 or sa_v <= 0:
            out[mid] = None
        else:
            out[mid] = (math.log(sh_v), math.log(sa_v), math.log(sh_v / sa_v))

    return out


def _derive_label_1x2(home_goals, away_goals):
    hg, ag = float(home_goals), float(away_goals)
    return 'H' if hg > ag else ('D' if hg == ag else 'A')


def build_raw_feature_rows(rows, verbose=False):
    """
    One dict per historical row: match_id, kickoff_at, season_id,
    label_home_goals, label_away_goals, label_result_1x2 (H/D/A — reused
    verbatim from the historical CSV's own column, or derived from the goal
    counts if absent), and all FEATS47 raw values (None where
    missing/unavailable — NOT MISSING30-gated, NOT imputed, NOT scaled;
    that stays the training-time caller's job, applied uniformly regardless
    of row source).
    """
    latent_by_idx = compute_latent_features(rows, verbose=verbose)
    structural_by_match = compute_structural_log_features(rows)

    out = []
    for i, r in enumerate(rows):
        feat = {}
        for f in FEATS39:
            raw = r.get(f, '')
            feat[f] = None if raw == '' else float(raw)

        elo_h, elo_a = feat.get(ELO_H), feat.get(ELO_A)
        if elo_h is not None and elo_a is not None:
            gap = elo_h - elo_a
            feat['elo_gap_signed_square'] = math.copysign(gap * gap, gap)
        else:
            feat['elo_gap_signed_square'] = None

        lf = latent_by_idx.get(i, (0.0, 0.0, 0.0, 0.0))
        for name, value in zip(LATENT_NAMES, lf):
            feat[name] = value

        sl = structural_by_match.get(r['match_id'])
        if sl is None:
            for name in STRUCTURAL_NAMES:
                feat[name] = None
        else:
            for name, value in zip(STRUCTURAL_NAMES, sl):
                feat[name] = value

        label_1x2 = r.get('label_result_1x2') or _derive_label_1x2(r['label_home_goals'], r['label_away_goals'])

        row_out = {
            'source_kind': 'historical',
            'source_prediction_id': None,
            'match_id': str(r['match_id']),
            'kickoff_at': r['kickoff_at'],
            'season_id': r.get('season_id') or None,
            'label_home_goals': float(r['label_home_goals']),
            'label_away_goals': float(r['label_away_goals']),
            'label_result_1x2': label_1x2,
        }
        for f in FEATS47:
            row_out[f] = feat[f]
        out.append(row_out)

    return out


def load_combined_dataset(csv_path):
    """
    Load a dataset built by build_candidate47_learning_dataset.py: already
    has all FEATS47 raw columns + labels + provenance metadata, chronologically
    sorted. Re-sorts defensively by kickoff_at regardless.

    @return list of dicts, same shape as build_raw_feature_rows() output.
    """
    with open(csv_path, newline='', encoding='utf-8') as f:
        rows = list(csv.DictReader(f))

    out = []
    for r in rows:
        row_out = {
            'source_kind': r.get('source_kind'),
            'source_prediction_id': r.get('source_prediction_id') or None,
            'match_id': r['match_id'],
            'kickoff_at': r['kickoff_at'],
            'season_id': r.get('season_id') or None,
            'label_home_goals': float(r['label_home_goals']),
            'label_away_goals': float(r['label_away_goals']),
            'label_result_1x2': r.get('label_result_1x2'),
        }
        for f in FEATS47:
            raw = r.get(f, '')
            row_out[f] = None if raw in ('', None) else float(raw)
        out.append(row_out)

    return sorted(out, key=lambda r: r['kickoff_at'])
