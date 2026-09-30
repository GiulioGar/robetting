"""
P16C — pilot: parse a Transfermarkt CURRENT SQUAD page (per-player market value)
for exactly 3 clubs (Real Madrid, Napoli, Lazio), compute TOP25 the same way as
P16A/P16B (sum of the 25 highest individual market values), and print a report.

Read-only pilot. No DB writes, no model changes, not wired into
CandidateModelService. Reuses tools/structural/normalizer.py::parse_market_value
for value parsing (no reimplementation).
"""
import sys
import re
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent / 'structural'))
from normalizer import parse_market_value  # noqa: E402

from bs4 import BeautifulSoup

CLUBS = [
    {'name': 'Real Madrid', 'slug': 'real-madrid', 'tm_id': 418},
    {'name': 'Napoli',      'slug': 'ssc-neapel',  'tm_id': 6195},
    {'name': 'Lazio',       'slug': 'lazio-rom',   'tm_id': 398},
]

SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad/tm_pilot')

_ID_RE = re.compile(r'/spieler/(\d+)')


def parse_squad_page(html: str, tm_id: int):
    """
    Parse a Transfermarkt "kader" (squad) page.

    Row structure (verified on real-madrid/ssc-neapel/lazio-rom pages):
        <table class="items"><tr class="odd|even">
            ...
            <td class="posrela"><table class="inline-table">
                <tr><td rowspan=2><img alt="Player Name"></td>
                    <td class="hauptlink"><a href="/slug/profil/spieler/ID">Name</a></td></tr>
                <tr><td>Position</td></tr>
            </table></td>
            ...
            <td class="rechts hauptlink"><a href="/slug/marktwertverlauf/spieler/ID">€X.XXm</a></td>
        </tr></table>

    Market value is always the LAST td.rechts (mirrors tm_parser.py convention).
    A row with no parseable value (e.g. injured/no valuation, "-") is skipped,
    never treated as €0 — matches the "never guess" rule in normalizer.py.
    """
    soup = BeautifulSoup(html, 'lxml')
    table = soup.find('table', class_='items')
    if table is None:
        return []

    players = []
    seen_ids = set()
    for row in table.select('tbody > tr'):
        name_link = row.select_one('td.posrela table.inline-table a[href*="/profil/spieler/"]')
        if name_link is None:
            continue
        name = name_link.get_text(strip=True)
        href = name_link.get('href', '')
        m = _ID_RE.search(href)
        player_id = int(m.group(1)) if m else None

        rechts_cells = row.select('td.rechts')
        if not rechts_cells:
            continue
        raw_value = rechts_cells[-1].get_text(strip=True).replace('\xa0', '')
        if not raw_value or raw_value in ('-', '\u2014'):
            continue
        try:
            value = parse_market_value(raw_value)
        except ValueError:
            continue

        if player_id is not None:
            if player_id in seen_ids:
                continue  # de-dup guard, should never trigger on a real squad page
            seen_ids.add(player_id)

        players.append({
            'player_id': player_id,
            'name': name,
            'market_value': value,
            'club_tm_id': tm_id,
        })
    return players


def main():
    generated_at = datetime.now(timezone.utc).isoformat()
    print(f"generated_at (pilot run): {generated_at}\n")

    any_failure = False

    for club in CLUBS:
        path = SCRATCH / f"{club['slug']}.html"
        html = path.read_text(encoding='utf-8')
        players = parse_squad_page(html, club['tm_id'])

        print(f"=== {club['name']} (tm_id={club['tm_id']}, slug={club['slug']}) ===")
        print(f"players found: {len(players)}")

        if len(players) == 0:
            print("  PARSER FAILURE — 0 players parsed. Stopping this club.")
            any_failure = True
            continue

        # sanity: no duplicate player_ids, all rows tagged with the requested club
        ids = [p['player_id'] for p in players if p['player_id'] is not None]
        dup = len(ids) != len(set(ids))
        wrong_club = any(p['club_tm_id'] != club['tm_id'] for p in players)
        print(f"duplicates: {'YES — FAIL' if dup else 'no'}")
        print(f"wrong club tag: {'YES — FAIL' if wrong_club else 'no'}")

        values_sorted = sorted(players, key=lambda p: -p['market_value'])
        top25 = values_sorted[:25]
        top25_sum = sum(p['market_value'] for p in top25)

        print(f"TOP25_MARKET_VALUE: \u20ac{top25_sum:,}")
        print("top 5 players:")
        for p in values_sorted[:5]:
            print(f"    {p['name']:<28} id={p['player_id']!s:<10} \u20ac{p['market_value']:,}")

        # plausibility: a top-flight club's most valuable player should be
        # in a sane 1M-250M EUR band; total top25 in a sane 20M-2bn band.
        max_val = values_sorted[0]['market_value']
        plausible = (1_000_000 <= max_val <= 250_000_000) and (20_000_000 <= top25_sum <= 2_000_000_000)
        print(f"plausibility check: {'OK' if plausible else 'SUSPECT — REVIEW'}")
        if not plausible:
            any_failure = True
        print()

    if any_failure:
        print("RESULT: pilot has at least one failure/suspect club — do NOT proceed to 96-team rollout.")
    else:
        print("RESULT: pilot OK on all 3 clubs.")


if __name__ == '__main__':
    main()
