"""
Competition configuration for the Robetting market-value collector.

To add a new competition in the future, add a new entry here.
The scraper engine reads this dict and needs no modification.

Fields per competition:
    name           Human-readable name (used in reports and logs).
    tm_slug        Transfermarkt URL slug  (e.g. "serie-a").
    tm_id          Transfermarkt competition ID (e.g. "IT1").
    expected_teams Number of teams currently in the competition.
                   Used to validate completeness; a mismatch triggers a warning.
"""

COMPETITIONS: dict[str, dict] = {
    "serie_a": {
        "name": "Serie A",
        "tm_slug": "serie-a",
        "tm_id": "IT1",
        "expected_teams": 20,
    },
    "premier_league": {
        "name": "Premier League",
        "tm_slug": "premier-league",
        "tm_id": "GB1",
        "expected_teams": 20,
    },
    "la_liga": {
        "name": "La Liga",
        "tm_slug": "laliga",
        "tm_id": "ES1",
        "expected_teams": 20,
    },
    "bundesliga": {
        "name": "Bundesliga",
        "tm_slug": "1-bundesliga",
        "tm_id": "L1",
        "expected_teams": 18,
    },
    "ligue_1": {
        "name": "Ligue 1",
        "tm_slug": "ligue-1",
        "tm_id": "FR1",
        "expected_teams": 18,
    },
}
