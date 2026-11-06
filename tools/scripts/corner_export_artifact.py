"""
Step 87 — export the versioned CORNER artifact (+ a golden fixture) from the frozen Step 83/84/86 configuration.

No new fitting choices: negative binomial with the 21 corner features (alpha=1.0), train-only median / mean / scale,
Gaussian copula rho estimated by cj.fit_rho on the TRAINING pairs only. Train = 2024/25 + 2025/26 (as decided in Step 86);
2026/27 is NOT used for any parameter. The golden fixture stores the feature vectors (null = missing) of a few 2026/27
matches and the Python outputs, so the PHP backend can be checked against Python without a database.

Usage:
  py -3 tools/scripts/corner_export_artifact.py --matches-tsv M.tsv --competitions-tsv C.tsv \
      --artifact tools/models/corner_nb_copula_v1.json --golden tests/Fixtures/corner_golden.json
"""
import argparse
import csv
import datetime
import json

import numpy as np

import corner_experiment as ce
import corner_joint as cj

TRAIN_YEARS = (2024, 2025)
TEST_YEARS = (2026,)
MODEL_VERSION = '1.0.0'


def _nn(v):
    """JSON-safe: NaN -> null."""
    return None if isinstance(v, float) and np.isnan(v) else v


def build_artifact(fm, rho, n_train):
    p = fm['params']
    return {
        'model_key': 'corner_nb_copula', 'model_version': MODEL_VERSION, 'trained_years': list(TRAIN_YEARS), 'training_matches': int(n_train),
        'trained_at': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'algorithm': 'NegativeBinomial2 (log link, L2 alpha=1.0 on standardised features), one global dispersion per side',
        'features': ce.FEATURES,
        'feature_definition': {'windows': list(ce.WINDOWS), 'venue_window': ce.VENUE_WINDOW,
                               'note': 'last N finished matches of the team in ANY competition strictly before kickoff; averages over the window matches '
                                       'WITH corner data (null if none); n* = those matches (a real count); venue = last 10 home (home team) / away (away team) '
                                       'matches; lg_* = competition-season mean of home/away corners over earlier matches with both counts, lg_n their number'},
        'imputer': {'strategy': 'median', 'values': [float(v) for v in p['median']]},
        'scaler': {'mean': [float(v) for v in p['mean']], 'scale': [float(v) for v in p['scale']]},
        'home_model': {'intercept': p['nb']['home']['intercept'], 'coefficients': [float(v) for v in p['nb']['home']['coefficients']],
                       'dispersion': p['nb']['home']['dispersion']},
        'away_model': {'intercept': p['nb']['away']['intercept'], 'coefficients': [float(v) for v in p['nb']['away']['coefficients']],
                       'dispersion': p['nb']['away']['dispersion']},
        'copula': {'type': 'gaussian', 'rho': float(rho), 'max_corners_per_side': cj.K,
                   'grid': {'x_lo': cj.X_LO, 'x_hi': cj.X_HI, 'dx': cj.DX}},
        'thresholds': list(cj.THRESHOLDS),
    }


def golden_case(row, i, fm, rho):
    mu_h, mu_a = float(fm['mus']['home']['nb'][i]), float(fm['mus']['away']['nb'][i])
    ph, pa = fm['phis']['home']['nb'], fm['phis']['away']['nb']
    P, mass = cj.joint_pmf(mu_h, ph, mu_a, pa, rho)
    tp = cj.total_pmf(P)
    m, v = cj.moments(tp)
    return {'match_id': int(row['id']), 'features': [_nn(float(x)) for x in row['x']],
            'expected': {'mu_home': mu_h, 'mu_away': mu_a, 'expected_total': mu_h + mu_a, 'over': cj.over_probs(tp),
                         'total_mean': m, 'total_variance': v, 'truncated_mass': 1.0 - mass,
                         'total_pmf_0_24': [float(x) for x in tp[:25]], 'joint_row0_0_9': [float(x) for x in P[0, :10]]}}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--matches-tsv', required=True)
    ap.add_argument('--competitions-tsv', required=True)
    ap.add_argument('--artifact', required=True)
    ap.add_argument('--golden', required=True)
    a = ap.parse_args()
    with open(a.competitions_tsv, newline='', encoding='utf-8-sig') as f:
        core_ids = {int(r['id']) for r in csv.DictReader(f, delimiter='\t') if r['slug'] in ce.CORE_SLUGS}
    rows = ce.build_dataset(ce.load_matches(a.matches_tsv), core_ids, years=TRAIN_YEARS + TEST_YEARS)
    fm = ce.fit_marginals(rows, train_years=TRAIN_YEARS, test_years=TEST_YEARS)
    tr = fm['tr']
    rho = cj.fit_rho(fm['Y']['home'][tr], fm['Y']['away'][tr], fm['mus']['home']['nb'][tr], fm['phis']['home']['nb'],
                     fm['mus']['away']['nb'][tr], fm['phis']['away']['nb'])
    art = build_artifact(fm, rho, tr.sum())
    with open(a.artifact, 'w', encoding='utf-8') as f:
        json.dump(art, f, indent=1)
    te_idx = np.where(fm['te'])[0]
    # first 2026/27 match (league mean still missing), one in the middle, the last one: all outside training
    picks = [te_idx[0], te_idx[len(te_idx) // 2], te_idx[-1]]
    golden = {'artifact_model_version': MODEL_VERSION, 'rho': float(rho), 'cases': [golden_case(rows[i], i, fm, rho) for i in picks]}
    with open(a.golden, 'w', encoding='utf-8') as f:
        json.dump(golden, f, indent=1)
    print(json.dumps({'rho': rho, 'n_train': int(tr.sum()), 'golden_matches': [c['match_id'] for c in golden['cases']],
                      'golden_missing_features': [sum(x is None for x in c['features']) for c in golden['cases']]}))


if __name__ == '__main__':
    main()
