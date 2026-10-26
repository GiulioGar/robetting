"""
Isolated BASE vs CHALLENGER comparison for a single Candidate47 FEATS47 change.

Generic driver — CHALLENGER may isolate a Recent-window change (Step C2.4) or
an Elo-scope change (Step C-ELO) or any other single-axis experiment, as long
as both datasets share the SAME 3511 core-league target matches/labels (only
verified here, not re-derived) and were built via
tools/datasets dataset_core_v1_*.csv (HistoricalPredictionDatasetBuilder output).

Default paths (Step C2.4 — Team History Recent vs legacy Recent):
    BASE        = tools/datasets/dataset_core_v1_legacy_current_2024_2025.csv
    CHALLENGER  = tools/datasets/dataset_core_v1_team_history_2024_2025.csv

Override with --base/--challenger to run any other isolated single-axis
comparison (e.g. Step C-ELO: Team History Recent BASE vs +Elo Network CHALLENGER).

Raw-feature construction (39 core + elo_gap_signed_square + 4 Latent walk-forward +
3 Structural LOG = 47) is delegated verbatim to candidate47_feature_pipeline.py —
zero methodology change, same module used to build the production Candidate47
artifact.

Before training: compares the 47 FEATS47 values row-by-row (same match_id order,
already verified) between BASE and CHALLENGER and prints the feature names that
differ. If any differing feature falls outside --expect-diff (default: the Step
C2.4 Recent-window axis), the script aborts BEFORE training — the isolation
requirement is violated and no benchmark is run.

TRAIN/TEST split, MISSING30 gating, median imputation (fit on TRAIN only),
StandardScaler (fit on TRAIN only), PoissonRegressor(alpha=1.0, max_iter=500) x2,
Bivariate Poisson 1X2 (Karlis & Ntzoufras, lambda3=0.15 fixed) and the
logloss/brier/accuracy metrics are ported verbatim from
tools/scripts/p16a_step4_train_compare.py (TRAIN_SEASONS={28,29,30,31,32},
TEST_SEASONS={23,24,25,26,27}). No new tuning, no new metric, no threshold.

Writes NOTHING — no artifact, no CSV, no production file is touched. Console
output only.

Usage:
    py -3 tools/scripts/compare_candidate47_recent_modes.py
    py -3 tools/scripts/compare_candidate47_recent_modes.py --base <path> --challenger <path>
    py -3 tools/scripts/compare_candidate47_recent_modes.py --base <path> --challenger <path> \
        --expect-diff core_elo_home_pre_match_elo core_elo_away_pre_match_elo elo_gap_signed_square
"""
import argparse
import math
import sys
from pathlib import Path

import numpy as np
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import candidate47_feature_pipeline as pipe  # noqa: E402

FEATS47 = pipe.FEATS47
LAMBDA3 = pipe.LAMBDA3
MISSING30_THRESHOLD = pipe.MISSING30_THRESHOLD
MISSING30_FEATS = set(pipe.MISSING30_FEATURES)

DEFAULT_BASE_PATH = ROOT / 'tools/datasets/dataset_core_v1_legacy_current_2024_2025.csv'
DEFAULT_CHALLENGER_PATH = ROOT / 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv'

# Step C2.4 default isolation axis (Recent window: core_recent_* — 12 features).
DEFAULT_EXPECTED_DIFF_FEATURES = sorted(f for f in FEATS47 if f.startswith('core_recent_'))

# Float-equality tolerance for the row-by-row feature diff check (guards against
# float round-trip noise through CSV text, not a real numeric difference).
DIFF_EPSILON = 1e-9

# Ported verbatim from p16a_step4_train_compare.py.
TRAIN_SEASONS = {'28', '29', '30', '31', '32'}
TEST_SEASONS = {'23', '24', '25', '26', '27'}
MAX_GOALS = 10


class CompareError(Exception):
    pass


def load_dataset(csv_path, label, latent_history_csv=None):
    hist_rows = pipe.load_historical_rows(csv_path)
    raw_rows = pipe.build_raw_feature_rows(hist_rows, latent_history_csv=latent_history_csv, verbose=True)
    print(f"[{label}] rows: {len(raw_rows)}" + (f"  (latent_history_csv={latent_history_csv})" if latent_history_csv else ""))
    return raw_rows


