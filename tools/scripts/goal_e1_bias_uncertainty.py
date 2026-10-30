"""
GOAL E1 (exploratory) - uncertainty of the HOME/AWAY lambda bias.

Reads ONLY the out-of-sample lambda CSV (fit on 2024/25, evaluated on 2025/26):
  tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv
No model, no fit, no DB/API. Standard library only. Writes nothing.

Per match:
  err_home = lambda_home - home_goals
  err_away = lambda_away - away_goals
  diff     = err_away - err_home          (paired AWAY-HOME)

Main method: block bootstrap, blocks = ISO calendar week of kickoff_at (UTC),
all matches of the same week (all leagues) resampled together; percentile 95% CI;
fixed seed. Leave-one-league-out repeats the same procedure without one league.

EXPLORATORY analysis on 2025/26: not an independent confirmation.

Usage: py tools/scripts/goal_e1_bias_uncertainty.py [--csv PATH] [--reps 2000] [--seed 20261031]
"""
import argparse
import csv
import math
import random
from collections import defaultdict
from datetime import datetime

DEFAULT_CSV = 'tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv'
REQUIRED = ['match_id', 'competition_id', 'kickoff_at', 'home_goals', 'away_goals', 'lambda_home', 'lambda_away']
INDICATORS = ('err_home', 'err_away', 'diff')


def load(path):
    with open(path, newline='', encoding='utf-8') as f:
        rd = csv.DictReader(f)
        missing = [c for c in REQUIRED if c not in (rd.fieldnames or [])]
        if missing:
            raise SystemExit(f'missing columns: {missing}')
        rows = list(rd)
    out, seen = [], set()
    for r in rows:
        if r['match_id'] in seen:
            raise SystemExit(f"duplicate match_id {r['match_id']}")
        seen.add(r['match_id'])
        vals = [r[c] for c in REQUIRED]
        if any(v in ('', None) for v in vals):
            raise SystemExit(f"null in required column, match {r['match_id']}")
        k = datetime.fromisoformat(r['kickoff_at'].replace('Z', '+00:00'))
        iso = k.isocalendar()
        eh = float(r['lambda_home']) - float(r['home_goals'])
        ea = float(r['lambda_away']) - float(r['away_goals'])
        out.append({'comp': int(r['competition_id']), 'week': (iso[0], iso[1]),
                    'err_home': eh, 'err_away': ea, 'diff': ea - eh})
    return out


def mean_se_iid(xs):
    n = len(xs)
    m = sum(xs) / n
    var = sum((x - m) ** 2 for x in xs) / (n - 1)
    return m, math.sqrt(var / n)


def block_bootstrap(rows, reps, rng):
    """Percentile CI per indicator, resampling calendar-week blocks."""
    blocks = defaultdict(lambda: [0, 0.0, 0.0, 0.0])  # n, sums per indicator
    for r in rows:
        b = blocks[r['week']]
        b[0] += 1
        for i, k in enumerate(INDICATORS):
            b[1 + i] += r[k]
    bl = list(blocks.values())
    nb = len(bl)
    dist = {k: [] for k in INDICATORS}
    for _ in range(reps):
        n = 0
        s = [0.0, 0.0, 0.0]
        for _ in range(nb):
            b = bl[rng.randrange(nb)]
            n += b[0]
            s[0] += b[1]
            s[1] += b[2]
            s[2] += b[3]
        for i, k in enumerate(INDICATORS):
            dist[k].append(s[i] / n)
    res = {}
    for k in INDICATORS:
        d = sorted(dist[k])
        res[k] = (d[int(0.025 * reps)], d[int(0.975 * reps) - 1], math.sqrt(_var(d)))
    return res, nb


def _var(xs):
    m = sum(xs) / len(xs)
    return sum((x - m) ** 2 for x in xs) / (len(xs) - 1)


def fmt(m, lo, hi):
    return f'{m:+.4f} [{lo:+.4f}, {hi:+.4f}]'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--csv', default=DEFAULT_CSV)
    ap.add_argument('--reps', type=int, default=2000)
    ap.add_argument('--seed', type=int, default=20261031)
    a = ap.parse_args()

    rows = load(a.csv)
    print(f'N={len(rows)} rows, unique match_id OK, required columns present: {REQUIRED}')
    print('EXPLORATORY on 2025/26 (fit TRAIN-only 2024/25); not an independent confirmation.\n')

    means = {k: sum(r[k] for r in rows) / len(rows) for k in INDICATORS}
    boot, nb = block_bootstrap(rows, a.reps, random.Random(a.seed))
    print(f'GLOBAL  (block bootstrap by ISO calendar week, {nb} blocks, {a.reps} reps, seed {a.seed}; percentile 95% CI)')
    print(f"{'indicator':<10} {'mean [95% CI]':<34} {'boot SE':>8} {'iid SE':>8}")
    for k in INDICATORS:
        lo, hi, se = boot[k]
        _, se_iid = mean_se_iid([r[k] for r in rows])
        print(f'{k:<10} {fmt(means[k], lo, hi):<34} {se:8.4f} {se_iid:8.4f}')

    print('\nPER LEAGUE (descriptive: N and mean bias; no inference)')
    print(f"{'comp':<6} {'N':>5} {'err_home':>9} {'err_away':>9} {'diff':>9}")
    comps = sorted({r['comp'] for r in rows})
    for c in comps:
        rs = [r for r in rows if r['comp'] == c]
        print(f"{c:<6} {len(rs):>5} " + ' '.join(f"{sum(r[k] for r in rs) / len(rs):+9.4f}" for k in INDICATORS))

    print('\nLEAVE-ONE-LEAGUE-OUT (same week-block bootstrap on the remaining leagues; mean [95% CI])')
    print(f"{'excluded':<9} {'N':>5} {'err_away':<32} {'diff (AWAY-HOME)':<32}")
    for c in comps:
        rs = [r for r in rows if r['comp'] != c]
        b, _ = block_bootstrap(rs, a.reps, random.Random(a.seed))
        m = {k: sum(r[k] for r in rs) / len(rs) for k in INDICATORS}
        print(f"{c:<9} {len(rs):>5} {fmt(m['err_away'], *b['err_away'][:2]):<32} {fmt(m['diff'], *b['diff'][:2]):<32}")


if __name__ == '__main__':
    main()
