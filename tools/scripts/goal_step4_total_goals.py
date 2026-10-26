"""Offline GOAL Step 4: observed vs predicted FT total-goals distribution (TEST 2025/26).

Reads ONLY tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv.
Total goals T = X1 + X2 + 2*X3 under the existing Bivariate Poisson (X = X1+X3, Y = X2+X3):
  lambda1 = lambda_home - lambda3, lambda2 = lambda_away - lambda3, X3 ~ Poisson(lambda3)
  P(T=t) = sum_{k=0}^{floor(t/2)} Poisson(k; lambda3) * Poisson(t-2k; lambda1+lambda2)   (exact, no matrix truncation)
Per match: E[T] = lambda_home + lambda_away ; Var[T] = lambda1 + lambda2 + 4*lambda3.
Aggregate predictive variance (law of total variance, population form, ddof=0):
  E_i[Var_i] + Var_i(E_i[T]).
"""
import csv
import math
from collections import defaultdict
from pathlib import Path

CSV_PATH = Path(__file__).resolve().parents[2] / 'tools' / 'datasets' / 'candidate47_team_history_test_lambdas_2025_26.csv'


def pois(k, lam):
    return math.exp(-lam + k * math.log(lam) - math.lgamma(k + 1)) if lam > 0 else (1.0 if k == 0 else 0.0)


def p_total(t, l1, l2, l3):
    s = 0.0
    for k in range(t // 2 + 1):
        s += pois(k, l3) * pois(t - 2 * k, l1 + l2)
    return s


def mean(xs):
    return sum(xs) / len(xs)


def var0(xs):
    m = mean(xs)
    return sum((x - m) ** 2 for x in xs) / len(xs)


def summarise(rows):
    n = len(rows)
    obs = [int(r['home_goals']) + int(r['away_goals']) for r in rows]
    mu, vi = [], []
    probs = [0.0] * 6                       # 0,1,2,3,4,5+
    for r in rows:
        lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
        l1, l2 = lh - l3, la - l3
        mu.append(lh + la)
        vi.append(l1 + l2 + 4 * l3)
        ps = [p_total(t, l1, l2, l3) for t in range(5)]
        for t in range(5):
            probs[t] += ps[t]
        probs[5] += 1.0 - sum(ps)
    exp_freq = [p / n for p in probs]
    obs_freq = [sum(1 for o in obs if (o == t if t < 5 else o >= 5)) / n for t in range(6)]
    return {
        'n': n,
        'obs_mean': mean(obs), 'pred_mean': mean(mu),
        'obs_var': var0(obs), 'pred_var': mean(vi) + var0(mu),
        'within': mean(vi), 'between': var0(mu),
        'obs_freq': obs_freq, 'exp_freq': exp_freq,
    }


def show(title, s):
    print(f"\n=== {title} ===")
    print(f"N={s['n']}  mean obs={s['obs_mean']:.4f}  mean pred={s['pred_mean']:.4f}  (diff {s['obs_mean'] - s['pred_mean']:+.4f})")
    print(f"var obs={s['obs_var']:.4f}  var pred (aggregate)={s['pred_var']:.4f}  = within {s['within']:.4f} + between {s['between']:.4f}"
          f"   ratio obs/pred={s['obs_var'] / s['pred_var']:.4f}")
    print(f"{'goals':>6} {'N obs':>7} {'obs %':>8} {'exp %':>8} {'diff pp':>8} {'SE obs pp':>10}")
    for t, lab in enumerate(['0', '1', '2', '3', '4', '5+']):
        o, e = s['obs_freq'][t], s['exp_freq'][t]
        se = math.sqrt(e * (1 - e) / s['n']) * 100      # binomial SE under the predicted frequency (context only)
        print(f"{lab:>6} {round(o * s['n']):>7} {o * 100:>8.2f} {e * 100:>8.2f} {(o - e) * 100:>+8.2f} {se:>10.2f}")


rows = list(csv.DictReader(open(CSV_PATH, encoding='utf-8')))
show('ALL', summarise(rows))
by = defaultdict(list)
for r in rows:
    by[r['competition_id']].append(r)

print('\n=== PER COMPETITION: summary ===')
print(f"{'comp':>5} {'N':>5} {'mean obs':>9} {'mean pred':>10} {'var obs':>8} {'var pred':>9} | diff pp (obs-exp):  0     1     2     3     4    5+")
per = {}
for c in sorted(by):
    s = summarise(by[c])
    per[c] = s
    d = ' '.join(f"{(o - e) * 100:+5.1f}" for o, e in zip(s['obs_freq'], s['exp_freq']))
    print(f"{c:>5} {s['n']:>5} {s['obs_mean']:>9.4f} {s['pred_mean']:>10.4f} {s['obs_var']:>8.4f} {s['pred_var']:>9.4f} | {d}")

for c in sorted(by):
    show(f'competition_id={c}', per[c])

# Anomalies / sanity
print('\n=== SANITY ===')
for r_ in rows[:1]:
    pass
sums = []
for r in rows:
    lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
    l1, l2 = lh - l3, la - l3
    sums.append(sum(p_total(t, l1, l2, l3) for t in range(0, 60)))
print('sum_{t<60} P(T=t) per match: min %.12f max %.12f' % (min(sums), max(sums)))
mus = [float(r['lambda_home']) + float(r['lambda_away']) for r in rows]
print('predicted E[T] per match: min %.3f max %.3f ; matches with E[T]>5: %d' % (min(mus), max(mus), sum(1 for m in mus if m > 5)))
obs_all = [int(r['home_goals']) + int(r['away_goals']) for r in rows]
print('observed max total goals', max(obs_all), '; count >=7:', sum(1 for o in obs_all if o >= 7))
