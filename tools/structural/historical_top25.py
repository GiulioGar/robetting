"""
Point-in-time historical Structural Strength (TOP25 market value) — P16A methodology.

For every match (home/away, kickoff_at = T):
  1. only player valuations with date < T (date string compare on YYYY-MM-DD)
  2. player's club at T = current_club_id of his LAST valuation before T (global,
     not pre-filtered by club — P14A validated method)
  3. TOP 25 players by market_value_in_eur for that club
  4. structural value = sum of those 25 values (EUR)

Single chronological sweep (valuation events sorted by date, matches sorted by
kickoff_at) — O(valuations + matches).

Persistent inputs (in the repo, so artifacts are reproducible offline):
  tools/datasets/structural/p16a_team_club_map.tsv
      team_id <TAB> team_name <TAB> competition_id <TAB> transfermarkt club_id
      (111 teams of 2024/25-2026/27 in the 5 core leagues, P16A step 2 mapping)
  tools/datasets/structural/player_valuations_extract.csv.gz
      Pinned extract of dcaribou/transfermarkt-datasets player_valuations.csv:
      every valuation (original row order) of any player ever valued at a mapped
      club. Players never at a mapped club cannot enter any TOP25, so the result
      is identical to running on the full file. Upstream is a moving dataset —
      the extract is what pins the historical values.

Rebuild the extract from a fresh upstream download (only if deliberately
re-pinning; historical values may then change):
  python tools/structural/historical_top25.py build-extract <player_valuations.csv[.gz]>
"""
import csv
import gzip
import hashlib
import io
import json
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DATA_DIR = ROOT / 'tools/datasets/structural'
TEAM_CLUB_MAP = DATA_DIR / 'p16a_team_club_map.tsv'
VALUATIONS_EXTRACT = DATA_DIR / 'player_valuations_extract.csv.gz'
VALUATIONS_META = DATA_DIR / 'player_valuations_extract.meta.json'

TOPN = 25
EXTRACT_FIELDS = ['date', 'player_id', 'market_value_in_eur', 'current_club_id']


def _open_text(path: Path):
    if path.suffix == '.gz':
        return io.TextIOWrapper(gzip.open(path, 'rb'), encoding='utf-8', newline='')
    return open(path, encoding='utf-8', newline='')


def load_team_club_map(path: Path = TEAM_CLUB_MAP) -> dict:
    """team_id (str) -> transfermarkt club_id (str)."""
    out = {}
    with open(path, encoding='utf-8') as f:
        for line in f:
            tid, _name, _comp, club_id = line.rstrip('\n').split('\t')
            out[tid] = club_id
    return out


def load_valuation_events(path: Path = VALUATIONS_EXTRACT) -> list:
    """[(date, player_id, market_value, club_id)] sorted by date (stable: file order kept within a date)."""
    events = []
    with _open_text(path) as f:
        for r in csv.DictReader(f):
            try:
                mv = int(r['market_value_in_eur'])
            except (ValueError, TypeError):
                continue
            events.append((r['date'], r['player_id'], mv, r['current_club_id']))
    events.sort(key=lambda e: e[0])
    return events


def top25_point_in_time(matches: list, events: list, team_to_club: dict, topn: int = TOPN) -> dict:
    """
    matches: iterable of dicts with match_id, kickoff_at, home_team_id, away_team_id.
    Returns match_id -> {'structural_home', 'structural_away', 'n_home', 'n_away'};
    structural_* is None when the team is unmapped.
    """
    player_current = {}
    club_players = defaultdict(dict)
    ev_idx, n_events = 0, len(events)

    def top_sum(club_id):
        vals = sorted(club_players.get(club_id, {}).values(), reverse=True)
        return sum(vals[:topn]), len(vals)

    out = {}
    for m in sorted(matches, key=lambda m: m['kickoff_at']):
        day = m['kickoff_at'][:10]
        while ev_idx < n_events and events[ev_idx][0] < day:
            _date, pid, mv, cid = events[ev_idx]
            old = player_current.get(pid)
            if old is not None:
                club_players[old[1]].pop(pid, None)
            player_current[pid] = (mv, cid)
            club_players[cid][pid] = mv
            ev_idx += 1

        home_club = team_to_club.get(m['home_team_id'])
        away_club = team_to_club.get(m['away_team_id'])
        sh, nh = top_sum(home_club) if home_club else (None, 0)
        sa, na = top_sum(away_club) if away_club else (None, 0)
        out[m['match_id']] = {'structural_home': sh, 'structural_away': sa, 'n_home': nh, 'n_away': na}
    return out


def build_extract(source: Path, out_path: Path = VALUATIONS_EXTRACT, team_to_club: dict = None) -> dict:
    """Filter a full upstream player_valuations file down to the players that ever reach a mapped club."""
    team_to_club = team_to_club or load_team_club_map()
    target_clubs = set(team_to_club.values())

    with _open_text(source) as f:
        rows = [{k: r[k] for k in EXTRACT_FIELDS} for r in csv.DictReader(f)]
    keep_players = {r['player_id'] for r in rows if r['current_club_id'] in target_clubs}
    kept = [r for r in rows if r['player_id'] in keep_players]

    buf = io.StringIO()
    w = csv.DictWriter(buf, fieldnames=EXTRACT_FIELDS, lineterminator='\n')
    w.writeheader()
    w.writerows(kept)
    with open(out_path, 'wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as gz:
        gz.write(buf.getvalue().encode('utf-8'))

    meta = {
        'source': 'dcaribou/transfermarkt-datasets player_valuations.csv',
        'source_sha256': hashlib.sha256(Path(source).read_bytes()).hexdigest(),
        'source_rows': len(rows),
        'extract_rows': len(kept),
        'players': len(keep_players),
        'max_date': max(r['date'] for r in kept),
        'extract_sha256': hashlib.sha256(Path(out_path).read_bytes()).hexdigest(),
    }
    VALUATIONS_META.write_text(json.dumps(meta, indent=2) + '\n')
    return meta


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == 'build-extract':
        print(json.dumps(build_extract(Path(sys.argv[2])), indent=2))
    else:
        print(__doc__)
        sys.exit(1)
