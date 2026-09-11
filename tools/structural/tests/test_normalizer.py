"""Tests for normalizer.parse_market_value — no network dependency."""
import pytest
from normalizer import parse_market_value


# ── Valid formats ─────────────────────────────────────────────────────────────

def test_parse_bn():
    assert parse_market_value('€1.43bn') == 1_430_000_000


def test_parse_m_two_decimals():
    assert parse_market_value('€684.00m') == 684_000_000


def test_parse_m_small():
    assert parse_market_value('€9.15m') == 9_150_000


def test_parse_k():
    assert parse_market_value('€850k') == 850_000


def test_parse_no_euro_sign():
    assert parse_market_value('684.00m') == 684_000_000


def test_parse_case_insensitive_suffix():
    assert parse_market_value('€500M') == 500_000_000
    assert parse_market_value('€1.5BN') == 1_500_000_000
    assert parse_market_value('€200K') == 200_000


def test_parse_integer_m():
    assert parse_market_value('€500m') == 500_000_000


def test_parse_whitespace_stripped():
    assert parse_market_value('  €684.00m  ') == 684_000_000


def test_parse_nbsp_stripped():
    assert parse_market_value('€684.00\xa0m') == 684_000_000


# ── Invalid formats — test 5 ──────────────────────────────────────────────────

def test_invalid_format_raises():
    with pytest.raises(ValueError, match="Unrecognised"):
        parse_market_value('€684,00m')   # comma as decimal — not supported


def test_invalid_dash_raises():
    with pytest.raises(ValueError):
        parse_market_value('-')


def test_invalid_empty_raises():
    with pytest.raises(ValueError):
        parse_market_value('')


def test_invalid_raw_number_raises():
    with pytest.raises(ValueError):
        parse_market_value('684000000')  # no suffix


def test_invalid_type_raises():
    with pytest.raises(ValueError, match="expects a str"):
        parse_market_value(684_000_000)  # type: ignore
