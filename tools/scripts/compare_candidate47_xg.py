"""
Isolated BASE vs CHALLENGER benchmark for the recent-xG experiment.

BASE        Candidate47 Team History Recent, the current 47 features
            (tools/datasets/dataset_core_v1_team_history_2024_2025.csv).
CHALLENGER  the SAME 47 features + exactly 4 xG features merged by match_id from
            the separate export produced by
            `php artisan robetting:export-recent-xg-features`:
                home_recent_avg_xg_for, home_recent_avg_xg_against,
                away_recent_avg_xg_for, away_recent_avg_xg_against
            (51 features). Diagnostic columns (window size, observations,
            coverage) are only printed, never used as features.

Everything else is identical and delegated to the existing driver
(compare_candidate47_recent_modes.py -> candidate47_feature_pipeline.py):
TRAIN/TEST seasons, MISSING30 gating, median imputation fit on TRAIN only (the 4
xG columns get the SAME treatment: TRAIN median for nulls, no missing indicator,
no ratio/diff), StandardScaler fit on TRAIN only, PoissonRegressor(alpha=1.0,
max_iter=500) x2, Bivariate Poisson (lambda3=0.15), logloss (primary), Brier,
accuracy. No tuning.

Before training it verifies that the xG export covers EXACTLY the dataset's
targets, in the same order, and that the merge keeps every benchmark row; any
mismatch aborts BEFORE training.

Writes NOTHING (no artifact, no CSV). Console output only.

Usage:
    py -3 tools/scripts/compare_candidate47_xg.py
    py -3 tools/scripts/compare_candidate47_xg.py --dataset <csv> --xg <csv>
"""
import argparse
import csv
import sys
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import compare_candidate47_recent_modes as cmp  # noqa: E402

DEFAULT_DATASET = ROOT / 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv'
DEFAULT_XG = ROOT / 'tools/datasets/recent_xg_features_2024_2025.csv'

XG_FEATURES = [
    'home_recent_avg_xg_for',
    'home_recent_avg_xg_against',
    'away_recent_avg_xg_for',
    'away_recent_avg_xg_against',
]
DIAG_COLUMNS = [
    'home_recent_window_matches', 'home_recent_xg_for_obs', 'home_recent_xg_against_obs', 'home_recent_xg_coverage',
    'away_recent_window_matches', 'away_recent_xg_for_obs', 'away_recent_xg_against_obs', 'away_recent_xg_coverage',
]


class XgError(Exception):
    pass


