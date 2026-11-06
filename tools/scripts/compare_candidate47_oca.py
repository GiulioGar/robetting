"""
Step 74 — Candidate47 + RECENT_ALL (BASELINE) vs the same + Opponent Latent PIT (OCA).

EXPERIMENTAL, read-only on every production artifact: writes nothing, trains in memory,
changes neither Candidate47 nor any prediction/snapshot.

Both models are trained and evaluated on the SAME dataset rows with the SAME split/masks and
the SAME fixed hyper-parameters (PoissonRegressor alpha=1.0, BivariatePoisson lambda3=0.15,
train-only median imputation + scaling): the ONLY difference is the 8 OCA columns.
Training/evaluation helpers are imported from compare_candidate47_recent_modes.py (ported
verbatim from p16a_step4_train_compare.py); the 47 base features come from
candidate47_feature_pipeline.py (walk-forward Latent, point-in-time Structural, dataset Elo).

OCA columns (home/away kept separate, attack/defence kept separate):
  oca_att_home, oca_def_home, oca_att_away, oca_def_away : mean PIT Latent of the opponents of the
      home / away team over its Recent window (covered opponents only);
  oca_cov_home, oca_cov_away : covered / total matches of the window;
  oca_support_home, oca_support_away : mean number of history matches behind the covered estimates.
A window with no covered opponent has NaN means/support (never 0): the usual train-only median
imputation then applies, and the coverage columns (0.0) keep the information explicit.
The OCA features come from tools/scripts/opponent_latent_pit.py (same function intended for
future inference — no second implementation).

Inputs (all produced read-only):
  --dataset          robetting:build-prediction-dataset --mode=core_only --seasons=2024,2025
                     --recent-window-mode=recent_all --elo-mode=extended --output=...
  --legacy-csv/--extended-csv   robetting:export-latent-fit-matches --latent-mode=legacy|extended
                     (same --before cutoff, after the last dataset match)
  --all-matches-tsv  mysql -B -e "SELECT id, kickoff_at, home_team_id, away_team_id FROM matches
                     WHERE status='finished' AND home_score_ft IS NOT NULL AND away_score_ft IS NOT NULL"
                     (every competition, as the RECENT_ALL window uses)

Usage:
  py -3 tools/scripts/compare_candidate47_oca.py --dataset D.csv --legacy-csv L.csv \
      --extended-csv E.csv --all-matches-tsv M.tsv
"""
import argparse
import csv
import math
import sys
import time
from collections import defaultdict
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import candidate47_feature_pipeline as pipe  # noqa: E402
import compare_candidate47_recent_modes as base  # noqa: E402
import opponent_latent_pit as pit  # noqa: E402

OCA_NAMES = ['oca_att_home', 'oca_def_home', 'oca_att_away', 'oca_def_away',
             'oca_cov_home', 'oca_cov_away', 'oca_support_home', 'oca_support_away']
BOOTSTRAP_RESAMPLES = 2000
BOOTSTRAP_SEED = 74


def load_team_matches(tsv_path):
    """{team_id: [{'kickoff_at', 'opponent_id'}, ...]} from the all-finished-matches TSV."""
    by_team = defaultdict(list)
    with open(tsv_path, newline='', encoding='utf-8-sig') as f:
        for r in csv.DictReader(f, delimiter='\t'):
            h, a, k = r['home_team_id'], r['away_team_id'], r['kickoff_at']
            by_team[h].append({'kickoff_at': k, 'opponent_id': a})
            by_team[a].append({'kickoff_at': k, 'opponent_id': h})
    return by_team


def windows_for_rows(hist_rows, team_matches, n=pit.DEFAULT_WINDOW):
    """{(match_id, 'home'|'away'): [(date, opponent_id), ...]} — Recent window of each side,
    strictly before the row's own kickoff (so never the target match itself)."""
    out = {}
    for r in hist_rows:
        for side, tid in (('home', str(r['home_team_id'])), ('away', str(r['away_team_id']))):
            out[(str(r['match_id']), side)] = pit.recent_window(team_matches.get(tid, []), r['kickoff_at'], n)
    return out


def oca_columns(summary_home, summary_away):
    """The 8 OCA values of one match; None = missing (no covered opponent), never 0."""
    def cov(s):
        return (s['matches_covered'] / s['matches_total']) if s['matches_total'] else 0.0
    return {
        'oca_att_home': summary_home['opp_latent_attack_mean'],
        'oca_def_home': summary_home['opp_latent_defence_mean'],
        'oca_att_away': summary_away['opp_latent_attack_mean'],
        'oca_def_away': summary_away['opp_latent_defence_mean'],
        'oca_cov_home': cov(summary_home),
        'oca_cov_away': cov(summary_away),
        'oca_support_home': summary_home['opp_support_mean'],
        'oca_support_away': summary_away['opp_support_mean'],
    }


def oca_matrix(match_ids, summaries):
    X = np.full((len(match_ids), len(OCA_NAMES)), np.nan)
    for i, mid in enumerate(match_ids):
        cols = oca_columns(summaries[(mid, 'home')], summaries[(mid, 'away')])
        for j, name in enumerate(OCA_NAMES):
            if cols[name] is not None:
                X[i, j] = cols[name]
    return X


def fit_predict_matrix(X, y_h, y_a, train_mask):
    """Same procedure as base.fit_and_predict (train-only median/scaler, Poisson alpha=1)."""
    return base.fit_and_predict(X, y_h, y_a, train_mask, None)


