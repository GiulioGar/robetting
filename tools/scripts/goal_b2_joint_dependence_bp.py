"""
GOAL B2 (exploratory) - effect of lambda3 = 0.15 on BTTS, exact score and draws.

Companion of goal_b1_total_goals_bp.py (same CSV, same 1755 matches, same conventions):
  tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv  (2025/26, fit on 2024/25 only)
  A. Bivariate Poisson, lambda3 = 0.15 (checked per row):  X = X1+X3, Y = X2+X3,
       X1~Pois(lh-l3), X2~Pois(la-l3), X3~Pois(l3)
       P(x,y) = sum_{k<=min(x,y)} Pois(k;l3) Pois(x-k;lh-l3) Pois(y-k;la-l3)
  B. Independent Poissons (l3 = 0)
lambda_home / lambda_away are identical in A and B (marginals Pois(lh), Pois(la) in both).

Computed per match:
  BTTS YES  closed form (exact, no grid): 1 - e^-lh - e^-la + e^-(lh+la-l3)   [B: l3=0]
            -> mean prob, observed frequency, LogLoss, Brier
  Exact score NLL of the observed (home_goals, away_goals): -ln P(x,y), exact sum over k, no grid
  1X2       from the joint pmf on a 0..G-1 x 0..G-1 grid (G = 40, NOT the production 0..10 matrix,
            NO renormalisation); the residual mass 1 - sum(grid) is reported.
            -> mean P(draw) vs observed draw frequency, full 1X2 LogLoss
  Sanity    A's 1X2 vs the p_home/p_draw/p_away columns of the CSV (max abs difference).
Paired differences A - B (negative = A better) with 95% CI from a bootstrap of ISO-calendar-week
blocks (2000 reps, seed 20261031). The inner sum over k is cut where Pois(k;l3) < 1e-18 (numerical
cut, covered by the residual-mass check).

No model, no fit, no lambda change, no tuning of lambda3, no DB/API. Standard library only.
Writes nothing. EXPLORATORY on 2025/26: not to be used to choose a model version.

Usage: py tools/scripts/goal_b2_joint_dependence_bp.py [--reps 2000] [--seed 20261031]
"""
import argparse
import csv
import math
import random
from collections import defaultdict
from datetime import datetime

CSV_PATH = 'tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv'
G = 40
EPS = 1e-18


def pois_arr(mu, n):
    if mu <= 0:
        return [1.0] + [0.0] * (n - 1)
    lm = math.log(mu)
    return [math.exp(k * lm - mu - math.lgamma(k + 1)) for k in range(n)]


def joint_grid(lh, la, l3):
    """P(x,y) on the 0..G-1 square. l3 = 0 -> independent Poissons."""
    p1, p2 = pois_arr(lh - l3, G), pois_arr(la - l3, G)
    if l3 <= 0:
        return [[p1[x] * p2[y] for y in range(G)] for x in range(G)]
    p3 = pois_arr(l3, G)
    kmax = max(k for k in range(G) if p3[k] >= EPS)
    return [[sum(p3[k] * p1[x - k] * p2[y - k] for k in range(min(x, y, kmax) + 1)) for y in range(G)] for x in range(G)]


def log_joint(x, y, lh, la, l3):
    """ln P(X=x, Y=y), exact, via log-sum-exp over k."""
    if l3 <= 0:
        return (x * math.log(lh) - lh - math.lgamma(x + 1)) + (y * math.log(la) - la - math.lgamma(y + 1))
    l1, l2 = lh - l3, la - l3
    terms = []
    for k in range(min(x, y) + 1):
        terms.append(k * math.log(l3) - l3 - math.lgamma(k + 1)
                     + (x - k) * math.log(l1) - l1 - math.lgamma(x - k + 1)
                     + (y - k) * math.log(l2) - l2 - math.lgamma(y - k + 1))
    m = max(terms)
    return m + math.log(sum(math.exp(t - m) for t in terms))


def p_btts(lh, la, l3):
    return 1.0 - math.exp(-lh) - math.exp(-la) + math.exp(-(lh + la - l3))


