"""Offline GOAL Step 6: constant Over/Under baselines from TRAIN 2024/25 frequencies vs Candidate47 on the TEST 2025/26.
TRAIN = dataset_core_v1_team_history_2024_2025.csv rows with season_id in 28..32 (original split);
TEST  = exported TEST lambda CSV (1755 matches). Baseline probabilities use TRAIN outcomes only.
"""
import csv
import math
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / 'tools' / 'datasets'
TRAIN_SEASONS = {'28', '29', '30', '31', '32'}
LINES = [0.5, 1.5, 2.5, 3.5, 4.5]


def pois(k, lam):
    return math.exp(-lam + k * math.log(lam) - math.lgamma(k + 1)) if lam > 0 else (1.0 if k == 0 else 0.0)


def p_total(t, l1, l2, l3):
    return sum(pois(k, l3) * pois(t - 2 * k, l1 + l2) for k in range(t // 2 + 1))


# ---- TRAIN (frequencies only) ----
ds = list(csv.DictReader(open(ROOT / 'dataset_core_v1_team_history_2024_2025.csv', encoding='utf-8')))
train = [r for r in ds if r['season_id'] in TRAIN_SEASONS and r['label_home_goals'] != '' and r['label_away_goals'] != '']
train_tot = [(r['competition_id'], int(float(r['label_home_goals'])) + int(float(r['label_away_goals']))) for r in train]
print(f'TRAIN rows: {len(train_tot)}  seasons {sorted({r["season_id"] for r in train})}')

glob = {L: sum(1 for _, t in train_tot if t > L) / len(train_tot) for L in LINES}
by_comp_train = defaultdict(list)
for c, t in train_tot:
    by_comp_train[c].append(t)
comp = {c: {L: sum(1 for t in ts if t > L) / len(ts) for L in LINES} for c, ts in by_comp_train.items()}

# ---- TEST ----
test = list(csv.DictReader(open(ROOT / 'candidate47_team_history_test_lambdas_2025_26.csv', encoding='utf-8')))
test_comps = Counter(r['competition_id'] for r in test)
train_comps = Counter(c for c, _ in train_tot)
print('TRAIN by competition_id:', dict(sorted(train_comps.items())))
print('TEST  by competition_id:', dict(sorted(test_comps.items())))
missing = sorted(set(test_comps) - set(train_comps))
print('TEST competition_id absent from TRAIN (would be excluded):', missing or 'none')
print(f'TEST N used: {len(test)} (no exclusions)' if not missing else 'EXCLUSIONS PRESENT')

data = []
for r in test:
    lh, la, l3 = float(r['lambda_home']), float(r['lambda_away']), float(r['lambda3'])
    l1, l2 = lh - l3, la - l3
    acc, cdf = 0.0, []
    for t in range(5):
        acc += p_total(t, l1, l2, l3)
        cdf.append(acc)
    tot = int(r['home_goals']) + int(r['away_goals'])
    data.append((r['competition_id'], tot, {L: 1.0 - cdf[int(math.floor(L))] for L in LINES}))

print('\nTRAIN frequencies (Over %): global', {L: round(glob[L] * 100, 2) for L in LINES})
for c in sorted(comp):
    print(f'  comp {c} (N={train_comps[c]})', {L: round(comp[c][L] * 100, 2) for L in LINES})


def score(probs_fn, L):
    ll = br = 0.0
    for c, t, p in data:
        y = 1 if t > L else 0
        q = min(max(probs_fn(c, p, L), 1e-12), 1 - 1e-12)
        ll += -(y * math.log(q) + (1 - y) * math.log(1 - q))
        br += (q - y) ** 2
    n = len(data)
    return ll / n, br / n


methods = {
    'Candidate47': lambda c, p, L: p[L],
    'Base global': lambda c, p, L: glob[L],
    'Base per-comp': lambda c, p, L: comp[c][L],
}
res = {m: {L: score(f, L) for L in LINES} for m, f in methods.items()}

print('\n=== LogLoss / Brier on the same 1755 TEST matches ===')
print(f"{'line':>6} | {'Cand47 LL':>9} {'Glob LL':>8} {'Comp LL':>8} | {'Cand47 Br':>9} {'Glob Br':>8} {'Comp Br':>8}")
for L in LINES:
    print(f"O{L:>4} | {res['Candidate47'][L][0]:>9.4f} {res['Base global'][L][0]:>8.4f} {res['Base per-comp'][L][0]:>8.4f} | "
          f"{res['Candidate47'][L][1]:>9.4f} {res['Base global'][L][1]:>8.4f} {res['Base per-comp'][L][1]:>8.4f}")

print('\n=== Baseline minus Candidate47 (positive = baseline worse); % relative to Candidate47 ===')
print(f"{'line':>6} | {'metric':>7} | {'global abs':>11} {'global %':>9} | {'per-comp abs':>12} {'per-comp %':>10} | best")
for L in LINES:
    for i, name in enumerate(['LogLoss', 'Brier']):
        c = res['Candidate47'][L][i]
        g = res['Base global'][L][i]
        k = res['Base per-comp'][L][i]
        best = min((c, 'Cand47'), (g, 'Global'), (k, 'PerComp'))[1]
        print(f"O{L:>4} | {name:>7} | {g - c:>+11.5f} {(g / c - 1) * 100:>+8.2f}% | {k - c:>+12.5f} {(k / c - 1) * 100:>+9.2f}% | {best}")
