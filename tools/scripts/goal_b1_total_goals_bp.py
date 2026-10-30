"""
GOAL B1 (exploratory) - does the Bivariate Poisson dependence improve the predictive
distribution of TOTAL goals, keeping Candidate47's lambdas fixed?

Input ONLY: tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv
(2025/26, lambdas from the fit on 2024/25 only; N=1755).

  A. Bivariate Poisson, lambda3 = 0.15 (value stored in the CSV, checked per row):
       X = X1 + X3, Y = X2 + X3, X1~Pois(lh-l3), X2~Pois(la-l3), X3~Pois(l3)
       T = X + Y = Pois(lh + la - 2*l3) + 2*Pois(l3)
       P(T=t) = sum_k Pois(k; l3) * Pois(t - 2k; lh + la - 2*l3)
  B. Independent Poissons (l3 = 0): T ~ Pois(lh + la).
Both have E[T] = lh + la; only the dispersion/shape differs.

Exact pmf on t = 0..M-1 (M = 80) in log space, no 0..10 truncation and no renormalisation;
the residual mass 1 - sum(pmf) is reported (max over matches).
Scores per match (observed T = home_goals + away_goals):
  NLL = -ln P(T = t_obs)
  RPS = sum_{k=0}^{M-2} (F_pred(k) - 1[t_obs <= k])^2   (ordinal, support unbounded: NOT divided by K-1)
Paired difference = A - B (negative = A better), 95% CI by bootstrap of ISO calendar-week
blocks (2000 reps, seed 20261031).

No model, no fit, no lambda change, no search for lambda3, no DB/API. Standard library only.
Writes nothing. EXPLORATORY on 2025/26: no model promotion from this comparison.

Usage: py tools/scripts/goal_b1_total_goals_bp.py [--reps 2000] [--seed 20261031]
"""
import argparse
import csv
import math
import random
from collections import defaultdict
from datetime import datetime

CSV_PATH = 'tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv'
M = 80  # pmf support 0..M-1


def log_pois(k, mu):
    return k * math.log(mu) - mu - math.lgamma(k + 1)


def pmf_independent(lam):
    return [math.exp(log_pois(t, lam)) for t in range(M)]


