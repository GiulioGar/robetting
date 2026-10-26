"""
P16D — persistent generator for tools/models/structural_strength_current.json.

Extends the P16C pilot (verified on Real Madrid/Napoli/Lazio) to every
CURRENT (2026/27) team across the 5 core leagues that already has a safe
team_id -> Transfermarkt club_id mapping from P16A (no fuzzy matching here —
teams without a P16A mapping are reported and skipped, never guessed).

TOP25_MARKET_VALUE definition (unchanged from P16A/P16B/P16C):
  sum of the 25 highest individual player market values on the CURRENT
  Transfermarkt squad ("kader") page for that club. Never the whole-squad
  overview total (that is a different metric, already used elsewhere by
  TeamStructuralRatingCalculator for a different purpose).

Reuses:
  - parse_squad_page() from tools/scripts/p16c_pilot_squad_parser.py (verified)
  - parse_market_value() from tools/structural/normalizer.py
  - team_id -> club_id mapping from tools/scripts/p16a_step2_club_mapping.py output
    (scratchpad/team_club_map.tsv)

Safety:
  - polite delay between requests (same 3s convention as tools/structural/collector.py)
  - if TM appears to be blocking (several consecutive 0-player pages / non-200
    responses), the run ABORTS — no partial snapshot is ever written
  - atomic write: built in a temp file, then os.replace() onto the final path
"""
import argparse
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[2]
SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')

sys.path.insert(0, str(Path(__file__).parent))
sys.path.insert(0, str(Path(__file__).parent.parent / 'structural'))
from p16c_pilot_squad_parser import parse_squad_page  # noqa: E402

OUT_PATH = ROOT / 'tools/models/structural_strength_current.json'
PLAYERS_OUT_PATH = ROOT / 'tools/models/structural_players_current.json'
REQUEST_DELAY_SECONDS = 3
CONSECUTIVE_FAILURE_ABORT_THRESHOLD = 3

_HEADERS = {
    'User-Agent': (
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        'AppleWebKit/537.36 (KHTML, like Gecko) '
        'Chrome/124.0.0.0 Safari/537.36'
    ),
    'Accept-Language': 'en-US,en;q=0.9',
}


def fetch_squad_page(tm_id: int, timeout: int = 30) -> tuple[int, str]:
    url = f'https://www.transfermarkt.com/club/kader/verein/{tm_id}/plus/1'
    resp = requests.get(url, headers=_HEADERS, timeout=timeout)
    return resp.status_code, resp.text


