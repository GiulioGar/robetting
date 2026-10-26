import pickle
from pathlib import Path

SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')

with open(SCRATCH / 'p16a_preds.pkl', 'rb') as f:
    d = pickle.load(f)

rows = d['rows']
test_mask = d['test_mask']
X48extra = d['X48extra']
preds_44 = d['preds_44']
preds_48 = d['preds_48']

team_names = {}
with open(SCRATCH / 'team_club_map.tsv', encoding='utf-8') as f:
    for line in f:
        tid, name, comp, cid = line.rstrip('\n').split('\t')
        team_names[tid] = name

test_idx = [i for i in range(len(rows)) if test_mask[i]]

candidates = []
for i in test_idx:
    sh, sa, sgap, sgap2 = X48extra[i]
    if sh != sh:  # NaN
        continue
    hn = team_names.get(rows[i]['home_team_id'], rows[i]['home_team_id'])
    an = team_names.get(rows[i]['away_team_id'], rows[i]['away_team_id'])
    candidates.append((i, hn, an, sh, sa, sgap))

# priority: matches featuring Real Madrid / Napoli / Lazio
priority_teams = {'Real Madrid', 'Napoli', 'Lazio'}
priority = [c for c in candidates if c[1] in priority_teams or c[2] in priority_teams]
priority.sort(key=lambda c: -abs(c[5]))

by_gap = sorted(candidates, key=lambda c: -abs(c[5]))
n = len(by_gap)


def pick_bucket(items, lo, hi):
    return [c for c in items if lo <= abs(c[5]) <= hi]


gaps = sorted(abs(c[5]) for c in candidates)
p90 = gaps[int(0.90 * len(gaps))]
p50 = gaps[int(0.50 * len(gaps))]
p20 = gaps[int(0.20 * len(gaps))]

strong = [c for c in candidates if abs(c[5]) >= p90]
medium = [c for c in candidates if p50 * 0.7 <= abs(c[5]) <= p50 * 1.3]
low = [c for c in candidates if abs(c[5]) <= p20]

selected = []
seen_idx = set()


def add(c):
    if c[0] not in seen_idx:
        selected.append(c)
        seen_idx.add(c[0])


# 1: strongest gap featuring a priority team if any, else global strongest
prio_strong = [c for c in priority if abs(c[5]) >= p50]
if prio_strong:
    add(prio_strong[0])
elif strong:
    add(strong[0])

# fill remaining slots: 2 strong, 2 medium, 1 low (favor priority teams when tied)
for c in sorted(strong, key=lambda c: -abs(c[5])):
    if len(selected) >= 3:
        break
    add(c)

for c in sorted(medium, key=lambda c: -(c in priority)):
    if len([s for s in selected]) >= 4:
        break
    add(c)

for c in sorted(low, key=lambda c: -(c in priority)):
    if len(selected) >= 5:
        break
    add(c)

# top up to 5 if short
for c in by_gap:
    if len(selected) >= 5:
        break
    add(c)

selected = selected[:5]

print(f"{'HOME':<18}{'AWAY':<18}{'STR_H':>14}{'STR_A':>14}{'GAP':>14}   C44 (P1/PX/P2)          C48 (P1/PX/P2)")
for i, hn, an, sh, sa, sgap in selected:
    pH44, pD44, pA44 = preds_44[i]
    pH48, pD48, pA48 = preds_48[i]
    print(f"{hn:<18}{an:<18}{sh:>14,.0f}{sa:>14,.0f}{sgap:>+14,.0f}   "
          f"{pH44*100:5.1f}/{pD44*100:5.1f}/{pA44*100:5.1f}   "
          f"{pH48*100:5.1f}/{pD48*100:5.1f}/{pA48*100:5.1f}")