def probabilities(lam_h, lam_a, mask):
    idx = np.where(mask)[0]
    return idx, np.array([base.bp_1x2(lam_h[i], lam_a[i]) for i in idx])


def outcome_index(y_h, y_a, idx):
    return np.array([0 if y_h[i] > y_a[i] else (1 if y_h[i] == y_a[i] else 2) for i in idx])


def per_match_logloss(P, y):
    return -np.log(np.maximum(P[np.arange(len(y)), y], 1e-12))


def ece_per_class(P, y, bins=10):
    """Equal-count bins per class: (ECE, mean predicted, observed rate)."""
    res = []
    for c in range(3):
        p = P[:, c]
        o = (y == c).astype(float)
        order = np.argsort(p)
        chunks = np.array_split(order, bins)
        ece = sum(len(ch) / len(p) * abs(p[ch].mean() - o[ch].mean()) for ch in chunks if len(ch))
        res.append((ece, p.mean(), o.mean()))
    return res


def paired_bootstrap_ci(diff, resamples=BOOTSTRAP_RESAMPLES, seed=BOOTSTRAP_SEED):
    rng = np.random.default_rng(seed)
    n = len(diff)
    means = np.array([diff[rng.integers(0, n, n)].mean() for _ in range(resamples)])
    return float(np.percentile(means, 2.5)), float(np.percentile(means, 97.5))


def brier(P, y):
    onehot = np.eye(3)[y]
    return float(((P - onehot) ** 2).sum(axis=1).mean())


def coverage_report(match_ids, summaries, mask, label):
    tot = cov = full = 0
    wins = 0
    for i in np.where(mask)[0]:
        for side in ('home', 'away'):
            s = summaries[(match_ids[i], side)]
            tot += s['matches_total']; cov += s['matches_covered']
            full += s['matches_covered'] == s['matches_total']
            wins += 1
    print('  %-5s windows=%d slots=%d covered=%d (%.1f%%) fully-covered windows=%d (%.1f%%)' % (
        label, wins, tot, cov, 100 * cov / max(1, tot), full, 100 * full / max(1, wins)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dataset', required=True)
    ap.add_argument('--legacy-csv', required=True)
    ap.add_argument('--extended-csv', required=True)
    ap.add_argument('--all-matches-tsv', required=True)
    a = ap.parse_args()

    t0 = time.perf_counter()
    hist_rows = pipe.load_historical_rows(a.dataset)
    raw_rows = pipe.build_raw_feature_rows(hist_rows)
    X47, y_h, y_a, season_id = base.build_matrix(raw_rows)
    match_ids = [str(r['match_id']) for r in raw_rows]
    t_base = time.perf_counter() - t0

    t1 = time.perf_counter()
    core, uefa = pit.load_fit_histories(a.legacy_csv, a.extended_csv)
    windows = windows_for_rows(hist_rows, load_team_matches(a.all_matches_tsv))
    summaries = pit.compute_opponent_latent_features(windows, core, uefa)
    Xoca = oca_matrix(match_ids, summaries)
    t_oca = time.perf_counter() - t1

    train_mask = np.isin(season_id, list(base.TRAIN_SEASONS))
    test_mask = np.isin(season_id, list(base.TEST_SEASONS))
    print('rows=%d TRAIN=%d TEST=%d | base features %.1fs, OCA features %.1fs' % (
        len(raw_rows), train_mask.sum(), test_mask.sum(), t_base, t_oca))
    print('OCA coverage:')
    coverage_report(match_ids, summaries, train_mask, 'TRAIN')
    coverage_report(match_ids, summaries, test_mask, 'TEST')
    print('  OCA NaN mean columns (TRAIN): ' + ', '.join(
        '%s=%d' % (n, int(np.isnan(Xoca[train_mask, j]).sum())) for j, n in enumerate(OCA_NAMES[:4])))

    results = {}
    for label, X in (('BASELINE (RECENT_ALL)', X47), ('+ OCA', np.hstack([X47, Xoca]))):
        lam_h, lam_a = fit_predict_matrix(X, y_h, y_a, train_mask)
        idx, P = probabilities(lam_h, lam_a, test_mask)
        y = outcome_index(y_h, y_a, idx)
        ll = per_match_logloss(P, y)
        results[label] = (idx, P, y, ll)
        m = base.eval_model(lam_h, lam_a, y_h, y_a, test_mask)
        print('%-22s n=%d logloss=%.5f brier=%.5f acc=%.4f' % (label, m['n'], m['logloss'], m['brier'], m['accuracy']))
        for name, (ece, mp, orate) in zip('HDA', ece_per_class(P, y)):
            print('    class %s: ECE=%.4f mean pred=%.4f observed=%.4f' % (name, ece, mp, orate))

    (_, P0, y0, ll0), (_, P1, y1, ll1) = results['BASELINE (RECENT_ALL)'], results['+ OCA']
    assert (y0 == y1).all()
    diff = ll1 - ll0
    lo, hi = paired_bootstrap_ci(diff)
    print('delta logloss (OCA - BASE) = %+.5f  paired bootstrap 95%% CI [%+.5f, %+.5f]  OCA better in %d/%d matches' % (
        diff.mean(), lo, hi, int((diff < 0).sum()), len(diff)))
    print('delta brier = %+.5f' % (brier(P1, y1) - brier(P0, y0)))


if __name__ == '__main__':
    main()
