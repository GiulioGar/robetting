"""
HTTP collector and result-validation primitives.

This module is responsible for:
    - fetching a single Transfermarkt competition page (fetch_page)
    - validating parsed results against expected team counts (validate_competition_result)
    - assembling per-competition result dicts (collect_competition)
    - building the final JSON payload (build_output)
    - detecting cross-league duplicates (find_duplicates)
    - merging teams from multiple results, skipping duplicates (merge_teams)

The engine is entirely config-driven: it reads COMPETITIONS from competitions.py
and needs no changes when new competitions are added there.
"""

import requests

from tm_parser import parse_competition_page

_TM_URL = "https://www.transfermarkt.com/{slug}/startseite/wettbewerb/{tm_id}"

_HEADERS = {
    'User-Agent': (
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        'AppleWebKit/537.36 (KHTML, like Gecko) '
        'Chrome/124.0.0.0 Safari/537.36'
    ),
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9',
    'Accept-Encoding': 'gzip, deflate, br',
    'Connection': 'keep-alive',
    'Upgrade-Insecure-Requests': '1',
    'Cache-Control': 'max-age=0',
}

REQUEST_DELAY_SECONDS = 3  # polite inter-request pause


# ── HTTP ──────────────────────────────────────────────────────────────────────

def fetch_page(url: str, timeout: int = 30) -> str:
    """
    Fetch a URL and return the HTML body as a string.

    Raises:
        requests.HTTPError: on 4xx / 5xx responses (fails explicitly, no retry).
        requests.ConnectionError / requests.Timeout: on network issues.
    """
    session = requests.Session()
    session.headers.update(_HEADERS)
    response = session.get(url, timeout=timeout)
    response.raise_for_status()
    return response.text


# ── Validation ────────────────────────────────────────────────────────────────

def validate_competition_result(
    comp_key: str,
    comp_config: dict,
    teams: list[dict],
) -> dict:
    """
    Wrap parsed teams in a result dict with completeness metadata.

    Args:
        comp_key:    Key from COMPETITIONS (e.g. "serie_a").
        comp_config: Config entry from COMPETITIONS.
        teams:       Output of parse_competition_page().

    Returns:
        {
            "key":      str,
            "name":     str,
            "teams":    list[dict],
            "found":    int,
            "expected": int,
            "ok":       bool,         # True iff found == expected
            "warning":  str | None,
        }

    Raises:
        RuntimeError: if teams is empty (hard failure — probable block or
                      page-structure change; the caller must not continue).
    """
    found = len(teams)
    expected = comp_config['expected_teams']

    if found == 0:
        raise RuntimeError(
            f"[{comp_config['name']}] Retrieved 0 teams. "
            "Possible Transfermarkt access block or page-structure change. "
            "Aborting — will not produce an incomplete dataset."
        )

    warning: str | None = None
    if found != expected:
        warning = f"Expected {expected} teams but found {found}."

    return {
        'key':      comp_key,
        'name':     comp_config['name'],
        'teams':    teams,
        'found':    found,
        'expected': expected,
        'ok':       warning is None,
        'warning':  warning,
    }


# ── Collection ────────────────────────────────────────────────────────────────

def collect_competition(comp_key: str, comp_config: dict) -> dict:
    """
    Fetch, parse, and validate one competition.

    This is the live version that makes an HTTP request.
    For testing, call validate_competition_result() directly with pre-fetched HTML.
    """
    url = _TM_URL.format(slug=comp_config['tm_slug'], tm_id=comp_config['tm_id'])
    html = fetch_page(url)
    teams = parse_competition_page(html)
    return validate_competition_result(comp_key, comp_config, teams)


# ── Output assembly ───────────────────────────────────────────────────────────

def build_output(all_teams: list[dict], snapshot_date: str, generated_at: str) -> dict:
    """
    Build the JSON-serialisable dict compatible with the Laravel importer.

    Compatible with App\\Services\\Structural\\MarketValueImportService::preview().
    """
    return {
        'snapshot_date': snapshot_date,
        'source':        'transfermarkt',
        'generated_at':  generated_at,
        'teams':         all_teams,
    }


def find_duplicates(results: list[dict]) -> list[tuple]:
    """
    Find team names that appear in more than one league result.

    Returns:
        List of (team_name, first_league_name, second_league_name) tuples.
        Only the first duplicate occurrence per team is reported.
    """
    seen: dict[str, str] = {}
    duplicates: list[tuple] = []

    for result in results:
        for team in result['teams']:
            name = team['team']
            if name in seen:
                duplicates.append((name, seen[name], result['name']))
            else:
                seen[name] = result['name']

    return duplicates


def merge_teams(results: list[dict]) -> list[dict]:
    """
    Flatten teams from all results into a single list.
    On duplicate team names, the first occurrence (first league) wins.
    """
    seen: set[str] = set()
    merged: list[dict] = []

    for result in results:
        for team in result['teams']:
            if team['team'] not in seen:
                seen.add(team['team'])
                merged.append(team)

    return merged