def pmf_bivariate(lh, la, l3):
    mu = lh + la - 2.0 * l3
    pm = [log_pois(t, mu) for t in range(M)]
    out = []
    for t in range(M):
        s = 0.0
        for k in range(t // 2 + 1):
            s += math.exp(log_pois(k, l3) + pm[t - 2 * k])
        out.append(s)
    return out


def scores(pmf, t_obs):
    nll = -math.log(pmf[t_obs])
    cum, rps = 0.0, 0.0
    for k in range(M - 1):
        cum += pmf[k]
        rps += (cum - (1.0 if t_obs <= k else 0.0)) ** 2
    return nll, rps


def mean(xs):
    return sum(xs) / len(xs)


def boot_ci(rows, key, reps, rng):
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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--reps', type=int, default=2000)
    ap.add_argument('--seed', type=int, default=20261031)
    a = ap.parse_args()

    rows, seen = [], set()
    max_resid = {'A': 0.0, 'B': 0.0}
    min_resid = {'A': 1.0, 'B': 1.0}
    with open(CSV_PATH, newline='', encoding='utf-8') as f:
        for r in csv.DictReader(f):
            assert r['match_id'] not in seen
            seen.add(r['match_id'])
            lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
            hg, ag = int(r['home_goals']), int(r['away_goals'])
            # parameter validity: finite, positive, lambda_i - lambda3 > 0, integer goals >= 0
            assert all(math.isfinite(v) for v in (lh, la, l3)), r['match_id']
            assert lh > 0 and la > 0 and l3 >= 0, r['match_id']
            assert lh - l3 > 0 and la - l3 > 0, r['match_id']
            assert hg >= 0 and ag >= 0 and hg + ag < M - 1, r['match_id']
            t = hg + ag
            pa, pb = pmf_bivariate(lh, la, l3), pmf_independent(lh + la)
            for name, p in (('A', pa), ('B', pb)):
                resid = 1.0 - sum(p)
                max_resid[name] = max(max_resid[name], resid)
                min_resid[name] = min(min_resid[name], resid)
            nll_a, rps_a = scores(pa, t)
            nll_b, rps_b = scores(pb, t)
            rows.append({
                'comp': int(r['competition_id']), 'week': datetime.fromisoformat(r['kickoff_at'].replace('Z', '+00:00')).isocalendar()[:2],
                't': t, 'pa': pa, 'pb': pb, 'l3': l3,
                'nll_a': nll_a, 'nll_b': nll_b, 'rps_a': rps_a, 'rps_b': rps_b,
                'd_nll': nll_a - nll_b, 'd_rps': rps_a - rps_b,
            })

    print(f'N={len(rows)} | unique match_id OK | all parameters valid | lambda3 values in CSV: {sorted({r["l3"] for r in rows})}')
    print(f'Residual probability mass beyond t={M-1}: A max={max_resid["A"]:.2e} (min {min_resid["A"]:.2e}), B max={max_resid["B"]:.2e} (min {min_resid["B"]:.2e})')
    print('EXPLORATORY on 2025/26; no model promotion from this comparison.\n')

    print('1) GLOBAL SCORES (mean per match)')
    print(f"{'':<28} {'NLL(T)':>9} {'RPS':>9}")
    print(f"{'A  BP lambda3=0.15':<28} {mean([r['nll_a'] for r in rows]):9.5f} {mean([r['rps_a'] for r in rows]):9.5f}")
    print(f"{'B  independent (l3=0)':<28} {mean([r['nll_b'] for r in rows]):9.5f} {mean([r['rps_b'] for r in rows]):9.5f}")
    print('Paired difference A - B (negative = A better), 95% CI = week-block bootstrap:')
    for key, lab in (('d_nll', 'NLL(T)'), ('d_rps', 'RPS')):
        lo, hi, nb = boot_ci(rows, key, a.reps, random.Random(a.seed))
        print(f"  {lab:<7} {mean([r[key] for r in rows]):+.5f}  [{lo:+.5f}, {hi:+.5f}]  ({nb} blocks, {a.reps} reps, seed {a.seed})")

    print('\n2) DISTRIBUTION OF T (observed frequency vs mean predicted probability)')
    print(f"{'T':<4} {'observed':>9} {'A (BP)':>9} {'B (indep)':>10} {'A-obs':>8} {'B-obs':>8}")
    n = len(rows)
    for lab, lo_, hi_ in (('0', 0, 0), ('1', 1, 1), ('2', 2, 2), ('3', 3, 3), ('4', 4, 4), ('5+', 5, M - 1)):
        o = sum(1 for r in rows if lo_ <= r['t'] <= hi_) / n
        pa_ = mean([sum(r['pa'][lo_:hi_ + 1]) for r in rows])
        pb_ = mean([sum(r['pb'][lo_:hi_ + 1]) for r in rows])
        print(f"{lab:<4} {o:9.4f} {pa_:9.4f} {pb_:10.4f} {pa_ - o:+8.4f} {pb_ - o:+8.4f}")
    print(f"mean T: observed {mean([r['t'] for r in rows]):.4f}; predicted (both) {mean([sum(k * p for k, p in enumerate(r['pa'])) for r in rows]):.4f} / {mean([sum(k * p for k, p in enumerate(r['pb'])) for r in rows]):.4f}")

    print('\n3) PER LEAGUE (descriptive: N and mean paired difference A - B)')
    print(f"{'comp':<5} {'N':>5} {'dNLL':>9} {'dRPS':>9}")
    for c in sorted({r['comp'] for r in rows}):
        rs = [r for r in rows if r['comp'] == c]
        print(f"{c:<5} {len(rs):>5} {mean([r['d_nll'] for r in rs]):+9.5f} {mean([r['d_rps'] for r in rs]):+9.5f}")


if __name__ == '__main__':
    main()
