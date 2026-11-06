"""
Step 86 — final validation of the first CORNER model on the untouched 2026/27 sample.

Frozen choices (Steps 83-85): negative binomial with the 21 corner features, Gaussian copula for the total-corner
markets, alpha=1.0, preprocessing and copula parameter learned ONLY on 2024/25 + 2025/26. Nothing is tuned on 2026/27.
It only wires the existing functions (corner_experiment.run for the marginals / baseline, corner_joint.run for the
independent vs copula Over/Under 8.5 / 9.5 / 10.5) to the split train = 2024 + 2025, test = 2026.

Inputs: the Step 83 matches TSV and competitions TSV (read-only SQL exports).
Usage:
  py -3 tools/scripts/corner_final_validation.py --matches-tsv M.tsv --competitions-tsv C.tsv --out R.json
"""
import argparse
import csv
import json
import time

import corner_experiment as ce
import corner_joint as cj

TRAIN_YEARS = (2024, 2025)
TEST_YEARS = (2026,)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--matches-tsv', required=True)
    ap.add_argument('--competitions-tsv', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    t0 = time.perf_counter()
    with open(a.competitions_tsv, newline='', encoding='utf-8-sig') as f:
        core_ids = {int(r['id']) for r in csv.DictReader(f, delimiter='\t') if r['slug'] in ce.CORE_SLUGS}
    rows = ce.build_dataset(ce.load_matches(a.matches_tsv), core_ids, years=TRAIN_YEARS + TEST_YEARS)
    res = {'marginals': ce.run(rows, TRAIN_YEARS, TEST_YEARS), 'joint': cj.run(rows, TRAIN_YEARS, TEST_YEARS),
           'seconds': round(time.perf_counter() - t0, 1)}
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, indent=1)
    print(json.dumps({'n_train': res['marginals']['n_train'], 'n_test': res['marginals']['n_test'], 'seconds': res['seconds']}))


if __name__ == '__main__':
    main()
