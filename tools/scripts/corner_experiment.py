"""
Step 83 — first experimental CORNER model (home / away corners), offline only.

Point-in-time dataset built from two read-only exports (no Laravel, no API):
  matches TSV : mysql -B -e "SELECT m.id, m.kickoff_at, m.competition_id, m.season_id, s.year_start,
                  m.home_team_id, m.away_team_id, ms.home_corners, ms.away_corners FROM matches m
                  JOIN seasons s ON s.id=m.season_id LEFT JOIN match_statistics ms ON ms.match_id=m.id
                  WHERE m.status='finished' AND m.home_score_ft IS NOT NULL AND m.away_score_ft IS NOT NULL"
  competitions TSV : mysql -B -e "SELECT id, slug FROM competitions"

Features (per target match, strictly before its kickoff — the same rule as the PHP calculators):
  - corners for / against of each team over its last 5 and 10 finished matches in ANY competition
    (RECENT_ALL-style window), averaged over the matches of the window WITH corner data;
  - venue form: last 10 home matches of the home team / last 10 away matches of the away team;
  - competition-season mean of home / away corners before the kickoff;
  - explicit counts: matches with data in each window (a count of 0 is a real 0, an average with no data is NaN).
Missing averages stay NaN (never 0); short windows are kept with their counts; the only imputation is the
TRAIN median, exactly like the Candidate47 preprocessing. Corners of a match without statistics are missing
(the data audit found no real zero-corner matches), never 0.

Models, all estimated on TRAIN (2024/25) only, one fixed split, no hyper-parameter search:
  baseline = per competition and side historical mean (Poisson), plus the same mean with an NB dispersion
  Poisson   = PoissonRegressor(alpha=1.0), NB2 = negative binomial, log link, same L2 penalty, one global dispersion.

Usage:
  py -3 tools/scripts/corner_experiment.py --matches-tsv M.tsv --competitions-tsv C.tsv --out R.json
"""
import argparse
import bisect
import csv
import json
import math
import time
from collections import defaultdict

import numpy as np
from scipy.optimize import minimize
from scipy.special import gammaln
from scipy.stats import nbinom, poisson
from sklearn.linear_model import PoissonRegressor

CORE_SLUGS = ('serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1')
TRAIN_YEAR, TEST_YEAR = 2024, 2025
WINDOWS = (5, 10)
VENUE_WINDOW = 10
ALPHA = 1.0
BINS = ((0, 3), (4, 5), (6, 7), (8, 9), (10, 99))


def _int(v):
    return None if v in ('', 'NULL', None) else int(v)


def load_matches(path):
    out = []
    with open(path, newline='', encoding='utf-8-sig') as f:
        for r in csv.DictReader(f, delimiter='\t'):
            out.append({'id': int(r['id']), 'ko': r['kickoff_at'], 'comp': int(r['competition_id']), 'year': int(r['year_start']),
                        'season': int(r['season_id']), 'home': int(r['home_team_id']), 'away': int(r['away_team_id']),
                        'hc': _int(r['home_corners']), 'ac': _int(r['away_corners']),
                        'hs': _int(r.get('home_shots')), 'as': _int(r.get('away_shots'))})   # shots columns are optional (Step 85)
    return sorted(out, key=lambda m: (m['ko'], m['id']))


def build_indexes(matches):
    """team -> chronological entries; (competition, season) -> chronological corner sums."""
    team = defaultdict(list)
    league = defaultdict(list)
    for m in matches:
        has = m['hc'] is not None and m['ac'] is not None
        has_s = m.get('hs') is not None and m.get('as') is not None
        team[m['home']].append({'ko': m['ko'], 'venue': 'H', 'cf': m['hc'] if has else None, 'ca': m['ac'] if has else None,
                                'sf': m['hs'] if has_s else None, 'sa': m['as'] if has_s else None})
        team[m['away']].append({'ko': m['ko'], 'venue': 'A', 'cf': m['ac'] if has else None, 'ca': m['hc'] if has else None,
                                'sf': m['as'] if has_s else None, 'sa': m['hs'] if has_s else None})
        if has:
            league[(m['comp'], m['season'])].append((m['ko'], m['hc'], m['ac']))
    team_ko = {t: [e['ko'] for e in es] for t, es in team.items()}
    league_ko, league_cum = {}, {}
    for k, rows in league.items():
        league_ko[k] = [r[0] for r in rows]
        league_cum[k] = (np.concatenate([[0], np.cumsum([r[1] for r in rows])]), np.concatenate([[0], np.cumsum([r[2] for r in rows])]))
    return team, team_ko, league_ko, league_cum


