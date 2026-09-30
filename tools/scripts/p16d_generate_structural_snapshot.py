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
    # ─── Load the committed team_id -> club_id mapping (P16A automated +
    # P16E manually-verified additions), consolidated into a single durable
    # file — see tools/scripts/p16_team_club_mapping.tsv for provenance.
    # This file IS the current-season (96) team list: there is no separate
    # "current teams" query here, so a future roster change (promotion/
    # relegation) requires updating this file by hand before re-running.
    mapping_path = ROOT / 'tools/scripts/p16_team_club_mapping.tsv'
    to_fetch = []  # (team_id, name, comp, club_id) — comp left empty, unused
    with open(mapping_path, encoding='utf-8') as f:
        for line in f:
            if not line.strip() or line.startswith('#'):
                continue
            tid, name, club_id = line.rstrip('\n').split('\t')
            to_fetch.append((tid, name, '', club_id))

    unmapped = []  # kept for the report below; always empty for this committed file

    print(f"Current 2026/27 teams requested: {len(to_fetch)}")
    print(f"With safe P16A/P16E mapping: {len(to_fetch)}")
    print(f"UNMAPPED (reported, not guessed, skipped): {len(unmapped)}")
    for tid, name, comp in unmapped:
        print(f"  UNMAPPED: team_id={tid} name={name!r} competition_id={comp}")
    print()

    generated_at = datetime.now(timezone.utc).isoformat()
    teams_out = {}
    consecutive_failures = 0
    aborted = False

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
                print(f"[{i+1}/{len(to_fetch)}] {name:<28} players={len(players):>3}  top25=\u20ac{top25_sum:,}")

        if consecutive_failures >= CONSECUTIVE_FAILURE_ABORT_THRESHOLD:
            print(f"\nABORTING: {consecutive_failures} consecutive failures — Transfermarkt may be blocking requests.")
            print("No snapshot written (existing structural_strength_current.json, if any, left untouched).")
            aborted = True
            break

    if aborted:
        return 1

    # ─── Validation before writing ───────────────────────────────────────────
    coverage = len(teams_out)
    total = len(to_fetch)
    print(f"\nCoverage: {coverage}/{total} requested teams ({coverage/total:.1%})")

    values = [t['top25_market_value'] for t in teams_out.values()]
    non_positive = [tid for tid, t in teams_out.items() if t['top25_market_value'] <= 0]
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
    snapshot = {
        'generated_at': generated_at,
        'definition': 'top25_market_value',
        'teams': teams_out,
    }
    tmp_path = OUT_PATH.with_suffix('.json.tmp')
    tmp_path.write_text(json.dumps(snapshot, indent=2, ensure_ascii=False), encoding='utf-8')
    os.replace(tmp_path, OUT_PATH)
    print(f"\nSnapshot written atomically -> {OUT_PATH}")
    print(f"generated_at: {generated_at}")

    return 0


if __name__ == '__main__':
    sys.exit(main())
