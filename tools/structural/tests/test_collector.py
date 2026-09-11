"""Tests for collector utilities — no network dependency."""
import json
import pytest
from collector import (
    build_output,
    find_duplicates,
    merge_teams,
    validate_competition_result,
)


def _make_result(name: str, teams: list[dict], expected: int) -> dict:
    comp_config = {'name': name, 'expected_teams': expected}
    return validate_competition_result(name.lower().replace(' ', '_'), comp_config, teams)


def _teams(names: list[str], value: int = 1_000_000) -> list[dict]:
    return [{'team': n, 'market_value': value} for n in names]


# ── Test 7: generazione JSON ──────────────────────────────────────────────────

def test_build_output_structure():
    teams = [{'team': 'Inter', 'market_value': 684_000_000}]
    output = build_output(teams, '2026-09-11', '2026-09-11T00:00:00+00:00')

    assert output['snapshot_date'] == '2026-09-11'
    assert output['source'] == 'transfermarkt'
    assert output['generated_at'] == '2026-09-11T00:00:00+00:00'
    assert output['teams'] == teams


def test_build_output_json_serialisable():
    teams = [{'team': 'Inter', 'market_value': 684_000_000}]
    output = build_output(teams, '2026-09-11', '2026-09-11T00:00:00+00:00')

    serialised = json.dumps(output)
    parsed = json.loads(serialised)

    assert parsed['teams'][0]['market_value'] == 684_000_000


def test_build_output_preserves_team_order():
    names = ['Inter', 'AC Milan', 'Juventus']
    teams = _teams(names)
    output = build_output(teams, '2026-09-11', 'ts')
    assert [t['team'] for t in output['teams']] == names


# ── Test 8: duplicato squadra ─────────────────────────────────────────────────

def test_find_no_duplicates():
    results = [
        _make_result('Serie A', _teams(['Inter', 'Milan']), 2),
        _make_result('Premier League', _teams(['Arsenal', 'Chelsea']), 2),
    ]
    assert find_duplicates(results) == []


def test_find_duplicates_detected():
    results = [
        _make_result('Serie A', _teams(['Inter', 'Milan']), 2),
        _make_result('Premier League', _teams(['Inter', 'Arsenal']), 2),
    ]
    dups = find_duplicates(results)
    assert len(dups) == 1
    assert dups[0] == ('Inter', 'Serie A', 'Premier League')


def test_find_duplicates_multiple():
    results = [
        _make_result('Liga A', _teams(['X', 'Y']), 2),
        _make_result('Liga B', _teams(['X', 'Z']), 2),
        _make_result('Liga C', _teams(['Y', 'W']), 2),
    ]
    dups = find_duplicates(results)
    assert len(dups) == 2
    names = {d[0] for d in dups}
    assert names == {'X', 'Y'}


def test_merge_teams_deduplicates():
    results = [
        _make_result('Liga A', _teams(['X', 'Y']), 2),
        _make_result('Liga B', _teams(['X', 'Z']), 2),
    ]
    merged = merge_teams(results)
    team_names = [t['team'] for t in merged]
    assert team_names.count('X') == 1
    assert set(team_names) == {'X', 'Y', 'Z'}


# ── Test 9: competizione vuota ────────────────────────────────────────────────

def test_empty_competition_raises():
    comp_config = {'name': 'Vuota League', 'expected_teams': 20}
    with pytest.raises(RuntimeError, match="0 teams"):
        validate_competition_result('vuota', comp_config, [])


# ── Test 10: verifica expected team count ─────────────────────────────────────

def test_team_count_exact_is_ok():
    result = _make_result('Test', _teams([f'T{i}' for i in range(20)]), 20)
    assert result['ok'] is True
    assert result['warning'] is None
    assert result['found'] == 20
    assert result['expected'] == 20


def test_team_count_mismatch_gives_warning():
    result = _make_result('Test', _teams([f'T{i}' for i in range(18)]), 20)
    assert result['ok'] is False
    assert result['warning'] is not None
    assert '18' in result['warning']
    assert '20' in result['warning']


def test_team_count_above_expected_gives_warning():
    result = _make_result('Test', _teams([f'T{i}' for i in range(21)]), 20)
    assert result['ok'] is False
    assert '21' in result['warning']