def main() -> int:
    arg_parser = argparse.ArgumentParser()
    arg_parser.add_argument(
        '--players-only',
        action='store_true',
        help=(
            'Reuse the same fetch/parse pipeline but write ONLY '
            f'{PLAYERS_OUT_PATH.name} — {OUT_PATH.name} is left untouched.'
        ),
    )
    arg_parser.add_argument(
        '--team-ids', default=None,
        help=(
            'Step C-STRUCTURAL-CHAMPIONS — comma-separated Robetting team_ids to '
            'fetch/update INCREMENTALLY (e.g. the 15 non-core UEFA Champions League '
            '2026/27 league-phase clubs). Every id MUST already have a row in '
            'p16_team_club_mapping.tsv — abort before any fetch otherwise. Omit for '
            'the original full-population (96 core teams) behaviour, unchanged.'
        ),
    )
    arg_parser.add_argument(
        '--base-snapshot', default=None,
        help=(
            'Existing structural_strength_*.json to start from — its "teams" entries '
            'are carried over byte-for-byte for every team NOT in --team-ids. Typically '
            f'{OUT_PATH.name} (the approved champion snapshot). Requires --team-ids and '
            '--output (which must differ from --base-snapshot, to never overwrite it).'
        ),
    )
    arg_parser.add_argument(
        '--output', default=None,
        help=(
            f'Override the Structural output path (default: {OUT_PATH.name}). '
            'Use a separate path (e.g. structural_strength_champions_extended.json) '
            'for any --team-ids/--base-snapshot run — never the champion file.'
        ),
    )
    args = arg_parser.parse_args()

    out_path = Path(args.output) if args.output else OUT_PATH
    incremental = args.team_ids is not None

    if args.base_snapshot and out_path == Path(args.base_snapshot):
        print("ABORTING: --output must differ from --base-snapshot — refusing to overwrite the base in place.")
        return 1

    # ─── Load the committed team_id -> club_id mapping (P16A automated +
    # P16E manually-verified additions, + the 15 non-core UEFA Champions
    # League 2026/27 rows appended in Step C-STRUCTURAL-CHAMPIONS),
    # consolidated into a single durable file — see
    # tools/scripts/p16_team_club_mapping.tsv for provenance. This file IS
    # the team list: there is no separate "current teams" query here, so a
    # future roster change requires updating this file by hand first.
    mapping_path = ROOT / 'tools/scripts/p16_team_club_mapping.tsv'
    all_mapped = []  # (team_id, name, comp, club_id) — comp left empty, unused
    with open(mapping_path, encoding='utf-8') as f:
        for line in f:
            if not line.strip() or line.startswith('#'):
                continue
            tid, name, club_id = line.rstrip('\n').split('\t')
            all_mapped.append((tid, name, '', club_id))

    if incremental:
        wanted_ids = [t.strip() for t in args.team_ids.split(',') if t.strip()]
        mapped_ids = {row[0] for row in all_mapped}
        missing = [t for t in wanted_ids if t not in mapped_ids]
        if missing:
            print(f"ABORTING: team_id(s) without a mapping row in {mapping_path.name}: {missing}. "
                  "No fetch attempted.")
            return 1
        to_fetch = [row for row in all_mapped if row[0] in set(wanted_ids)]
    else:
        to_fetch = all_mapped

    unmapped = []  # kept for the report below; always empty for this committed file

    print(f"Teams requested this run: {len(to_fetch)}" + (" (incremental)" if incremental else ""))
    print(f"With safe P16A/P16E mapping: {len(to_fetch)}")
    print(f"UNMAPPED (reported, not guessed, skipped): {len(unmapped)}")
    for tid, name, comp in unmapped:
        print(f"  UNMAPPED: team_id={tid} name={name!r} competition_id={comp}")
    print()

    generated_at = datetime.now(timezone.utc).isoformat()

    teams_out = {}
    players_out = {}
    if args.base_snapshot:
        base = json.loads(Path(args.base_snapshot).read_text(encoding='utf-8'))
        # Byte/numerically-preserved carry-over — these entries are never
        # touched below unless the SAME team_id is also in to_fetch (fresh
        # fetch result then takes precedence, same "update" semantics as a
        # full re-run would have for that one team).
        teams_out = dict(base.get('teams', {}))
        print(f"Base snapshot loaded: {args.base_snapshot} ({len(teams_out)} existing teams, generated_at={base.get('generated_at')})")

    consecutive_failures = 0
    aborted = False
    fetched_this_run = set()

    for i, (tid, name, comp, club_id) in enumerate(to_fetch):
        if i > 0:
            time.sleep(REQUEST_DELAY_SECONDS)

        try:
            status, html = fetch_squad_page(int(club_id))
        except requests.RequestException as exc:
            print(f"[{i+1}/{len(to_fetch)}] {name} (club_id={club_id}) FETCH ERROR: {exc}")
            consecutive_failures += 1
            status, html = None, ''
        else:
            if status != 200:
                print(f"[{i+1}/{len(to_fetch)}] {name} (club_id={club_id}) HTTP {status}")
                consecutive_failures += 1

        if status == 200:
            players = parse_squad_page(html, int(club_id))
            if len(players) == 0:
                print(f"[{i+1}/{len(to_fetch)}] {name} (club_id={club_id}) 0 players parsed — possible block/structure change")
                consecutive_failures += 1
            else:
                consecutive_failures = 0
                values_sorted = sorted(players, key=lambda p: -p['market_value'])
                top25 = values_sorted[:25]
                top25_sum = sum(p['market_value'] for p in top25)
                teams_out[tid] = {
                    'transfermarkt_club_id': int(club_id),
                    'team_name': name,
                    'players_count': len(players),
                    'top25_market_value': top25_sum,
                }
                fetched_this_run.add(tid)
                # Full squad (not just TOP25) kept separately \u2014 never consumed
                # by the Structural TOP25 output above, which is unchanged.
                players_out[tid] = {
                    'transfermarkt_club_id': int(club_id),
                    'team_name': name,
                    'players': [
                        {
                            'tm_player_id': p['player_id'],
                            'name': p['name'],
                            'market_value': p['market_value'],
                        }
                        for p in players
                    ],
                }
                print(f"[{i+1}/{len(to_fetch)}] {name:<28} players={len(players):>3}  top25=\u20ac{top25_sum:,}")

        if consecutive_failures >= CONSECUTIVE_FAILURE_ABORT_THRESHOLD:
            print(f"\nABORTING: {consecutive_failures} consecutive failures — Transfermarkt may be blocking requests.")
            print("No snapshot written (existing structural_strength_current.json, if any, left untouched).")
            aborted = True
            break

    if aborted:
        return 1

    # ─── Validation before writing ───────────────────────────────────────────
    # Coverage/positivity are checked against THIS RUN's own fetch batch only
    # — in incremental mode teams_out also holds the untouched base-snapshot
    # entries, which were already validated when that base was generated and
    # must not be re-validated/re-counted here.
    coverage = len(fetched_this_run)
    total = len(to_fetch)
    print(f"\nCoverage: {coverage}/{total} requested teams ({coverage/total:.1%})")

    fetched_values = {tid: teams_out[tid] for tid in fetched_this_run}
    values = [t['top25_market_value'] for t in fetched_values.values()]
    non_positive = [tid for tid, t in fetched_values.items() if t['top25_market_value'] <= 0]
    if non_positive:
        print(f"ABORTING: {len(non_positive)} teams with TOP25 <= 0 — refusing to write a suspect snapshot.")
        return 1

    print(f"TOP25 min/median/max: \u20ac{min(values):,} / \u20ac{sorted(values)[len(values)//2]:,} / \u20ac{max(values):,}")

    for team_id, wanted_name in [('324', 'Real Madrid'), ('278', 'Napoli'), ('274', 'Lazio')]:
        t = teams_out.get(team_id)
        if t:
            print(f"  {wanted_name}: players_count={t['players_count']}  top25=\u20ac{t['top25_market_value']:,}")
        else:
            print(f"  {wanted_name}: NOT in snapshot (team_id={team_id})")

    if coverage == 0:
        print("ABORTING: zero teams covered — refusing to write an empty snapshot.")
        return 1

    # ─── Atomic write ─────────────────────────────────────────────────────────
    structural_snapshot, players_snapshot = build_snapshots(teams_out, players_out, generated_at)

    if args.players_only:
        print(f"\n--players-only: {out_path} left untouched (not written)")
    else:
        _write_json_atomic(out_path, structural_snapshot)
        print(f"\nSnapshot written atomically -> {out_path}")
        print(f"generated_at: {generated_at}")

    if incremental:
        # players_out here only holds THIS RUN's fetch batch (never merged
        # with a base players file above) — writing it to the shared
        # PLAYERS_OUT_PATH would silently truncate the full-population
        # player-level file down to just these teams. Skip it; the
        # Structural TOP25 output above is the only deliverable this mode
        # produces (same scope requested — never the player-level file).
        print(f"Incremental mode: {PLAYERS_OUT_PATH.name} intentionally NOT written "
              "(would truncate it to only this run's teams).")
    else:
        _write_json_atomic(PLAYERS_OUT_PATH, players_snapshot)
        print(f"Player-level snapshot written atomically -> {PLAYERS_OUT_PATH}")

    return 0


def build_snapshots(teams_out: dict, players_out: dict, generated_at: str) -> tuple[dict, dict]:
    """
    Pure, no-I/O builder for the two output dicts. Kept separate from main()
    so it can be unit-tested with synthetic data (no network, no real files).

    The Structural (TOP25) dict is byte-for-byte what main() has always
    written — this function only adds the second, independent dict; it
    performs no sorting/filtering/derivation of its own, so it cannot change
    the existing Structural output.
    """
    structural_snapshot = {
        'generated_at': generated_at,
        'definition': 'top25_market_value',
        'teams': teams_out,
    }
    players_snapshot = {
        'generated_at': generated_at,
        'definition': 'squad_players_market_value',
        'teams': players_out,
    }
    return structural_snapshot, players_snapshot


def _write_json_atomic(path: Path, data: dict) -> None:
    tmp_path = path.with_suffix('.json.tmp')
    tmp_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')
    os.replace(tmp_path, path)


if __name__ == '__main__':
    sys.exit(main())
