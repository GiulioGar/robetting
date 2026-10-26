"""
Calibration diagnostic of the current BEST: Candidate47 Team History Recent.

Read-only diagnostic. Same benchmark pipeline as compare_candidate47_recent_modes.py
(delegated verbatim): 47 features, TRAIN = 2024/25 (season_id 28-32), TEST = 2025/26
(season_id 23-27), MISSING30 gating, median imputation + StandardScaler fit on TRAIN
only, PoissonRegressor(alpha=1.0, max_iter=500) x2, Bivariate Poisson (lambda3=0.15).
No retraining beyond that single fit, no tuning, no calibration method, no feature
change. The TEST set is only measured, never used to correct anything.

Dataset: tools/datasets/dataset_core_v1_team_history_2024_2025.csv

Printed (TEST only):
  1. global LogLoss / Brier / Accuracy;
  2. 1X2 calibration tables, separately for HOME, DRAW, AWAY, in 10 probability
     buckets [0,10%) ... [90,100%]: N, mean predicted probability, observed event
     frequency, gap = observed - predicted;
  3. final-pick confidence = max(P_home, P_draw, P_away), buckets <40%, 40-50% ...
     70-80%, >=80%: N, mean confidence, observed accuracy, gap = accuracy - confidence;
  4. ECE (Expected Calibration Error).

ECE definition (standard, equal-width binning):
    ECE = sum_b (N_b / N) * | observed_b - predicted_b |
  where the sum runs over the non-empty buckets b, N is the number of TEST matches,
  N_b the observations in bucket b, predicted_b the mean predicted probability in the
  bucket and observed_b the empirical frequency in the bucket.
    - per outcome (HOME/DRAW/AWAY): each TEST match contributes once per outcome,
      predicted = P(outcome), observed = 1 if the outcome occurred else 0, 10 buckets;
    - max-confidence: predicted = max(P), observed = 1 if the argmax outcome occurred
      (accuracy), using the 6 confidence buckets above.
Buckets with very few observations are shown with their N and must not be read as
strong evidence.

Writes NOTHING. No DB / API access.

Usage:
    py -3 tools/scripts/diagnose_candidate47_calibration.py
    py -3 tools/scripts/diagnose_candidate47_calibration.py --dataset <csv>
"""
import argparse
import sys
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import compare_candidate47_recent_modes as cmp  # noqa: E402

DEFAULT_DATASET = ROOT / 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv'

PROB_EDGES = [i / 10 for i in range(11)]            # 0.0, 0.1, ... 1.0
CONF_EDGES = [0.0, 0.4, 0.5, 0.6, 0.7, 0.8, 1.0 + 1e-12]
OUTCOMES = ('HOME', 'DRAW', 'AWAY')


def prob_label(lo, hi, last):
    return f"{lo * 100:.0f}-{min(hi, 1.0) * 100:.0f}%" + (']' if last else ')')


def conf_label(i):
    if i == 0:
        return '<40%'
    if i == len(CONF_EDGES) - 2:
        return '>=80%'
    return f"{CONF_EDGES[i] * 100:.0f}-{CONF_EDGES[i + 1] * 100:.0f}%"


def bin_stats(pred, obs, edges):
    """Equal-width buckets [e_i, e_{i+1}); returns per-bucket (n, mean_pred, mean_obs)."""
    idx = np.digitize(pred, edges[1:-1], right=False)  # 0 .. len(edges)-2
    out = []
    for b in range(len(edges) - 1):
        m = idx == b
        n = int(m.sum())
        out.append((n, float(pred[m].mean()) if n else None, float(obs[m].mean()) if n else None))
    return out


def ece(stats, total):
    return sum((n / total) * abs(o - p) for n, p, o in stats if n > 0)


def print_table(title, stats, labels, gap_name, total):
    print(f'\n{title}')
    print(f"  {'bucket':<10} {'N':>6} {'mean pred':>10} {'observed':>10} {gap_name:>10}")
    for label, (n, p, o) in zip(labels, stats):
        if n == 0:
            print(f'  {label:<10} {0:>6} {"-":>10} {"-":>10} {"-":>10}')
        else:
            print(f'  {label:<10} {n:>6} {p:>10.4f} {o:>10.4f} {o - p:>+10.4f}')
    print(f'  ECE = {ece(stats, total):.4f}   (N total = {total})')


