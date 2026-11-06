"""
Step 85 — does shot volume improve the Step 83 negative-binomial CORNER model?

Two NB variants, same rows, same fixed split (train 2024/25, test 2025/26), same preprocessing learned on TRAIN only,
same hyper-parameters (alpha=1.0, no search):
  A = Step 83 features (21), unchanged;
  B = A + total shots for / against over the last 5 and 10 matches of each team, with explicit counts (12 more).
Only the marginals are compared (no copula, no Over/Under). One run.

Input TSV = the Step 83 export plus the two shot columns:
  mysql -B -e "SELECT m.id, m.kickoff_at, m.competition_id, m.season_id, s.year_start, m.home_team_id, m.away_team_id,
               ms.home_corners, ms.away_corners, ms.home_shots, ms.away_shots FROM matches m
               JOIN seasons s ON s.id=m.season_id LEFT JOIN match_statistics ms ON ms.match_id=m.id
               WHERE m.status='finished' AND m.home_score_ft IS NOT NULL AND m.away_score_ft IS NOT NULL"

Usage:
  py -3 tools/scripts/corner_shots_compare.py --matches-tsv M.tsv --competitions-tsv C.tsv --out R.json [--step83-json corner_result.json]
"""
import argparse
import csv
import json
import time

import numpy as np

import corner_experiment as ce


def per_match_logloss(y, mu, phi):
    return -ce.logpmf(y, mu, phi)


def paired_ci(diff, resamples=2000, seed=85):
    rng = np.random.default_rng(seed)
    n = len(diff)
    m = np.array([diff[rng.integers(0, n, n)].mean() for _ in range(resamples)])
    return float(np.percentile(m, 2.5)), float(np.percentile(m, 97.5))


def compare(rows):
    fa, fb = ce.fit_marginals(rows, use_shots=False), ce.fit_marginals(rows, use_shots=True)
    tr, te = fa['tr'], fa['te']
    assert (tr == fb['tr']).all() and (te == fb['te']).all()
    out = {'n_train': int(tr.sum()), 'n_test': int(te.sum()), 'variants': {}, 'paired': {}, 'residual_corr_home_away': {}}
    for name, fm in (('A_step83', fa), ('B_plus_shots', fb)):
        out['variants'][name] = {}
        for side in ('home', 'away'):
            y, mu, phi = fm['Y'][side], fm['mus'][side]['nb'], fm['phis'][side]['nb']
            out['variants'][name][side] = {'dispersion': phi, 'test': ce.evaluate(y[te], mu[te], phi), 'train': ce.evaluate(y[tr], mu[tr], phi)}
        rh = ce.pearson_resid(fm['Y']['home'], fm['mus']['home']['nb'], fm['phis']['home']['nb'])
        ra = ce.pearson_resid(fm['Y']['away'], fm['mus']['away']['nb'], fm['phis']['away']['nb'])
        out['residual_corr_home_away'][name] = {'train': ce.corr(rh[tr], ra[tr]), 'test': ce.corr(rh[te], ra[te])}
    for side in ('home', 'away'):
        y = fa['Y'][side][te]
        la = per_match_logloss(y, fa['mus'][side]['nb'][te], fa['phis'][side]['nb'])
        lb = per_match_logloss(y, fb['mus'][side]['nb'][te], fb['phis'][side]['nb'])
        d = lb - la
        out['paired'][side] = {'delta_logloss_B_minus_A': float(d.mean()), 'ci95': paired_ci(d), 'B_better_share': float((d < 0).mean()),
                               'delta_mae': float(np.abs(y - fb['mus'][side]['nb'][te]).mean() - np.abs(y - fa['mus'][side]['nb'][te]).mean())}
    Xs = np.array([r['xs'] for r in rows])
    out['shot_feature_coverage'] = {
        'missing_share_train': {k: float(np.isnan(Xs[tr, j]).mean()) for j, k in enumerate(ce.SHOT_FEATURES) if 'for10' in k or 'for5' in k},
        'missing_share_test': {k: float(np.isnan(Xs[te, j]).mean()) for j, k in enumerate(ce.SHOT_FEATURES) if 'for10' in k or 'for5' in k},
        'mean_window_count_train': {k: float(np.nanmean(Xs[tr, j])) for j, k in enumerate(ce.SHOT_FEATURES) if k.endswith('n10')}}
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--matches-tsv', required=True)
    ap.add_argument('--competitions-tsv', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--step83-json', default=None)
    a = ap.parse_args()
    t0 = time.perf_counter()
    with open(a.competitions_tsv, newline='', encoding='utf-8-sig') as f:
        core_ids = {int(r['id']) for r in csv.DictReader(f, delimiter='\t') if r['slug'] in ce.CORE_SLUGS}
    res = compare(ce.build_dataset(ce.load_matches(a.matches_tsv), core_ids))
    if a.step83_json:
        ref = json.load(open(a.step83_json))
        res['A_reproduces_step83'] = all(abs(res['variants']['A_step83'][s]['test']['logloss'] - ref['models'][s]['nb']['test']['logloss']) < 1e-9
                                         for s in ('home', 'away'))
    res['seconds'] = round(time.perf_counter() - t0, 1)
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, indent=1)
    print(json.dumps({k: res[k] for k in ('n_train', 'n_test', 'seconds') if k in res}))


if __name__ == '__main__':
    main()
