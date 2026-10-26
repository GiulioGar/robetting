import csv
import re
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')
TM = SCRATCH / 'tm_data'

# Step C-STRUCTURAL-CHAMPIONS — clubs.csv is now vendored at a stable local
# path instead of a session-ephemeral scratchpad (which is cleaned up between
# sessions and is no longer readable). all_teams.tsv/dataset_teams.tsv below
# are unrelated to this fix and still live in SCRATCH (original core-5 driver
# below is otherwise unchanged).
CLUBS_CSV = ROOT / 'tools/datasets/structural/clubs.csv'

COMP_TO_TM = {'15': 'IT1', '16': 'GB1', '17': 'ES1', '18': 'L1', '19': 'FR1'}

# Step C-STRUCTURAL-CHAMPIONS — additional domestic TM codes for non-core
# UEFA Champions League 2026/27 league-phase clubs (self-mapped: harmless,
# additive, never hit by the original core-5 loop above, whose
# dataset_teams.tsv values are only '15'..'19'). Used by the separate
# p16_champions_2026_club_mapping.py driver, not by this script's own
# unprotected top-level core-5 matching loop below.
COMP_TO_TM.update({
    'PO1': 'PO1',    # Portugal
    'NL1': 'NL1',    # Netherlands
    'TR1': 'TR1',    # Turkey
    'BE1': 'BE1',    # Belgium
    'GR1': 'GR1',    # Greece
    'A1':  'A1',     # Austria
    'SK1': 'SK1',    # Slovakia — NOT present in clubs.csv (verified empty); kept
                      # here only as the documented intended code, resolves to
                      # zero candidates today.
    'UKR1': 'UKR1',  # Ukraine
    'CZ1': 'CZ1',    # Czech Republic — NOTE: clubs.csv has NO 'CZ1' rows; the
                      # real dcaribou code for Czech Republic is 'TS1'. Kept as
                      # a self-mapped no-op per instructions (do not silently
                      # rename it here); the Champions mapping driver uses
                      # 'TS1' directly and flags this discrepancy.
    'NO1': 'NO1',    # Norway
})

# team_id -> name (Robetting)
teams = {}
with open(SCRATCH / 'all_teams.tsv', encoding='utf-8') as f:
    for line in f:
        tid, name = line.rstrip('\n').split('\t', 1)
        teams[tid] = name

# team_id -> (competition_id, seasons)
dataset_teams = {}
with open(SCRATCH / 'dataset_teams.tsv', encoding='utf-8') as f:
    for line in f:
        tid, comp, seasons = line.rstrip('\n').split('\t')
        dataset_teams[tid] = comp

# clubs.csv: club_id, name, domestic_competition_id
clubs_by_comp = {}
with open(CLUBS_CSV, encoding='utf-8') as f:
    for r in csv.DictReader(f):
        clubs_by_comp.setdefault(r['domestic_competition_id'], []).append((r['club_id'], r['name']))


def norm(s: str) -> str:
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode('ascii')
    s = s.lower()
    s = re.sub(r'[^a-z0-9 ]', ' ', s)
    stop = {'fc', 'cfc', 'afc', 'ac', 'ss', 'ssc', 'us', 'uc', 'ssd', 'calcio',
            'football', 'club', 'associazione', 'sportiva', 'societa', 'spa',
            '1899', '1900', '1907', '1909', '1913', '1919', '1936', 'de', 'cf',
            'real', 'sporting', 'olympique', 'racing', 'athletic', 'club',
            'sc', 'sv', 'fsv', 'tsg', 'vfl', 'vfb', 'bv', '1846', '1848', '1899',
            '1904', 'og', 'as'}
    tokens = [t for t in s.split() if t not in stop]
    return ' '.join(tokens) if tokens else s.strip()

