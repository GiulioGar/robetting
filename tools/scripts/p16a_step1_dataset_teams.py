import csv
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

with open(ROOT / 'tools/datasets/dataset_core_v1_2024_2025.csv', newline='', encoding='utf-8') as f:
    rows = list(csv.DictReader(f))

teams = {}
for r in rows:
    if r.get('label_home_goals', '') == '':
        continue
    for tid in (r['home_team_id'], r['away_team_id']):
        d = teams.setdefault(tid, {'comp': r['competition_id'], 'seasons': set()})
        d['seasons'].add(r['season_id'])

print(f"distinct teams: {len(teams)}")
SCRATCH = Path('C:/Users/Utente/AppData/Local/Temp/claude/C--xampp-htdocs-robetting/576da024-d3cc-4335-8cf3-bc0ea4e5f2ba/scratchpad')
with open(SCRATCH / 'dataset_teams.tsv', 'w', encoding='utf-8') as out:
    for tid, d in teams.items():
        out.write(f"{tid}\t{d['comp']}\t{','.join(sorted(d['seasons']))}\n")
