"""
GOAL E1 (exploratory) - HOME, total goals and home advantage, 2024/25 vs 2025/26.

Same universe and bootstrap logic as goal_e1_away_baseline.py (Step 28):
  - 2025/26 matches + Candidate47 (fit TRAIN-only 2024/25) lambdas:
      tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv  (N=1755)
  - 2024/25 matches (kickoff before 2025-07-01) from
      tools/datasets/dataset_core_v1_team_history_2024_2025.csv          (N=1756)
  - HOME baseline = constant per league, mean HOME goals of 2024/25 only.

Metrics: observed HOME/AWAY/TOTAL/HOME-AWAY by season (global + league, with N so composition
differences are visible), HOME bias and marginal Poisson log-loss for Candidate47 vs baseline,
paired log-loss difference (candidate - baseline; negative = Candidate47 better) with a bootstrap
by ISO calendar week of kickoff (2000 reps, seed 20261031), and predicted vs observed HOME-AWAY.

No model, no fit, no lambda change, no DB/API. Standard library only. Writes nothing.
EXPLORATORY on 2025/26; not an independent confirmation.

Usage: py tools/scripts/goal_e1_home_total_advantage.py [--reps 2000] [--seed 20261031]
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


def mean(xs):
    return sum(xs) / len(xs)


def load_train():
    rows = []
    with open(DATASET, newline='', encoding='utf-8') as f:
        for r in csv.DictReader(f):
            if kick(r['kickoff_at']) < TRAIN_END:
                rows.append({'comp': int(r['competition_id']),
                             'h': float(r['label_home_goals']), 'a': float(r['label_away_goals'])})
    return rows


def load_test(base_home):
    rows, seen = [], set()
    with open(LAMBDAS, newline='', encoding='utf-8') as f:
        for r in csv.DictReader(f):
            assert r['match_id'] not in seen
            seen.add(r['match_id'])
            c = int(r['competition_id'])
            h, a = float(r['home_goals']), float(r['away_goals'])
            lh, la = float(r['lambda_home']), float(r['lambda_away'])
            rows.append({
                'comp': c, 'week': kick(r['kickoff_at']).isocalendar()[:2], 'h': h, 'a': a,
                'lh': lh, 'la': la, 'bh': base_home[c],
                'err_cand': lh - h, 'err_base': base_home[c] - h,
                'll_cand': poisson_ll(h, lh), 'll_base': poisson_ll(h, base_home[c]),
                'gap_err': (lh - la) - (h - a),  # predicted - observed (HOME-AWAY)
            })
    for r in rows:
        r['d_ll'] = r['ll_cand'] - r['ll_base']
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


def season_line(label, rs):
    h, a = mean([r['h'] for r in rs]), mean([r['a'] for r in rs])
    return h, a, h + a, h - a


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--reps', type=int, default=2000)
    ap.add_argument('--seed', type=int, default=20261031)
    a = ap.parse_args()

    train = load_train()
    comps = sorted({r['comp'] for r in train})
    base_home = {c: mean([r['h'] for r in train if r['comp'] == c]) for c in comps}
    test = load_test(base_home)
    print(f'2024/25: N={len(train)} | 2025/26: N={len(test)} | HOME baseline from 2024/25 only')
    print('EXPLORATORY on 2025/26; not an independent confirmation.\n')

    print('1) OBSERVED GOALS BY SEASON (per match)')
    print(f"{'comp':<6} {'N 24/25':>7} {'N 25/26':>7} | {'HOME 24/25':>10} {'HOME 25/26':>10} {'d':>7} | {'AWAY 24/25':>10} {'AWAY 25/26':>10} {'d':>7} | "
          f"{'TOT 24/25':>9} {'TOT 25/26':>9} {'d':>7} | {'H-A 24/25':>9} {'H-A 25/26':>9} {'d':>7}")
    n_tr, n_te = defaultdict(int), defaultdict(int)
    std = {k: 0.0 for k in ('h', 'a', 't', 'g')}  # change standardised to the 2025/26 league mix
    for c in comps + ['ALL']:
        tr = train if c == 'ALL' else [r for r in train if r['comp'] == c]
        te = test if c == 'ALL' else [r for r in test if r['comp'] == c]
        s1, s2 = season_line('24', tr), season_line('25', te)
        print(f"{c!s:<6} {len(tr):>7} {len(te):>7} | {s1[0]:10.4f} {s2[0]:10.4f} {s2[0] - s1[0]:+7.4f} | {s1[1]:10.4f} {s2[1]:10.4f} {s2[1] - s1[1]:+7.4f} | "
              f"{s1[2]:9.4f} {s2[2]:9.4f} {s2[2] - s1[2]:+9.4f} | {s1[3]:9.4f} {s2[3]:9.4f} {s2[3] - s1[3]:+7.4f}")
        if c != 'ALL':
            for k, i in (('h', 0), ('a', 1), ('t', 2), ('g', 3)):
                std[k] += (s2[i] - s1[i]) * len(te) / len(test)
    print(f"Composition note: league mix differs only by league 19 (N 308 in 2024/25 vs 307 in 2025/26). Change standardised to the 2025/26 mix: "
          f"HOME {std['h']:+.4f}  AWAY {std['a']:+.4f}  TOTAL {std['t']:+.4f}  H-A {std['g']:+.4f}.")
    print('Leagues 18/19 hold 308 matches in 2024/25 = 306 + 2 playoff matches presumably (not identifiable from these CSVs: no stage column);')
    print('2025/26 league 19 has 307 (one regular-season match missing, see checkpoint 2026-10-26). Playoffs are included in all means above.\n')

    print('2) HOME: Candidate47 vs constant per-league baseline (2025/26)')
    print(f"{'':<12} {'pred mean':>10} {'obs mean':>9} {'bias':>8} {'PoisLL/match':>13}")
    obs = mean([r['h'] for r in test])
    print(f"{'Candidate47':<12} {mean([r['lh'] for r in test]):10.4f} {obs:9.4f} {mean([r['err_cand'] for r in test]):+8.4f} {mean([r['ll_cand'] for r in test]):13.4f}")
    print(f"{'Baseline':<12} {mean([r['bh'] for r in test]):10.4f} {obs:9.4f} {mean([r['err_base'] for r in test]):+8.4f} {mean([r['ll_base'] for r in test]):13.4f}")
    lo, hi, nb = boot_mean(test, 'd_ll', a.reps, random.Random(a.seed))
    print(f"Paired LogLoss difference (Candidate47 - baseline; negative = Candidate47 better): {mean([r['d_ll'] for r in test]):+.5f}  "
          f"95% CI [{lo:+.5f}, {hi:+.5f}]  (week-block bootstrap, {nb} blocks, {a.reps} reps, seed {a.seed})\n")

    print(f"{'comp':<5} {'N':>4} {'obs 24/25':>9} {'obs 25/26':>9} | {'cand pred':>9} {'cand bias':>9} | {'base bias':>9} | {'LL cand':>7} {'LL base':>7} {'dLL':>8}")
    for c in comps:
        rs = [r for r in test if r['comp'] == c]
        print(f"{c:<5} {len(rs):>4} {base_home[c]:9.4f} {mean([r['h'] for r in rs]):9.4f} | {mean([r['lh'] for r in rs]):9.4f} {mean([r['err_cand'] for r in rs]):+9.4f} | "
              f"{mean([r['err_base'] for r in rs]):+9.4f} | {mean([r['ll_cand'] for r in rs]):7.4f} {mean([r['ll_base'] for r in rs]):7.4f} {mean([r['d_ll'] for r in rs]):+8.4f}")

    print('\n3) HOME-AWAY (home advantage in goals), 2025/26: Candidate47 predicted vs observed')
    print(f"{'comp':<6} {'N':>5} {'pred H-A':>9} {'obs H-A':>8} {'pred-obs':>9}")
    for c in comps + ['ALL']:
        rs = test if c == 'ALL' else [r for r in test if r['comp'] == c]
        p, o = mean([r['lh'] - r['la'] for r in rs]), mean([r['h'] - r['a'] for r in rs])
        print(f"{c!s:<6} {len(rs):>5} {p:9.4f} {o:8.4f} {p - o:+9.4f}")
    lo, hi, _ = boot_mean(test, 'gap_err', a.reps, random.Random(a.seed))
    print(f"Global pred-obs (HOME-AWAY): {mean([r['gap_err'] for r in test]):+.4f}  95% CI [{lo:+.4f}, {hi:+.4f}]  (same week-block bootstrap)")
    print(f"Baseline-implied HOME-AWAY on the same matches (constant per league): {mean([r['bh'] - (sum(x['a'] for x in train if x['comp'] == r['comp']) / sum(1 for x in train if x['comp'] == r['comp'])) for r in test]):.4f}")


if __name__ == '__main__':
    main()
