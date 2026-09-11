"""Tests for tm_parser.parse_competition_page — no network dependency."""
import pytest
from pathlib import Path
from tm_parser import parse_competition_page

FIXTURE = Path(__file__).parent / 'fixtures' / 'competition_sample.html'


# ── Test 6: parsing di HTML fixture locale ────────────────────────────────────

def test_parse_fixture_returns_all_teams():
    html = FIXTURE.read_text(encoding='utf-8')
    teams = parse_competition_page(html)

    assert len(teams) == 5


def test_parse_fixture_team_names():
    html = FIXTURE.read_text(encoding='utf-8')
    teams = parse_competition_page(html)
    names = [t['team'] for t in teams]

    assert 'Inter Milan' in names
    assert 'AC Milan' in names
    assert 'Juventus FC' in names
    assert 'SSC Napoli' in names
    assert 'US Lecce' in names


def test_parse_fixture_market_values():
    html = FIXTURE.read_text(encoding='utf-8')
    teams = parse_competition_page(html)
    by_name = {t['team']: t['market_value'] for t in teams}

    assert by_name['Inter Milan'] == 684_000_000
    assert by_name['AC Milan'] == 520_000_000
    assert by_name['Juventus FC'] == 485_000_000
    assert by_name['SSC Napoli'] == 397_000_000
    assert by_name['US Lecce'] == 60_500_000


def test_parse_fixture_value_type_is_int():
    html = FIXTURE.read_text(encoding='utf-8')
    teams = parse_competition_page(html)
    for team in teams:
        assert isinstance(team['market_value'], int)


def test_parse_no_items_table():
    html = '<html><body><p>Nothing here</p></body></html>'
    assert parse_competition_page(html) == []


def test_parse_empty_items_table():
    html = '<html><body><table class="items"><tbody></tbody></table></body></html>'
    assert parse_competition_page(html) == []


def test_parse_row_with_dash_value_is_skipped():
    html = '''
    <html><body>
    <table class="items"><tbody>
      <tr class="odd">
        <td class="rechts">1</td>
        <td class="hauptlink"><a href="#">Test FC</a></td>
        <td class="rechts">€5.00m</td>
        <td class="rechts">-</td>
      </tr>
    </tbody></table>
    </body></html>
    '''
    teams = parse_competition_page(html)
    assert teams == []


def test_parse_value_inside_anchor():
    html = '''
    <html><body>
    <table class="items"><tbody>
      <tr class="odd">
        <td class="rechts">1</td>
        <td class="hauptlink"><a href="#">Test FC</a></td>
        <td class="rechts">€5.00m</td>
        <td class="rechts"><a href="/test-fc/datenfakten/verein/1">€100.00m</a></td>
      </tr>
    </tbody></table>
    </body></html>
    '''
    teams = parse_competition_page(html)
    assert len(teams) == 1
    assert teams[0] == {'team': 'Test FC', 'market_value': 100_000_000}
