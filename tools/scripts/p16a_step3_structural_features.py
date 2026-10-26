"""
P16A step 3 — point-in-time Structural Strength (TOP25 market value) per match.

For every match (home/away, kickoff_at = T):
  1. only player_valuations with date < T
  2. player's club at T = current_club_id of his LAST valuation before T (global, not
     pre-filtered by club — matches P14A validated method)
  3. TOP 25 players by market_value_in_eur for that club
  4. structural_* = sum of those 25 values (EUR)

Implemented as a single chronological sweep (events sorted by date, matches sorted by
kickoff_at) — O(valuations + matches), no repeated per-date search.
"""
import csv
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')
TM = SCRATCH / 'tm_data'

TOPN = 25

# team_id -> club_id (Robetting -> dcaribou), from step 2
team_to_club = {}
with open(SCRATCH / 'team_club_map.tsv', encoding='utf-8') as f:
    for line in f:
        tid, name, comp, club_id = line.rstrip('\n').split('\t')
        team_to_club[tid] = club_id

target_club_ids = set(team_to_club.values())
print(f"Target clubs: {len(target_club_ids)}")

# ─── Load + sort valuation events ───────────────────────────────────────────
events = []  # (date, player_id, market_value, club_id)
with open(TM / 'player_valuations.csv', encoding='utf-8') as f:
    for r in csv.DictReader(f):
        try:
            mv = int(r['market_value_in_eur'])
        except (ValueError, TypeError):
            continue
        events.append((r['date'], r['player_id'], mv, r['current_club_id']))

events.sort(key=lambda e: e[0])
print(f"Valuation events: {len(events)}")

# ─── Load matches (TRAIN+TEST 2024/25+2025/26, then FWD 2026/27) ───────────
def load_matches(path):
    with open(path, newline='', encoding='utf-8') as f:
        rows = list(csv.DictReader(f))
    out = []
    for r in rows:
        if r.get('label_home_goals', '') == '' and path.name != 'dataset_core_v1_2026.csv':
            continue
        out.append({
            'match_id': r['match_id'],
            'kickoff_at': r['kickoff_at'],
            'home_team_id': r['home_team_id'],
            'away_team_id': r['away_team_id'],
            'season_id': r['season_id'],
        })
    return out

matches = load_matches(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv')
matches_fwd = load_matches(ROOT / 'tools/datasets/dataset_core_v1_2026.csv')
print(f"TRAIN+TEST matches: {len(matches)}  FWD matches: {len(matches_fwd)}")

all_matches = sorted(matches + matches_fwd, key=lambda m: m['kickoff_at'])

# ─── Chronological sweep ────────────────────────────────────────────────────
player_current = {}          # player_id -> (market_value, club_id)
club_players = defaultdict(dict)  # club_id -> {player_id: market_value}

ev_idx = 0
n_events = len(events)


def top25_sum(club_id):
    vals = sorted(club_players.get(club_id, {}).values(), reverse=True)
    n = len(vals)
    return sum(vals[:TOPN]), n


results = []
coverage_home_n = []
coverage_away_n = []

for m in all_matches:
    T = m['kickoff_at']
    # advance events strictly before T
    while ev_idx < n_events and events[ev_idx][0] < T[:10]:
        date, pid, mv, cid = events[ev_idx]
        old = player_current.get(pid)
        if old is not None:
            old_mv, old_cid = old
            club_players[old_cid].pop(pid, None)
        player_current[pid] = (mv, cid)
        club_players[cid][pid] = mv
        ev_idx += 1

    home_club = team_to_club.get(m['home_team_id'])
    away_club = team_to_club.get(m['away_team_id'])

    struct_home, n_home = (top25_sum(home_club) if home_club else (None, 0))
    struct_away, n_away = (top25_sum(away_club) if away_club else (None, 0))

    if home_club:
        coverage_home_n.append(n_home)
    if away_club:
        coverage_away_n.append(n_away)

    results.append({
        'match_id': m['match_id'],
        'kickoff_at': T,
        'season_id': m['season_id'],
        'structural_home': struct_home,
        'structural_away': struct_away,
        'n_home': n_home,
        'n_away': n_away,
    })

with open(SCRATCH / 'structural_features.csv', 'w', newline='', encoding='utf-8') as out:
    w = csv.DictWriter(out, fieldnames=['match_id', 'kickoff_at', 'season_id',
                                         'structural_home', 'structural_away', 'n_home', 'n_away'])
    w.writeheader()
    w.writerows(results)

print(f"\nWrote {len(results)} rows -> structural_features.csv")

# ─── Coverage diagnostics ───────────────────────────────────────────────────
missing_home = sum(1 for r in results if r['structural_home'] is None)
missing_away = sum(1 for r in results if r['structural_away'] is None)
print(f"Missing structural_home (unmapped team): {missing_home}")
print(f"Missing structural_away (unmapped team): {missing_away}")

partial25 = sum(1 for r in results if r['n_home'] is not None and 0 < r['n_home'] < 25)
zero_home = sum(1 for r in results if r['structural_home'] is not None and r['n_home'] == 0)
print(f"Rows with n_home < 25 (partial squad found): {partial25}")
print(f"Rows with n_home == 0 (mapped club, zero players found): {zero_home}")

import statistics
vals_home = [r['structural_home'] for r in results if r['structural_home']]
print(f"structural_home: min={min(vals_home):,}  median={statistics.median(vals_home):,.0f}  max={max(vals_home):,}")