# Manual overrides for names normalization would not resolve well.
OVERRIDES = {
    'Inter': 'Inter Milan',
    'AC Milan': 'AC Milan',
    'Lazio': 'Societ\u00e0 Sportiva Lazio S.p.A.',
    'Hellas Verona': 'Hellas Verona',
    'Manchester United': 'Manchester United',
    'Manchester City': 'Manchester City',
    'Newcastle': 'Newcastle United',
    'Nottingham Forest': 'Nottingham Forest',
    'Wolverhampton Wanderers': 'Wolverhampton Wanderers',
    'West Ham': 'West Ham United',
    'Tottenham': 'Tottenham Hotspur',
    'Brighton': 'Brighton & Hove Albion',
    'Sheffield United': 'Sheffield United',
    'Leeds United': 'Leeds United',
    'Ipswich Town': 'Ipswich Town',
    'Athletic Club': 'Athletic Bilbao',
    'Atletico Madrid': 'Atl\u00e9tico de Madrid',
    'Atlético Madrid': 'Atl\u00e9tico de Madrid',
    'Real Sociedad': 'Real Sociedad',
    'Celta Vigo': 'RC Celta de Vigo',
    'Deportivo Alaves': 'Deportivo Alav\u00e9s',
    'Rayo Vallecano': 'Rayo Vallecano',
    'Real Betis': 'Real Betis Balompi\u00e9',
    'UD Las Palmas': 'UD Las Palmas',
    'RCD Espanyol': 'RCD Espanyol Barcelona',
    'RCD Mallorca': 'RCD Mallorca',
    'CD Leganes': 'CD Legan\u00e9s',
    'Bayern Munich': 'FC Bayern M\u00fcnchen',
    'Bayern M\u00fcnchen': 'Bayern Munich',
    'FC Heidenheim': '1. Fu\u00dfballclub Heidenheim 1846',
    '1. FC Heidenheim': '1. Fu\u00dfballclub Heidenheim 1846',
    'Rennes': 'Stade Rennais FC',
    'Wolves': 'Wolverhampton Wanderers',
    'Borussia Monchengladbach': 'Borussia M\u00f6nchengladbach',
    'Eintracht Frankfurt': 'Eintracht Frankfurt',
    'Bayer Leverkusen': 'Bayer 04 Leverkusen',
    'RB Leipzig': 'RB Leipzig',
    'VfL Wolfsburg': 'VfL Wolfsburg',
    'VfL Bochum': 'VfL Bochum',
    'Borussia Dortmund': 'Borussia Dortmund',
    "Borussia M'gladbach": 'Borussia M\u00f6nchengladbach',
    'FSV Mainz 05': '1. FSV Mainz 05',
    'Mainz 05': '1. FSV Mainz 05',
    'FC Koln': '1. FC K\u00f6ln',
    '1. FC Koln': '1. FC K\u00f6ln',
    'FC Heidenheim': '1. FC Heidenheim 1846',
    'Holstein Kiel': 'Holstein Kiel',
    'St Pauli': 'FC St. Pauli',
    'Paris Saint Germain': 'Paris Saint-Germain',
    'Paris Saint-Germain': 'Paris Saint-Germain',
    'Olympique Marseille': 'Olympique Marseille',
    'Olympique Lyonnais': 'Olympique Lyonnais',
    'AS Saint-Etienne': 'AS Saint-\u00c9tienne',
    'Saint-Etienne': 'AS Saint-\u00c9tienne',
    'Stade Rennais': 'Stade Rennais FC',
    'RC Lens': 'RC Lens',
    'RC Strasbourg': 'RC Strasbourg Alsace',
    'Toulouse FC': 'Toulouse FC',
    'Le Havre AC': 'Le Havre AC',
    'AJ Auxerre': 'AJ Auxerre',
    'FC Nantes': 'FC Nantes',
    'Angers SCO': 'Angers SCO',
}

results = []
unmatched = []
for tid, name in teams.items():
    if tid not in dataset_teams:
        continue
    comp = dataset_teams[tid]
    tm_comp = COMP_TO_TM[comp]
    candidates = clubs_by_comp.get(tm_comp, [])

    club_id = None
    lookup_name = OVERRIDES.get(name, name)
    for cid, cname in candidates:
        if cname == lookup_name:
            club_id = cid
            break
    if club_id is None:
        nn = norm(lookup_name)
        for cid, cname in candidates:
            if norm(cname) == nn:
                club_id = cid
                break
    if club_id is None:
        # substring match as last resort
        nn = norm(lookup_name)
        best = None
        for cid, cname in candidates:
            cnn = norm(cname)
            if nn and (nn in cnn or cnn in nn):
                best = (cid, cname)
                break
        if best:
            club_id = best[0]

    if club_id is None:
        unmatched.append((tid, name, comp))
    else:
        results.append((tid, name, comp, club_id))

print(f"Matched: {len(results)} / {len(dataset_teams)}")
print(f"Unmatched: {len(unmatched)}")
for u in unmatched:
    print("  UNMATCHED:", u)

with open(SCRATCH / 'team_club_map.tsv', 'w', encoding='utf-8') as out:
    for tid, name, comp, club_id in results:
        out.write(f"{tid}\t{name}\t{comp}\t{club_id}\n")