def result_probs(grid):
    ph = pd = pa = 0.0
    for x in range(G):
        for y in range(G):
            if x > y:
                ph += grid[x][y]
            elif x == y:
                pd += grid[x][y]
            else:
                pa += grid[x][y]
    return ph, pd, pa


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
    resid = {'A': 0.0, 'B': 0.0}
    max_btts_grid_diff = 0.0
    max_csv_diff = 0.0
    for r in csv.DictReader(open(CSV_PATH, newline='', encoding='utf-8')):
        assert r['match_id'] not in seen
        seen.add(r['match_id'])
        lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
        hg, ag = int(r['home_goals']), int(r['away_goals'])
        assert all(math.isfinite(v) for v in (lh, la, l3)) and lh - l3 > 0 and la - l3 > 0 and l3 >= 0, r['match_id']
        assert max(hg, ag) < G - 1, r['match_id']
        y_btts = 1 if (hg > 0 and ag > 0) else 0
        res = 0 if hg > ag else (1 if hg == ag else 2)  # 0 home, 1 draw, 2 away

        out = {'comp': int(r['competition_id']),
               'week': datetime.fromisoformat(r['kickoff_at'].replace('Z', '+00:00')).isocalendar()[:2],
               'btts': y_btts, 'draw': 1 if res == 1 else 0}
        for name, l3_ in (('A', l3), ('B', 0.0)):
            pb = p_btts(lh, la, l3_)
            grid = joint_grid(lh, la, l3_)
            ph, pdr, pa = result_probs(grid)
            resid[name] = max(resid[name], abs(1.0 - (ph + pdr + pa)))
            # cross-check closed-form BTTS against the grid
            g_btts = sum(grid[x][y] for x in range(1, G) for y in range(1, G))
            max_btts_grid_diff = max(max_btts_grid_diff, abs(g_btts - pb))
            probs = (ph, pdr, pa)
            if name == 'A':
                max_csv_diff = max(max_csv_diff, abs(ph - float(r['p_home'])), abs(pdr - float(r['p_draw'])), abs(pa - float(r['p_away'])))
            k = name.lower()
            out[f'pb_{k}'] = pb
            out[f'pd_{k}'] = pdr
            out[f'll_btts_{k}'] = -math.log(pb if y_btts else 1.0 - pb)
            out[f'br_btts_{k}'] = (pb - y_btts) ** 2
            out[f'nll_score_{k}'] = -log_joint(hg, ag, lh, la, l3_)
            out[f'll_1x2_{k}'] = -math.log(probs[res])
        for m in ('ll_btts', 'br_btts', 'nll_score', 'll_1x2'):
            out[f'd_{m}'] = out[f'{m}_a'] - out[f'{m}_b']
        rows.append(out)

    n = len(rows)
    print(f'N={n} | unique match_id OK | all parameters valid | lambda3 in CSV: 0.15 on all rows')
    print(f'Grid 0..{G-1}: residual mass max |1 - sum| A={resid["A"]:.2e}, B={resid["B"]:.2e} | '
          f'closed-form BTTS vs grid max diff {max_btts_grid_diff:.2e} | A 1X2 vs CSV p_* (production 0..10, renormalised) max abs diff {max_csv_diff:.2e}')
    print('EXPLORATORY on 2025/26; not to be used to choose a model version.\n')

    print('1) BTTS YES')
    print(f"{'':<26} {'mean P':>8} {'obs freq':>9} {'LogLoss':>9} {'Brier':>8}")
    obs_btts = mean([r['btts'] for r in rows])
    for lab, k in (('A  BP lambda3=0.15', 'a'), ('B  independent', 'b')):
        print(f"{lab:<26} {mean([r['pb_' + k] for r in rows]):8.4f} {obs_btts:9.4f} {mean([r['ll_btts_' + k] for r in rows]):9.5f} {mean([r['br_btts_' + k] for r in rows]):8.5f}")

    print('\n2) EXACT SCORE  NLL of the observed (home, away) pair')
    print(f"   A {mean([r['nll_score_a'] for r in rows]):.5f}   B {mean([r['nll_score_b'] for r in rows]):.5f}")

    print('\n3) 1X2 and DRAW')
    obs_draw = mean([r['draw'] for r in rows])
    print(f"   mean P(draw): A {mean([r['pd_a'] for r in rows]):.4f}  B {mean([r['pd_b'] for r in rows]):.4f}  | observed draw frequency {obs_draw:.4f}")
    print(f"   full 1X2 LogLoss: A {mean([r['ll_1x2_a'] for r in rows]):.5f}  B {mean([r['ll_1x2_b'] for r in rows]):.5f}")

    print('\n4) PAIRED DIFFERENCES A - B (negative = A better), 95% CI week-block bootstrap')
    for key, lab in (('d_ll_btts', 'BTTS LogLoss'), ('d_br_btts', 'BTTS Brier'), ('d_nll_score', 'Exact-score NLL'), ('d_ll_1x2', '1X2 LogLoss')):
        lo, hi, nb = boot_ci(rows, key, a.reps, random.Random(a.seed))
        print(f"   {lab:<16} {mean([r[key] for r in rows]):+.5f}  [{lo:+.5f}, {hi:+.5f}]")
    print(f"   ({nb} blocks, {a.reps} reps, seed {a.seed}; main LogLoss/NLL comparisons: BTTS LogLoss, exact-score NLL, 1X2 LogLoss)")


if __name__ == '__main__':
    main()
