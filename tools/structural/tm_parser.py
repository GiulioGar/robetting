"""
Transfermarkt HTML parser.

Extracts (team_name, total_market_value) pairs from a Transfermarkt
competition overview page (the "startseite" page).

Page structure assumed:
    <table class="items">
        <tr class="odd"> or <tr class="even">
            <td class="hauptlink"><a>Team Name</a></td>
            ...
            <td class="rechts">€avg_mv</td>       ← second-to-last rechts
            <td class="rechts">[<a>]€total_mv</td> ← LAST rechts  ← what we want
        </tr>
    </table>

The parser never guesses: if the total-MV cell is missing or unparseable
the team row is silently skipped (the caller checks completeness).
"""

from bs4 import BeautifulSoup

from normalizer import parse_market_value


def parse_competition_page(html: str) -> list[dict]:
    """
    Parse a Transfermarkt competition page and return a list of teams.

    Args:
        html: Raw HTML content of the competition overview page.

    Returns:
        List of {"team": str, "market_value": int} dicts,
        one per successfully parsed row. Order preserved from page.
    """
    soup = BeautifulSoup(html, 'lxml')

    table = soup.find('table', class_='items')
    if table is None:
        return []

    teams: list[dict] = []

    for row in table.select('tr.odd, tr.even'):
        name_td = row.find('td', class_='hauptlink')
        if name_td is None:
            continue

        name_link = name_td.find('a')
        if name_link is None:
            continue

        team_name = name_link.get_text(strip=True)
        if not team_name:
            continue

        # Total market value is always the LAST td.rechts in the row.
        rechts_cells = row.select('td.rechts')
        if not rechts_cells:
            continue

        last_cell = rechts_cells[-1]
        raw_value = last_cell.get_text(strip=True).replace('\xa0', '')

        if not raw_value or raw_value in ('-', '—'):
            continue

        try:
            market_value = parse_market_value(raw_value)
        except ValueError:
            continue

        teams.append({'team': team_name, 'market_value': market_value})

    return teams