def verify_same_targets(base_rows, challenger_rows):
    """Step C2.3 already verified parity; re-check cheaply before training —
    same match_id, same order, same labels, or abort."""
    if len(base_rows) != len(challenger_rows):
        raise CompareError(
            f"Row count mismatch: BASE={len(base_rows)} CHALLENGER={len(challenger_rows)}."
        )

    for i, (b, c) in enumerate(zip(base_rows, challenger_rows)):
        if b['match_id'] != c['match_id']:
            raise CompareError(
                f"Target mismatch at row {i}: BASE match_id={b['match_id']} "
                f"!= CHALLENGER match_id={c['match_id']}. Datasets are not aligned."
            )
        if b['kickoff_at'] != c['kickoff_at']:
            raise CompareError(
                f"kickoff_at mismatch at row {i} (match_id={b['match_id']}): "
                f"BASE={b['kickoff_at']} CHALLENGER={c['kickoff_at']}."
            )
        if (b['label_home_goals'], b['label_away_goals']) != (c['label_home_goals'], c['label_away_goals']):
            raise CompareError(
                f"Label mismatch at row {i} (match_id={b['match_id']})."
            )


def diff_feats47(base_rows, challenger_rows):
    """
    Row-aligned (same match_id order, already verified by verify_same_targets)
    comparison of the 47 FEATS47 raw values between BASE and CHALLENGER.

    @return sorted list of FEATS47 names that differ in at least one row.
    """
    differing = []
    for f in FEATS47:
        changed = False
        for b, c in zip(base_rows, challenger_rows):
            bv, cv = b[f], c[f]
            if (bv is None) != (cv is None):
                changed = True
                break
            if bv is not None and abs(float(bv) - float(cv)) > DIFF_EPSILON:
                changed = True
                break
        if changed:
            differing.append(f)
    return differing


def build_matrix(raw_rows):
    """39+8 = 47-column matrix with MISSING30 gating, raw (unimputed) NaNs."""
    n = len(raw_rows)
    X = np.zeros((n, len(FEATS47)), dtype=np.float64)
    for i, r in enumerate(raw_rows):
        for j, f in enumerate(FEATS47):
            v = r[f]
            if v is None:
                X[i, j] = np.nan
            elif f in MISSING30_FEATS and float(v) > MISSING30_THRESHOLD:
                X[i, j] = np.nan
            else:
                X[i, j] = float(v)

    y_h = np.array([r['label_home_goals'] for r in raw_rows], dtype=np.float64)
    y_a = np.array([r['label_away_goals'] for r in raw_rows], dtype=np.float64)
    season_id = np.array([r['season_id'] for r in raw_rows])

    return X, y_h, y_a, season_id


def fit_and_predict(X_full, y_h, y_a, train_mask, test_mask):
    """Ported verbatim from p16a_step4_train_compare.py — median/scaler fit on TRAIN only."""
    medians = np.nanmedian(X_full[train_mask], axis=0)
    X_imp = np.where(np.isnan(X_full), medians, X_full)
    scaler = StandardScaler()
    X_sc_train = scaler.fit_transform(X_imp[train_mask])
    X_sc_all = scaler.transform(X_imp)

    model_h = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc_train, y_h[train_mask])
    model_a = PoissonRegressor(alpha=1.0, max_iter=500).fit(X_sc_train, y_a[train_mask])

    lam_h = model_h.predict(X_sc_all)
    lam_a = model_a.predict(X_sc_all)
    return lam_h, lam_a


_logfact = [0.0]
for k in range(1, MAX_GOALS + 1):
    _logfact.append(_logfact[-1] + math.log(k))


def bp_1x2(lh, la, l3=LAMBDA3):
    """Ported verbatim from p16a_step4_train_compare.py (Karlis & Ntzoufras)."""
    lh = max(lh, 1e-6)
    la = max(la, 1e-6)
    l3 = min(l3, min(lh, la) * 0.9999)
    l1 = lh - l3
    l2 = la - l3
    log_ratio = math.log(l3) - math.log(l1) - math.log(l2)
    const = -(l1 + l2 + l3)
    pH = pD = pA = 0.0
    for x in range(MAX_GOALS + 1):
        logpx = x * math.log(l1) - _logfact[x] if x > 0 else -_logfact[0]
        for y in range(MAX_GOALS + 1):
            logpy = y * math.log(l2) - _logfact[y] if y > 0 else -_logfact[0]
            inner = 1.0
            for k in range(1, min(x, y) + 1):
                logterm = (_logfact[x] - _logfact[k] - _logfact[x - k]) + \
                          (_logfact[y] - _logfact[k] - _logfact[y - k]) + \
                          _logfact[k] + k * log_ratio
                inner += math.exp(logterm)
            p = math.exp(const + logpx + logpy) * inner
            if x > y:
                pH += p
            elif x == y:
                pD += p
            else:
                pA += p
    total = pH + pD + pA
    return pH / total, pD / total, pA / total