def export_test_csv(out_path, dataset_path, raw_rows, idx, lam_h, lam_a, probs):
    """Per-match TEST export (out-of-sample: lambdas come from the TRAIN-only fit)."""
    import csv

    with open(dataset_path, newline='', encoding='utf-8') as f:
        competition_by_match = {r['match_id']: r['competition_id'] for r in csv.DictReader(f)}

    out_path.parent.mkdir(parents=True, exist_ok=True)
    with open(out_path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(['match_id', 'competition_id', 'season_id', 'kickoff_at', 'home_goals', 'away_goals',
                    'lambda_home', 'lambda_away', 'lambda3', 'p_home', 'p_draw', 'p_away'])
        for k, i in enumerate(idx):
            r = raw_rows[i]
            lh = max(float(lam_h[i]), 1e-6)
            la = max(float(lam_a[i]), 1e-6)
            l3_effective = min(cmp.LAMBDA3, min(lh, la) * 0.9999)   # same cap applied inside bp_1x2
            w.writerow([r['match_id'], competition_by_match.get(r['match_id'], ''), r['season_id'], r['kickoff_at'],
                        int(r['label_home_goals']), int(r['label_away_goals']),
                        repr(float(lam_h[i])), repr(float(lam_a[i])), repr(l3_effective),
                        repr(float(probs[k, 0])), repr(float(probs[k, 1])), repr(float(probs[k, 2]))])
    print(f'Exported {len(idx)} TEST rows to {out_path}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dataset', default=str(DEFAULT_DATASET), help='Team History dataset CSV.')
    parser.add_argument('--export-test-csv', default=None,
                        help='Optional: write the per-match TEST out-of-sample lambdas/probabilities to this CSV '
                             '(match_id, competition_id, season_id, kickoff_at, observed goals, lambda_home, '
                             'lambda_away, effective lambda3, 1X2 probabilities). Diagnostic export only.')
    args = parser.parse_args()

    raw_rows = cmp.load_dataset(Path(args.dataset), 'CANDIDATE47_TEAM_HISTORY')
    X, y_h, y_a, season_id = cmp.build_matrix(raw_rows)
    train_mask = np.isin(season_id, list(cmp.TRAIN_SEASONS))
    test_mask = np.isin(season_id, list(cmp.TEST_SEASONS))
    print(f'TRAIN rows: {train_mask.sum()}  TEST rows: {test_mask.sum()}')

    lam_h, lam_a = cmp.fit_and_predict(X, y_h, y_a, train_mask, test_mask)

    metrics = cmp.eval_model(lam_h, lam_a, y_h, y_a, test_mask)
    print('\n=== GLOBAL (TEST) ===')
    print(f"LogLoss={metrics['logloss']:.4f}  Brier={metrics['brier']:.4f}  Accuracy={metrics['accuracy']:.4f}  N={metrics['n']}")

    idx = np.where(test_mask)[0]
    probs = np.array([cmp.bp_1x2(lam_h[i], lam_a[i]) for i in idx])          # columns: H, D, A
    hg, ag = y_h[idx], y_a[idx]
    outcome = np.where(hg > ag, 0, np.where(hg == ag, 1, 2))                  # 0=H 1=D 2=A
    total = len(idx)

    if args.export_test_csv:
        export_test_csv(Path(args.export_test_csv), Path(args.dataset), raw_rows, idx, lam_h, lam_a, probs)

    print('\n=== 1X2 CALIBRATION (TEST) — gap = observed frequency - predicted probability ===')
    labels = [prob_label(PROB_EDGES[i], PROB_EDGES[i + 1], i == len(PROB_EDGES) - 2) for i in range(len(PROB_EDGES) - 1)]
    outcome_ece = {}
    for k, name in enumerate(OUTCOMES):
        stats = bin_stats(probs[:, k], (outcome == k).astype(float), PROB_EDGES[:-1] + [PROB_EDGES[-1] + 1e-12])
        print_table(f'{name}', stats, labels, 'gap', total)
        outcome_ece[name] = ece(stats, total)

    print('\n=== CONFIDENCE OF THE FINAL PICK = max(P_home, P_draw, P_away) (TEST) — gap = accuracy - confidence ===')
    conf = probs.max(axis=1)
    pick = probs.argmax(axis=1)
    correct = (pick == outcome).astype(float)
    conf_stats = bin_stats(conf, correct, CONF_EDGES)
    print_table('max-confidence', conf_stats, [conf_label(i) for i in range(len(CONF_EDGES) - 1)], 'gap', total)

    print('\n=== ECE SUMMARY (TEST) ===')
    for name in OUTCOMES:
        print(f'ECE {name:<5} = {outcome_ece[name]:.4f}')
    print(f'ECE max-confidence = {ece(conf_stats, total):.4f}')


if __name__ == '__main__':
    main()
