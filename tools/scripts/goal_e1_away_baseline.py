"""
GOAL E1 (exploratory) - is the AWAY over-estimation specific to Candidate47?

Compares, on the SAME 1755 matches of 2025/26 used in goal_e1_bias_uncertainty.py:
  - Candidate47 (fit TRAIN-only 2024/25) lambda_away, from
      tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv
  - a constant per-league baseline = mean AWAY goals observed in 2024/25 only, from
      tools/datasets/dataset_core_v1_team_history_2024_2025.csv (2024/25 rows = kickoff before 2025-07-01)

Metric: marginal AWAY Poisson log-loss per match, -(y*ln(l) - l - ln(y!)); paired difference
(candidate - baseline): negative = Candidate47 better. Bootstrap by ISO calendar week of kickoff
(blocks = all matches of the week, all leagues), same seed/reps as goal_e1_bias_uncertainty.py.

No model, no fit, no lambda change, no DB/API. Standard library only. Writes nothing.
EXPLORATORY on 2025/26; not an independent confirmation.

Usage: py tools/scripts/goal_e1_away_baseline.py [--reps 2000] [--seed 20261031]
"""
import argparse
import csv
import math
import random
from collections import defaultdict
from datetime import datetime

LAMBDAS = 'tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv'
DATASET = 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv'
TRAIN_END = datetime.fromisoformat('2025-07-01T00:00:00+00:00')


def kick(s):
    return datetime.fromisoformat(s.replace('Z', '+00:00'))


def poisson_ll(y, lam):
    return -(y * math.log(lam) - lam - math.lgamma(y + 1))


def load_baseline():
    sums, cnt = defaultdict(float), defaultdict(int)
    with open(DATASET, newline='', encoding='utf-8') as f:
        for r in csv.DictReader(f):
            if kick(r['kickoff_at']) < TRAIN_END:
                c = int(r['competition_id'])
                sums[c] += float(r['label_away_goals'])
                cnt[c] += 1
    return {c: sums[c] / cnt[c] for c in cnt}, dict(cnt), sum(sums.values()) / sum(cnt.values())


def load_test(base):
    rows, seen = [], set()
    with open(LAMBDAS, newline='', encoding='utf-8') as f:
        for r in csv.DictReader(f):
            assert r['match_id'] not in seen
            seen.add(r['match_id'])
            c = int(r['competition_id'])
            y = float(r['away_goals'])
            lam, b = float(r['lambda_away']), base[c]
            rows.append({
                'comp': c, 'week': kick(r['kickoff_at']).isocalendar()[:2], 'y': y,
                'cand': lam, 'base': b,
                'll_cand': poisson_ll(y, lam), 'll_base': poisson_ll(y, b),
            })
    return rows


def boot_mean(rows, key, reps, rng):
    blocks = defaultdict(lambda: [0, 0.0])
    for r in rows:
        b = blocks[r['week']]
        b[0] += 1
        b[1] += r[key]
    bl = list(blocks.values())
    nb = len(bl)
    out = []
    for _ in range(reps):
        n, s = 0, 0.0
        for _ in range(nb):
            b = bl[rng.randrange(nb)]
            n += b[0]
            s += b[1]
        out.append(s / n)
    out.sort()
    return out[int(0.025 * reps)], out[int(0.975 * reps) - 1], nb


def mean(xs):
    return sum(xs) / len(xs)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--reps', type=int, default=2000)
    ap.add_argument('--seed', type=int, default=20261031)
    a = ap.parse_args()

    base, n_train, base_global = load_baseline()
    rows = load_test(base)
    for r in rows:
        r['d_ll'] = r['ll_cand'] - r['ll_base']
        r['err_cand'] = r['cand'] - r['y']
        r['err_base'] = r['base'] - r['y']
    print(f'2025/26 matches evaluated: N={len(rows)} | baseline built from 2024/25 only (N={sum(n_train.values())})')
    print('EXPLORATORY on 2025/26; not an independent confirmation.\n')

    obs_g = mean([r['y'] for r in rows])
    print('GLOBAL (AWAY)')
    print(f"{'':<12} {'pred mean':>10} {'obs mean':>9} {'bias':>8} {'PoisLL/match':>13}")
    print(f"{'Candidate47':<12} {mean([r['cand'] for r in rows]):10.4f} {obs_g:9.4f} {mean([r['err_cand'] for r in rows]):+8.4f} {mean([r['ll_cand'] for r in rows]):13.4f}")
    print(f"{'Baseline':<12} {mean([r['base'] for r in rows]):10.4f} {obs_g:9.4f} {mean([r['err_base'] for r in rows]):+8.4f} {mean([r['ll_base'] for r in rows]):13.4f}")

    lo, hi, nb = boot_mean(rows, 'd_ll', a.reps, random.Random(a.seed))
    print(f"\nPaired LogLoss difference (Candidate47 - baseline; negative = Candidate47 better): "
          f"{mean([r['d_ll'] for r in rows]):+.5f}  95% CI [{lo:+.5f}, {hi:+.5f}]  "
          f"(week-block bootstrap, {nb} blocks, {a.reps} reps, seed {a.seed})")

    print('\nPER LEAGUE (descriptive)')
    print(f"{'comp':<5} {'N':>4} {'obs 24/25':>9} {'obs 25/26':>9} {'d obs':>7} | {'cand pred':>9} {'cand bias':>9} | {'base pred':>9} {'base bias':>9} | {'LL cand':>7} {'LL base':>7} {'dLL':>8}")
    for c in sorted(base):
        rs = [r for r in rows if r['comp'] == c]
        o = mean([r['y'] for r in rs])
        print(f"{c:<5} {len(rs):>4} {base[c]:9.4f} {o:9.4f} {o - base[c]:+7.4f} | "
              f"{mean([r['cand'] for r in rs]):9.4f} {mean([r['err_cand'] for r in rs]):+9.4f} | "
              f"{base[c]:9.4f} {mean([r['err_base'] for r in rs]):+9.4f} | "
              f"{mean([r['ll_cand'] for r in rs]):7.4f} {mean([r['ll_base'] for r in rs]):7.4f} {mean([r['d_ll'] for r in rs]):+8.4f}")
    print(f"{'ALL':<5} {len(rows):>4} {base_global:9.4f} {obs_g:9.4f} {obs_g - base_global:+7.4f}   (2024/25 global = mean over its {sum(n_train.values())} matches)")


if __name__ == '__main__':
    main()