def eval_model(lam_h, lam_a, y_h, y_a, mask):
    """Ported verbatim from p16a_step4_train_compare.py."""
    idx = np.where(mask)[0]
    logloss = 0.0
    brier = 0.0
    correct = 0
    for i in idx:
        pH, pD, pA = bp_1x2(lam_h[i], lam_a[i])
        hg, ag = y_h[i], y_a[i]
        outcome = 'H' if hg > ag else ('D' if hg == ag else 'A')
        p_map = {'H': pH, 'D': pD, 'A': pA}
        p_true = max(p_map[outcome], 1e-12)
        logloss += -math.log(p_true)
        one_hot = {'H': (1, 0, 0), 'D': (0, 1, 0), 'A': (0, 0, 1)}[outcome]
        brier += (pH - one_hot[0]) ** 2 + (pD - one_hot[1]) ** 2 + (pA - one_hot[2]) ** 2
        pred = 'H' if (pH >= pD and pH >= pA) else ('A' if pA >= pD else 'D')
        if pred == outcome:
            correct += 1
    n = len(idx)
    return {
        'logloss': logloss / n,
        'brier': brier / n,
        'accuracy': correct / n,
        'n': n,
    }


def run_one(raw_rows, label):
    X, y_h, y_a, season_id = build_matrix(raw_rows)
    train_mask = np.isin(season_id, list(TRAIN_SEASONS))
    test_mask = np.isin(season_id, list(TEST_SEASONS))

    print(f"[{label}] TRAIN rows: {train_mask.sum()}  TEST rows: {test_mask.sum()}")

    lam_h, lam_a = fit_and_predict(X, y_h, y_a, train_mask, test_mask)
    metrics = eval_model(lam_h, lam_a, y_h, y_a, test_mask)
    return metrics, int(train_mask.sum()), int(test_mask.sum())


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default=str(DEFAULT_BASE_PATH),
                         help='BASE dataset CSV path (default: Step C2.4 legacy-current Recent dataset).')
    parser.add_argument('--challenger', default=str(DEFAULT_CHALLENGER_PATH),
                         help='CHALLENGER dataset CSV path (default: Step C2.4 team_history Recent dataset).')
    parser.add_argument('--expect-diff', nargs='*', default=DEFAULT_EXPECTED_DIFF_FEATURES,
                         help='FEATS47 names allowed to differ between BASE and CHALLENGER. '
                              'Default: the 12 core_recent_* features (Step C2.4 Recent-window axis). '
                              'Any other differing feature aborts before training.')
    parser.add_argument('--latent-history-csv', default=None,
                         help='Optional ExportLatentFitMatches CSV (robetting:export-latent-fit-matches '
                              '--latent-mode=extended) supplying EXTRA Latent walk-forward history for the '
                              'CHALLENGER ONLY — BASE always uses legacy Latent (no extra history). '
                              'Use the SAME --base/--challenger dataset path for a pure Latent-axis '
                              'experiment (e.g. both = dataset_core_v1_team_history_2024_2025.csv).')
    return parser.parse_args()


def main():
    args = parse_args()
    expected_diff = set(args.expect_diff)

    base_rows = load_dataset(Path(args.base), 'BASE')
    challenger_rows = load_dataset(Path(args.challenger), 'CHALLENGER', latent_history_csv=args.latent_history_csv)

    try:
        verify_same_targets(base_rows, challenger_rows)
    except CompareError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    differing = diff_feats47(base_rows, challenger_rows)
    print(f"\nFEATS47 differing (BASE vs CHALLENGER): {differing}")

    unexpected = sorted(set(differing) - expected_diff)
    if unexpected:
        print(
            f"ERROR: isolation violated — unexpected differing feature(s): {unexpected}. "
            f"Expected only: {sorted(expected_diff)}. Aborting before training.",
            file=sys.stderr,
        )
        sys.exit(1)

    metrics_base, train_n, test_n = run_one(base_rows, 'BASE')
    metrics_challenger, _, _ = run_one(challenger_rows, 'CHALLENGER')

    print(f"\nTrain rows: {train_n}")
    print(f"Test rows:  {test_n}")

    print(f"\nBASE        logloss={metrics_base['logloss']:.4f}  brier={metrics_base['brier']:.4f}  accuracy={metrics_base['accuracy']:.4f}")
    print(f"CHALLENGER  logloss={metrics_challenger['logloss']:.4f}  brier={metrics_challenger['brier']:.4f}  accuracy={metrics_challenger['accuracy']:.4f}")

    d_logloss = metrics_challenger['logloss'] - metrics_base['logloss']
    d_brier = metrics_challenger['brier'] - metrics_base['brier']
    d_acc = metrics_challenger['accuracy'] - metrics_base['accuracy']
    print(f"\nDelta (CHALLENGER - BASE): dLogLoss={d_logloss:+.5f}  dBrier={d_brier:+.5f}  dAccuracy={d_acc * 100:+.2f}pp")


if __name__ == '__main__':
    main()