def window_stats(entries, kos, ko, n, venue=None, fkey='cf', akey='ca'):
    """Last n matches strictly before ko (of the given venue if set): (avg_for, avg_against, n_with_data).
    Averages are None when no match of the window has data for the metric (corners by default, 'sf'/'sa' = shots)."""
    hi = bisect.bisect_left(kos, ko)
    prior = [e for e in entries[:hi] if venue is None or e['venue'] == venue]
    window = prior[-n:]
    data = [e for e in window if e[fkey] is not None]
    if not data:
        return None, None, 0
    return sum(e[fkey] for e in data) / len(data), sum(e[akey] for e in data) / len(data), len(data)


def corner_features(m, idx):
    team, team_ko, league_ko, league_cum = idx
    f = {}
    for side, tid, venue in (('h', m['home'], 'H'), ('a', m['away'], 'A')):
        es, kos = team.get(tid, []), team_ko.get(tid, [])
        for n in WINDOWS:
            f[f'{side}_for{n}'], f[f'{side}_ag{n}'], f[f'{side}_n{n}'] = window_stats(es, kos, m['ko'], n)
        f[f'{side}_vfor'], f[f'{side}_vag'], f[f'{side}_vn'] = window_stats(es, kos, m['ko'], VENUE_WINDOW, venue)
    key = (m['comp'], m['season'])
    kos = league_ko.get(key, [])
    i = bisect.bisect_left(kos, m['ko'])
    f['lg_n'] = i
    f['lg_home'] = float(league_cum[key][0][i]) / i if i else None
    f['lg_away'] = float(league_cum[key][1][i]) / i if i else None
    return f


FEATURES = ([f'{s}_{k}' for s in ('h', 'a') for k in ('for5', 'ag5', 'n5', 'for10', 'ag10', 'n10', 'vfor', 'vag', 'vn')]
            + ['lg_home', 'lg_away', 'lg_n'])


SHOT_FEATURES = [f'{s}_{k}' for s in ('h', 'a') for k in ('sfor5', 'sag5', 'sn5', 'sfor10', 'sag10', 'sn10')]


def shot_features(m, idx):
    """Step 85: total shots for / against over the last 5 and 10 matches (any competition), same rules as the corner windows:
    averages over the window's matches WITH shot data, None when there is none, explicit count of those matches."""
    team, team_ko = idx[0], idx[1]
    f = {}
    for side, tid in (('h', m['home']), ('a', m['away'])):
        es, kos = team.get(tid, []), team_ko.get(tid, [])
        for n in WINDOWS:
            f[f'{side}_sfor{n}'], f[f'{side}_sag{n}'], f[f'{side}_sn{n}'] = window_stats(es, kos, m['ko'], n, fkey='sf', akey='sa')
    return f


def build_dataset(matches, core_ids, years=(TRAIN_YEAR, TEST_YEAR)):
    idx = build_indexes(matches)
    rows = []
    for m in matches:
        if m['comp'] not in core_ids or m['year'] not in years or m['hc'] is None or m['ac'] is None:
            continue
        f = corner_features(m, idx)
        fs = shot_features(m, idx)
        rows.append({'id': m['id'], 'comp': m['comp'], 'year': m['year'], 'ko': m['ko'], 'yh': m['hc'], 'ya': m['ac'],
                     'x': [np.nan if f[k] is None else float(f[k]) for k in FEATURES],
                     'xs': [np.nan if fs[k] is None else float(fs[k]) for k in SHOT_FEATURES]})
    return rows


