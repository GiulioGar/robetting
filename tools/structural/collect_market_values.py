#!/usr/bin/env python3
"""
Robetting — Transfermarkt Market Value Collector

Scrapes total squad market values for all configured European competitions
and writes a single JSON file compatible with the Robetting Structural importer
(App\\Services\\Structural\\MarketValueImportService).

Usage:
    python collect_market_values.py
    python collect_market_values.py --date 2026-09-01
    python collect_market_values.py --out /path/to/output.json

Arguments:
    --date   Snapshot date in YYYY-MM-DD format.
             Defaults to today's date (local time).
             This value is written to "snapshot_date" in the JSON and is
             the date the admin importer stores against each snapshot row.
    --out    Absolute path for the output JSON file.
             Defaults to:
               <repo_root>/storage/app/structural/market_values_transfermarkt_YYYY-MM-DD.json

Behaviour:
    - Fetches each competition page sequentially with a polite delay.
    - Fails hard (exit 1) if any competition returns 0 teams.
    - Warns (but does not fail) if a competition returns a different count
      than expected_teams in competitions.py.
    - Reports all duplicate team names across leagues before writing.
    - Writes the JSON only if the total count matches the sum of expected_teams.
      On mismatch the file is still written but the script exits with code 1
      so CI / the caller knows something is off.

Output JSON format (compatible with MarketValueImportService):
    {
        "snapshot_date": "YYYY-MM-DD",
        "source":        "transfermarkt",
        "generated_at":  "<ISO-8601 UTC timestamp>",
        "teams": [
            {"team": "Inter", "market_value": 684000000},
            ...
        ]
    }
"""

import argparse
import json
import sys
import time
from datetime import date, datetime, timezone
from pathlib import Path

# Allow running the script from any directory.
sys.path.insert(0, str(Path(__file__).parent))

from competitions import COMPETITIONS
from collector import (
    REQUEST_DELAY_SECONDS,
    build_output,
    collect_competition,
    find_duplicates,
    merge_teams,
)

_REPO_ROOT = Path(__file__).parent.parent.parent
_DEFAULT_OUTPUT_DIR = _REPO_ROOT / 'storage' / 'app' / 'structural'


# ── CLI ───────────────────────────────────────────────────────────────────────

def _valid_date(value: str) -> str:
    try:
        date.fromisoformat(value)
    except ValueError:
        raise argparse.ArgumentTypeError(
            f"Invalid date {value!r} — expected YYYY-MM-DD."
        )
    return value


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description='Collect Transfermarkt market values and produce a Robetting JSON snapshot.'
    )
    parser.add_argument(
        '--date',
        type=_valid_date,
        default=None,
        metavar='YYYY-MM-DD',
        help='Snapshot date (default: today).',
    )
    parser.add_argument(
        '--out',
        type=Path,
        default=None,
        metavar='PATH',
        help='Output file path (default: storage/app/structural/market_values_transfermarkt_<date>.json).',
    )
    return parser.parse_args()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    args = _parse_args()

    snapshot_date = args.date or date.today().isoformat()
    generated_at = datetime.now(timezone.utc).isoformat()

    print('=' * 60)
    print('Robetting — Transfermarkt Market Value Collector')
    print('=' * 60)
    print(f'Snapshot date  : {snapshot_date}')
    print(f'Generated at   : {generated_at}')
    print(f'Competitions   : {len(COMPETITIONS)}')
    print(f'Request delay  : {REQUEST_DELAY_SECONDS}s between leagues')
    print()

    results = []

    for i, (comp_key, comp_config) in enumerate(COMPETITIONS.items()):
        if i > 0:
            print(f'  (pause {REQUEST_DELAY_SECONDS}s...)')
            time.sleep(REQUEST_DELAY_SECONDS)

        print(f'[{i + 1}/{len(COMPETITIONS)}] {comp_config["name"]} ({comp_config["tm_id"]}) ...', end=' ', flush=True)

        try:
            result = collect_competition(comp_key, comp_config)
        except Exception as exc:
            print()
            print(f'\nFATAL ERROR — {comp_config["name"]}:', file=sys.stderr)
            print(f'  {exc}', file=sys.stderr)
            print('\nAborting. No JSON written.', file=sys.stderr)
            sys.exit(1)

        results.append(result)

        if result['ok']:
            print(f'{result["found"]}/{result["expected"]} teams  [OK]')
        else:
            print(f'{result["found"]}/{result["expected"]} teams  [WARNING]')
            print(f'  ↳ {result["warning"]}')

    # ── Completeness report ───────────────────────────────────────────────────

    print()
    print('=' * 60)
    print('COMPLETENESS REPORT')
    print('=' * 60)

    total_found = 0
    total_expected = 0
    has_count_warning = False

    for r in results:
        mark = 'OK' if r['ok'] else 'WARNING'
        print(f'{r["name"]:<22}  found: {r["found"]:>2} / expected {r["expected"]:>2}  [{mark}]')
        if r['warning']:
            print(f'  ↳ {r["warning"]}')
            has_count_warning = True
        total_found += r['found']
        total_expected += r['expected']

    print('-' * 60)
    grand_mark = 'OK' if total_found == total_expected else 'WARNING'
    print(f'{"TOTAL":<22}  found: {total_found:>2} / expected {total_expected:>2}  [{grand_mark}]')

    # ── Duplicate check ───────────────────────────────────────────────────────

    duplicates = find_duplicates(results)
    if duplicates:
        print()
        print(f'DUPLICATES DETECTED ({len(duplicates)}):')
        for team_name, first_league, second_league in duplicates:
            print(f'  "{team_name}"  —  found in "{first_league}" AND "{second_league}"')

    # ── Build & write JSON ────────────────────────────────────────────────────

    all_teams = merge_teams(results)
    output = build_output(all_teams, snapshot_date, generated_at)

    if args.out:
        out_path: Path = args.out
        out_path.parent.mkdir(parents=True, exist_ok=True)
    else:
        _DEFAULT_OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        filename = f'market_values_transfermarkt_{snapshot_date}.json'
        out_path = _DEFAULT_OUTPUT_DIR / filename

    with open(out_path, 'w', encoding='utf-8') as fh:
        json.dump(output, fh, ensure_ascii=False, indent=2)

    print()
    print(f'JSON written to : {out_path}')
    print(f'Teams in file   : {len(all_teams)}')

    if duplicates:
        print(f'Duplicates      : {len(duplicates)} (first occurrence kept, review required)')

    # ── Exit code ─────────────────────────────────────────────────────────────

    if has_count_warning or duplicates:
        print()
        print('WARNING: Issues detected above. Review before importing into Robetting.')
        sys.exit(1)

    print()
    print('All checks passed. JSON is ready for import.')


if __name__ == '__main__':
    main()
