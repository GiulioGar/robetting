"""
Market-value string normaliser.

Converts Transfermarkt abbreviated values to integer euro amounts.

Supported formats (case-insensitive suffix):
    €1.43bn   ->  1_430_000_000
    €684.00m  ->    684_000_000
    €9.15m    ->      9_150_000
    €850k     ->        850_000

Any unrecognised format raises ValueError — no silent guessing.
"""

import re

_PATTERN = re.compile(
    r'^'
    r'€?'                       # optional euro sign
    r'(?P<number>\d+(?:\.\d+)?)' # integer or decimal  e.g.  684.00
    r'\s*'                      # optional whitespace
    r'(?P<suffix>bn|m|k)'       # mandatory suffix
    r'$',
    re.IGNORECASE,
)

_MULTIPLIERS: dict[str, int] = {
    'bn': 1_000_000_000,
    'm':  1_000_000,
    'k':  1_000,
}


def parse_market_value(raw: str) -> int:
    """
    Convert a Transfermarkt market-value string to integer euros.

    Args:
        raw: Value string as returned by the Transfermarkt page,
             e.g. "€684.00m", "€1.43bn", "€850k".

    Returns:
        Integer euro amount.

    Raises:
        ValueError: If the string does not match any known format.
    """
    if not isinstance(raw, str):
        raise ValueError(
            f"parse_market_value expects a str, got {type(raw).__name__!r}: {raw!r}"
        )

    cleaned = raw.strip().replace('\xa0', '')  # strip non-breaking spaces

    m = _PATTERN.match(cleaned)
    if m is None:
        raise ValueError(f"Unrecognised market value format: {raw!r}")

    number = float(m.group('number'))
    suffix = m.group('suffix').lower()
    multiplier = _MULTIPLIERS[suffix]

    return int(round(number * multiplier))