# ── models ────────────────────────────────────────────────────────────────────────────────────────────────

def nb_logpmf(y, mu, phi):
    r = 1.0 / phi
    return gammaln(y + r) - gammaln(r) - gammaln(y + 1) + r * np.log(r / (r + mu)) + y * np.log(mu / (r + mu))


def fit_nb(X, y, alpha=ALPHA):
    """NB2, log link, mean NLL + 0.5*alpha*||w||^2 (same penalty as sklearn PoissonRegressor), global dispersion."""
    n, d = X.shape
    mu0 = max(y.mean(), 1e-6)

    def obj(p):
        w, b, lphi = p[:d], p[d], p[d + 1]
        mu = np.exp(np.clip(X @ w + b, -20, 20))
        return -nb_logpmf(y, mu, math.exp(lphi)).mean() + 0.5 * alpha * float(w @ w)

    p0 = np.concatenate([np.zeros(d), [math.log(mu0)], [math.log(0.1)]])
    res = minimize(obj, p0, method='L-BFGS-B', bounds=[(None, None)] * (d + 1) + [(math.log(1e-4), math.log(10))])
    return res.x[:d], res.x[d], math.exp(res.x[d + 1])


def fit_nb_dispersion(y, mu):
    res = minimize(lambda lp: -nb_logpmf(y, mu, math.exp(lp[0])).mean(), [math.log(0.1)], method='L-BFGS-B',
                   bounds=[(math.log(1e-4), math.log(10))])
    return math.exp(res.x[0])


def logpmf(y, mu, phi):
    return poisson.logpmf(y, mu) if phi is None else nb_logpmf(y, mu, phi)


def bin_probs(mu, phi):
    cols = []
    for lo, hi in BINS:
        if phi is None:
            cols.append(poisson.cdf(hi, mu) - (poisson.cdf(lo - 1, mu) if lo > 0 else 0.0))
        else:
            r = 1.0 / phi
            p = r / (r + mu)
            cols.append(nbinom.cdf(hi, r, p) - (nbinom.cdf(lo - 1, r, p) if lo > 0 else 0.0))
    return np.column_stack(cols)


def evaluate(y, mu, phi):
    """Per side metrics. phi=None -> Poisson."""
    var = mu if phi is None else mu + phi * mu ** 2
    obs = np.column_stack([((y >= lo) & (y <= hi)).astype(float) for lo, hi in BINS]).mean(axis=0)
    pred = bin_probs(mu, phi).mean(axis=0)
    return {'logloss': float(-logpmf(y, mu, phi).mean()), 'mae': float(np.abs(y - mu).mean()),
            'mean_pred': float(mu.mean()), 'mean_obs': float(y.mean()),
            'tv_bins': float(0.5 * np.abs(pred - obs).sum()), 'pearson_msq': float((((y - mu) ** 2) / var).mean())}


def pearson_resid(y, mu, phi):
    return (y - mu) / np.sqrt(mu if phi is None else mu + phi * mu ** 2)


def corr(a, b):
    return float(np.corrcoef(a, b)[0, 1])


