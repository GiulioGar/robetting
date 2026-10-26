"""Offline GOAL Step 5: Over/Under calibration from the exported TEST lambdas (no retraining).
P(T=t) = sum_k Poisson(k; l3) * Poisson(t-2k; l1+l2), l1=lH-l3, l2=lA-l3 (exact, as in Step 4).
P(Over L) = 1 - P(T <= floor(L)).
"""
import csv
import math
from collections import defaultdict
from pathlib import Path

CSV_PATH = Path(__file__).resolve().parents[2] / 'tools' / 'datasets' / 'candidate47_team_history_test_lambdas_2025_26.csv'
LINES = [0.5, 1.5, 2.5, 3.5, 4.5]


def pois(k, lam):
    return math.exp(-lam + k * math.log(lam) - math.lgamma(k + 1)) if lam > 0 else (1.0 if k == 0 else 0.0)


def p_total(t, l1, l2, l3):
    return sum(pois(k, l3) * pois(t - 2 * k, l1 + l2) for k in range(t // 2 + 1))


rows = list(csv.DictReader(open(CSV_PATH, encoding='utf-8')))
data = []   # (comp, total_goals, {line: p_over})
for r in rows:
    lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
    l1, l2 = lh - l3, la - l3
    cdf, acc = [], 0.0
    for t in range(5):
        acc += p_total(t, l1, l2, l3)
        cdf.append(acc)
    data.append((r['competition_id'], int(r['home_goals']) + int(r['away_goals']),
                 {L: 1.0 - cdf[int(math.floor(L))] for L in LINES}))


def metrics(sub, L):
    n = len(sub)
    ll = br = 0.0
    for _, t, p in sub:
        y = 1 if t > L else 0
        pp = min(max(p[L], 1e-12), 1 - 1e-12)
        ll += -(y * math.log(pp) + (1 - y) * math.log(1 - pp))
        br += (p[L] - y) ** 2
    obs = sum(1 for _, t, _ in sub if t > L) / n
    pred = sum(p[L] for _, _, p in sub) / n
    return n, ll / n, br / n, obs, pred


print('=== OVERALL (TEST, N=%d) ===' % len(data))
print(f"{'line':>6} {'obs %':>8} {'pred %':>8} {'diff pp':>8} {'LogLoss':>9} {'Brier':>8}")
for L in LINES:
    n, ll, br, obs, pred = metrics(data, L)
    print(f"O{L:>4} {obs * 100:>8.2f} {pred * 100:>8.2f} {(obs - pred) * 100:>+8.2f} {ll:>9.4f} {br:>8.4f}")

by = defaultdict(list)
for d in data:
    by[d[0]].append(d)
print('\n=== PER COMPETITION: obs % / pred % / diff pp  (LogLoss, Brier) ===')
for L in LINES:
    print(f'\nOver {L}')
    print(f"{'comp':>5} {'N':>5} {'obs %':>7} {'pred %':>7} {'diff pp':>8} {'LogLoss':>8} {'Brier':>7}")
    for c in sorted(by):
        n, ll, br, obs, pred = metrics(by[c], L)
        print(f"{c:>5} {n:>5} {obs * 100:>7.2f} {pred * 100:>7.2f} {(obs - pred) * 100:>+8.2f} {ll:>8.4f} {br:>7.4f}")

for L in (2.5, 3.5):
    ordered = sorted(data, key=lambda d: d[2][L])
    n = len(ordered)
    print(f'\n=== CALIBRATION Over {L}: 5 quantile groups (equal N) ===')
    print(f"{'grp':>4} {'N':>5} {'p range':>15} {'mean p %':>9} {'obs %':>7} {'diff pp':>8} {'SE pp':>6}")
    for g in range(5):
        lo, hi = g * n // 5, (g + 1) * n // 5
        grp = ordered[lo:hi]
        ps = [d[2][L] for d in grp]
        mp = sum(ps) / len(ps)
        ob = sum(1 for d in grp if d[1] > L) / len(grp)
        se = math.sqrt(mp * (1 - mp) / len(grp)) * 100
        print(f"{g + 1:>4} {len(grp):>5} {min(ps):>6.3f}-{max(ps):<7.3f} {mp * 100:>9.2f} {ob * 100:>7.2f} {(ob - mp) * 100:>+8.2f} {se:>6.2f}")