def read_csv(path):
    with open(path, newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def to_float(v):
    return None if v is None or v == '' else float(v)


def verify_merge(dataset_rows, xg_rows, raw_rows):
    """Same targets, same order, nothing dropped by the merge."""
    dataset_ids = [r['match_id'] for r in dataset_rows]
    xg_ids = [r['match_id'] for r in xg_rows]

    if len(set(xg_ids)) != len(xg_ids):
        raise XgError('xG export contains duplicate match_id.')
    if xg_ids != dataset_ids:
        raise XgError(
            f'xG export targets differ from the dataset targets '
            f'(dataset={len(dataset_ids)}, xg={len(xg_ids)}); ids/order must match exactly.'
        )

    raw_ids = [r['match_id'] for r in raw_rows]
    if len(raw_ids) != len(dataset_ids) or set(raw_ids) != set(dataset_ids):
        raise XgError(
            f'Benchmark rows ({len(raw_ids)}) do not cover the {len(dataset_ids)} dataset targets: '
            f'the merge would not keep the same target set.'
        )


def attach_xg(raw_rows, xg_rows):
    """Order-preserving left join on match_id (raw_rows order is kept untouched)."""
    by_id = {r['match_id']: r for r in xg_rows}
    out = []
    for r in raw_rows:
        x = by_id[r['match_id']]
        merged = dict(r)
        for f in XG_FEATURES:
            merged[f] = to_float(x[f])
        out.append(merged)
    return out


def build_matrix_51(raw_rows_with_xg):
    X47, y_h, y_a, season_id = cmp.build_matrix(raw_rows_with_xg)
    xg = np.full((len(raw_rows_with_xg), len(XG_FEATURES)), np.nan)
    for i, r in enumerate(raw_rows_with_xg):
        for j, f in enumerate(XG_FEATURES):
            if r[f] is not None:
                xg[i, j] = r[f]
    return np.hstack([X47, xg]), X47, y_h, y_a, season_id


# Name fragments flagging "recent form / goals / attack-defence strength" features,
# highlighted (with '*') in the redundancy diagnostic.
HIGHLIGHT_KEYWORDS = ('recent', 'goals', 'form', 'points', 'attack', 'defen', 'latent', 'strength')


def pairwise_pearson(a, b):
    """Pearson r over pairwise-complete observations; (r, n). r is None if undefined."""
    m = ~(np.isnan(a) | np.isnan(b))
    n = int(m.sum())
    if n < 3:
        return None, n
    x, y = a[m], b[m]
    if x.std() == 0 or y.std() == 0:
        return None, n
    return float(np.corrcoef(x, y)[0, 1]), n


def redundancy_diagnostic(X51, train_mask, top_n=10):
    """TRAIN-only correlation diagnostic of the 4 xG features vs the 47 BASE features
    (raw values, MISSING30-gated NaN kept as missing). Looks at nothing from TEST."""
    names47 = list(cmp.FEATS47)
    Xtr = X51[train_mask]
    n47 = len(names47)

    print('\n=== xG redundancy diagnostic (TRAIN only, pairwise-complete Pearson) ===')
    for j, xg_name in enumerate(XG_FEATURES):
        xg = Xtr[:, n47 + j]
        rows = []
        for i, name in enumerate(names47):
            r, n = pairwise_pearson(xg, Xtr[:, i])
            if r is not None:
                rows.append((name, r, n))
        rows.sort(key=lambda t: abs(t[1]), reverse=True)
        print(f'\n{xg_name}  (non-null TRAIN rows: {int((~np.isnan(xg)).sum())})')
        print(f'  top {top_n} |r| vs BASE features:')
        for name, r, n in rows[:top_n]:
            flag = '*' if any(k in name for k in HIGHLIGHT_KEYWORDS) else ' '
            print(f'   {flag} {name:<60} r={r:+.3f}  (n={n})')
        flagged = [(name, r) for name, r, _ in rows if any(k in name for k in HIGHLIGHT_KEYWORDS) and abs(r) >= 0.5]
        if flagged:
            print('  recent/goals/form/strength features with |r| >= 0.5: '
                  + ', '.join(f'{name} ({r:+.2f})' for name, r in flagged))
        else:
            print('  no recent/goals/form/strength feature with |r| >= 0.5')

    print('\nxG-vs-xG correlation matrix (TRAIN, pairwise-complete):')
    short = ['h_for', 'h_agst', 'a_for', 'a_agst']
    print('           ' + ''.join(f'{s:>9}' for s in short))
    for a in range(4):
        cells = []
        for b in range(4):
            r, _ = pairwise_pearson(Xtr[:, n47 + a], Xtr[:, n47 + b])
            cells.append(f'{r:+9.3f}' if r is not None else f'{"n/a":>9}')
        print(f'{short[a]:>9}  ' + ''.join(cells))
    print('(* = name contains one of: ' + ', '.join(HIGHLIGHT_KEYWORDS) + ')')


# XG_REPLACE_GOALS: the 4 recent goals features are replaced IN PLACE (same column
# positions, same imputation/scaling) by the matching xG features -> still 47 features.
REPLACE_GOALS = {
    'core_recent_home_avg_goals_for':     'home_recent_avg_xg_for',
    'core_recent_home_avg_goals_against': 'home_recent_avg_xg_against',
    'core_recent_away_avg_goals_for':     'away_recent_avg_xg_for',
    'core_recent_away_avg_goals_against': 'away_recent_avg_xg_against',
}


def build_matrix_replace_goals(X47, X51):
    names47 = list(cmp.FEATS47)
    X = X47.copy()
    for goals_name, xg_name in REPLACE_GOALS.items():
        X[:, names47.index(goals_name)] = X51[:, len(names47) + XG_FEATURES.index(xg_name)]
    return X


def run(X, y_h, y_a, train_mask, test_mask):
    lam_h, lam_a = cmp.fit_and_predict(X, y_h, y_a, train_mask, test_mask)
    return cmp.eval_model(lam_h, lam_a, y_h, y_a, test_mask)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dataset', default=str(DEFAULT_DATASET), help='Team History target dataset CSV.')
    parser.add_argument('--xg', default=str(DEFAULT_XG), help='xG export CSV (robetting:export-recent-xg-features).')
    args = parser.parse_args()

    dataset_rows = read_csv(args.dataset)
    xg_rows = read_csv(args.xg)

    raw_rows = cmp.load_dataset(Path(args.dataset), 'BASE')

    try:
        verify_merge(dataset_rows, xg_rows, raw_rows)
    except XgError as e:
        print(f'ERROR: {e}', file=sys.stderr)
        sys.exit(1)
    print(f'Merge check OK: {len(raw_rows)} targets, identical ids/order between dataset and xG export.')

    merged = attach_xg(raw_rows, xg_rows)
    X51, X47, y_h, y_a, season_id = build_matrix_51(merged)

    train_mask = np.isin(season_id, list(cmp.TRAIN_SEASONS))
    test_mask = np.isin(season_id, list(cmp.TEST_SEASONS))
    print(f'TRAIN rows: {train_mask.sum()}  TEST rows: {test_mask.sum()}')

    xg_cols = X51[:, len(cmp.FEATS47):]
    if np.all(np.isnan(xg_cols[train_mask]), axis=0).any():
        print('ERROR: an xG feature has no TRAIN observation at all; median imputation undefined.', file=sys.stderr)
        sys.exit(1)

    # Diagnostics only (never features).
    for name, mask in (('TRAIN', train_mask), ('TEST', test_mask)):
        null_pct = np.isnan(xg_cols[mask]).mean(axis=0) * 100
        print(f'xG null % {name}: ' + '  '.join(f'{f}={p:.1f}%' for f, p in zip(XG_FEATURES, null_pct)))

    redundancy_diagnostic(X51, train_mask)

    X_rep = build_matrix_replace_goals(X47, X51)
    assert X_rep.shape == X47.shape, 'XG_REPLACE_GOALS must keep exactly 47 features.'

    base = run(X47, y_h, y_a, train_mask, test_mask)
    add = run(X51, y_h, y_a, train_mask, test_mask)
    rep = run(X_rep, y_h, y_a, train_mask, test_mask)

    print('\nReplaced columns (XG_REPLACE_GOALS): ' + ', '.join(f'{g} -> {x}' for g, x in REPLACE_GOALS.items()))
    for label, m in (('BASE (47)', base), ('XG_ADD (51)', add), ('XG_REPLACE_GOALS (47)', rep)):
        print(f"{label:<22} logloss={m['logloss']:.4f}  brier={m['brier']:.4f}  accuracy={m['accuracy']:.4f}")
    for label, m in (('XG_ADD (51)', add), ('XG_REPLACE_GOALS (47)', rep)):
        print(
            f"Delta ({label} - BASE): dLogLoss={m['logloss'] - base['logloss']:+.5f}  "
            f"dBrier={m['brier'] - base['brier']:+.5f}  dAccuracy={(m['accuracy'] - base['accuracy']) * 100:+.2f}pp"
        )


if __name__ == '__main__':
    main()