def fit_marginals(rows, use_shots=False, train_years=(TRAIN_YEAR,), test_years=(TEST_YEAR,)):
    """Fit baseline / Poisson / NB marginals on TRAIN only and predict every row (shared by run() and the joint model).
    use_shots=True appends the Step 85 shot-volume features (same preprocessing and hyper-parameters).
    train_years / test_years select the fixed chronological split (Step 86 trains on 2024+2025 and tests on 2026)."""
    year = np.array([r['year'] for r in rows])
    tr, te = np.isin(year, train_years), np.isin(year, test_years)
    X = np.array([r['x'] + (r['xs'] if use_shots else []) for r in rows])
    comp = np.array([r['comp'] for r in rows])
    Y = {'home': np.array([r['yh'] for r in rows], float), 'away': np.array([r['ya'] for r in rows], float)}

    med = np.nanmedian(X[tr], axis=0)
    Xi = np.where(np.isnan(X), med, X)
    mean, sd = Xi[tr].mean(axis=0), Xi[tr].std(axis=0)
    sd[sd == 0] = 1.0
    Z = (Xi - mean) / sd

    mus, phis = {}, {}
    nb_params = {}
    for side in ('home', 'away'):
        y = Y[side]
        base = np.zeros(len(rows))
        for c in np.unique(comp):
            sel = comp == c
            base[sel] = y[tr & sel].mean() if (tr & sel).any() else y[tr].mean()
        pois = PoissonRegressor(alpha=ALPHA, max_iter=2000).fit(Z[tr], y[tr])
        w, b, phi = fit_nb(Z[tr], y[tr])
        nb_params[side] = {'coefficients': w, 'intercept': float(b), 'dispersion': float(phi)}
        mus[side] = {'baseline': base, 'poisson': pois.predict(Z), 'nb': np.exp(Z @ w + b)}
        phis[side] = {'baseline': None, 'baseline_nb': fit_nb_dispersion(y[tr], base[tr]), 'poisson': None, 'nb': phi}
        mus[side]['baseline_nb'] = base
    return {'tr': tr, 'te': te, 'X': X, 'Y': Y, 'mus': mus, 'phis': phis,
            'params': {'median': med, 'mean': mean, 'scale': sd, 'nb': nb_params}}


def run(rows, train_years=(TRAIN_YEAR,), test_years=(TEST_YEAR,)):
    fm = fit_marginals(rows, train_years=train_years, test_years=test_years)
    tr, te, X, Y, mus, phis = fm['tr'], fm['te'], fm['X'], fm['Y'], fm['mus'], fm['phis']
    out = {'n_train': int(tr.sum()), 'n_test': int(te.sum()), 'n_features': len(FEATURES),
           'missing_share_train': {k: float(np.isnan(X[tr, j]).mean()) for j, k in enumerate(FEATURES) if np.isnan(X[tr, j]).any()},
           'raw_corr_home_away': {'train': corr(Y['home'][tr], Y['away'][tr]), 'test': corr(Y['home'][te], Y['away'][te])},
           'models': {}}
    for side in ('home', 'away'):
        y = Y[side]
        out['models'][side] = {}
        for name in ('baseline', 'baseline_nb', 'poisson', 'nb'):
            out['models'][side][name] = {'dispersion': phis[side][name],
                                         'test': evaluate(y[te], mus[side][name][te], phis[side][name]),
                                         'train': evaluate(y[tr], mus[side][name][tr], phis[side][name])}
    out['residual_corr_home_away'] = {}
    for name in ('baseline', 'baseline_nb', 'poisson', 'nb'):
        rh = pearson_resid(Y['home'], mus['home'][name], phis['home'][name])
        ra = pearson_resid(Y['away'], mus['away'][name], phis['away'][name])
        out['residual_corr_home_away'][name] = {'train': corr(rh[tr], ra[tr]), 'test': corr(rh[te], ra[te])}
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--matches-tsv', required=True)
    ap.add_argument('--competitions-tsv', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    t0 = time.perf_counter()
    with open(a.competitions_tsv, newline='', encoding='utf-8-sig') as f:
        core_ids = {int(r['id']) for r in csv.DictReader(f, delimiter='\t') if r['slug'] in CORE_SLUGS}
    rows = build_dataset(load_matches(a.matches_tsv), core_ids)
    t_ds = time.perf_counter() - t0
    res = run(rows)
    res['seconds'] = {'dataset': round(t_ds, 1), 'total': round(time.perf_counter() - t0, 1)}
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, indent=1)
    print(json.dumps({k: res[k] for k in ('n_train', 'n_test', 'raw_corr_home_away', 'residual_corr_home_away', 'seconds')}))


if __name__ == '__main__':
    main()
