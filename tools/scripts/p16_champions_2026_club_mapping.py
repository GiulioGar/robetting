"""
Step C-STRUCTURAL-CHAMPIONS — local (no-HTTP) matching for the 14 non-core
UEFA Champions League 2026/27 league-phase clubs covered by the 10 domestic
TM codes extended in p16a_step2_club_mapping.py's COMP_TO_TM (PO1, NL1, TR1,
BE1, GR1, A1, SK1, UKR1, CZ1, NO1).

Reuses the EXACT SAME matching tiers as p16a_step2_club_mapping.py (exact
name -> normalized name -> substring), with its norm()/OVERRIDES copied
verbatim here rather than imported, because that script's top level has
unprotected file reads against SCRATCH/all_teams.tsv and SCRATCH/dataset_teams.tsv
(a different, now-gone session scratchpad) that would raise on import —
unrelated to this task, not touched.

Input: tools/datasets/structural/clubs.csv (local, already vendored — no
HTTP request made by this script).

Output: tools/scripts/p16_champions_2026_mapping_proposed.tsv (PROPOSAL only
— tools/scripts/p16_team_club_mapping.tsv, the canonical production mapping,
is never written here).

Known findings (read-only clubs.csv inspection, no scraping):
  - Slovakia has NO rows at all in this clubs.csv — Slovan Bratislava is
    unmatchable with this dataset (coverage gap, not a wrong code). Reported
    as unresolved, same bucket as Sabah FA (manual/out of scope).
  - 'CZ1' has ZERO rows in clubs.csv. dcaribou's real Czech Republic code is
    'TS1' — used directly below for Slavia Praha, with this discrepancy
    flagged explicitly in the output.
"""
import csv
import re
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CLUBS_CSV = ROOT / 'tools/datasets/structural/clubs.csv'
OUT_PATH = ROOT / 'tools/scripts/p16_champions_2026_mapping_proposed.tsv'

# Verbatim copy of p16a_step2_club_mapping.py's norm() — same algorithm, no
# duplication of INTENT, just avoiding an import with unrelated side effects.
STOP = {'fc', 'cfc', 'afc', 'ac', 'ss', 'ssc', 'us', 'uc', 'ssd', 'calcio',
        'football', 'club', 'associazione', 'sportiva', 'societa', 'spa',
        '1899', '1900', '1907', '1909', '1913', '1919', '1936', 'de', 'cf',
        'real', 'sporting', 'olympique', 'racing', 'athletic',
        'sc', 'sv', 'fsv', 'tsg', 'vfl', 'vfb', 'bv', '1846', '1848',
        '1904', 'og', 'as', 'kv', 'cp', 'cfr', 'sk'}


def norm(s: str) -> str:
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode('ascii')
    s = s.lower()
    s = re.sub(r'[^a-z0-9 ]', ' ', s)
    tokens = [t for t in s.split() if t not in STOP]
    return ' '.join(tokens) if tokens else s.strip()


# team_id (Robetting) -> (name, known TM domestic code). team_id values are
# the real Robetting ids confirmed in the Step B Champions-coverage audit.
TARGETS = {
    424: ('Club Brugge KV', 'BE1'),
    426: ('AEK Athens FC', 'GR1'),
    444: ('Lask Linz', 'A1'),
    409: ('FC Porto', 'PO1'),
    442: ('Viking', 'NO1'),
    408: ('Feyenoord', 'NL1'),
    436: ('Slovan Bratislava', None),   # no Slovak rows in clubs.csv at all
    410: ('Sporting CP', 'PO1'),
    433: ('Galatasaray', 'TR1'),
    407: ('PSV Eindhoven', 'NL1'),
    420: ('Shakhtar Donetsk', 'UKR1'),
    428: ('Fenerbahçe', 'TR1'),
    463: ('Sabah FA', None),            # Azerbaijan — manual/out of scope per instructions
    414: ('Bodo/Glimt', 'NO1'),
    422: ('Slavia Praha', 'TS1'),       # dcaribou code is TS1, NOT 'CZ1' (CZ1 has zero rows)
}

OVERRIDES = {
    'Fenerbahçe': 'Fenerbahce',
    'Bodo/Glimt': 'Fotballklubben Bodø/Glimt',
    'Lask Linz': 'LASK',
    'Club Brugge KV': 'Club Brugge KV',
    'Slavia Praha': 'SK Slavia Prague',
}


def load_clubs_by_comp():
    clubs_by_comp = {}
    with open(CLUBS_CSV, encoding='utf-8') as f:
        for r in csv.DictReader(f):
            clubs_by_comp.setdefault(r['domestic_competition_id'], []).append((r['club_id'], r['name']))
    return clubs_by_comp


def match_one(name, tm_code, clubs_by_comp):
    """Same 3-tier matching as p16a_step2_club_mapping.py; returns (club_id, club_name, tier) or (None, None, None)."""
    if tm_code is None:
        return None, None, 'no_domestic_code'

    candidates = clubs_by_comp.get(tm_code, [])
    if not candidates:
        return None, None, 'no_candidates_for_code'

    lookup_name = OVERRIDES.get(name, name)

    for cid, cname in candidates:
        if cname == lookup_name:
            return cid, cname, 'exact'

    nn = norm(lookup_name)
    for cid, cname in candidates:
        if norm(cname) == nn:
            return cid, cname, 'normalized'

    matches = [(cid, cname) for cid, cname in candidates if nn and (nn in norm(cname) or norm(cname) in nn)]
    if len(matches) == 1:
        return matches[0][0], matches[0][1], 'substring_unique'
    if len(matches) > 1:
        return None, None, f'substring_ambiguous:{[m[1] for m in matches]}'

    return None, None, 'unmatched'


def main():
    clubs_by_comp = load_clubs_by_comp()

    rows = []
    for tid, (name, tm_code) in TARGETS.items():
        club_id, club_name, tier = match_one(name, tm_code, clubs_by_comp)
        safe = tier in ('exact', 'normalized', 'substring_unique')
        rows.append({
            'team_id': tid,
            'name': name,
            'tm_code': tm_code,
            'club_id': club_id,
            'club_name': club_name,
            'tier': tier,
            'safe': safe,
        })

    print(f"{'team_id':<8} {'name':<22} {'tm_code':<8} {'club_id':<10} {'candidate_name':<30} {'tier':<28} {'safe'}")
    for r in rows:
        print(f"{r['team_id']:<8} {r['name']:<22} {str(r['tm_code']):<8} {str(r['club_id']):<10} "
              f"{str(r['club_name']):<30} {r['tier']:<28} {r['safe']}")

    safe_rows = [r for r in rows if r['safe']]
    unsafe_rows = [r for r in rows if not r['safe']]

    print(f"\nSafe auto-matches: {len(safe_rows)}")
    print(f"Manual/unresolved: {len(unsafe_rows)}")

    if safe_rows:
        with open(OUT_PATH, 'w', encoding='utf-8') as out:
            out.write("# PROPOSED mapping for UEFA Champions League 2026/27 league-phase clubs — Step C-STRUCTURAL-CHAMPIONS.\n")
            out.write("# NOT the canonical p16_team_club_mapping.tsv — proposal only, not yet verified/committed.\n")
            out.write("# team_id (Robetting) -> team_name -> Transfermarkt club_id.\n")
            for r in safe_rows:
                out.write(f"{r['team_id']}\t{r['name']}\t{r['club_id']}\n")
        print(f"\nProposal written: {OUT_PATH} ({len(safe_rows)} rows)")
    else:
        print("\nNo safe matches — proposal file NOT written.")

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
