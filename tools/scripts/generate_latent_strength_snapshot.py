"""
Generate the CURRENT Latent Attack/Defence strength snapshot FROM THE DATABASE.

Input: finished core-league results with kickoff_at < generated_at, seasons from
2024/25 on, exported by `php artisan robetting:export-latent-fit-matches` (same
selection policy as the prediction dataset: status 'finished' only, valid FT
score via MatchOutcomeLabelBuilder, awarded/walkover excluded).

Fitter (unchanged, validated P9H/P9I/P9J): single static fit, hard sum-to-zero
reparametrization + analytic jacobian, PRIOR_N=3 ridge, uniform weights
(xi=0, no time decay).

Output: tools/models/latent_strength_current.json
  generated_at            ISO8601 UTC — also the data cutoff (kickoff < generated_at)
  last_match_included_at  max kickoff_at among matches used
  match_count             number of matches used
  model_version, source, methodology
  teams: [{team_id, attack, defence}, ...]

Written atomically (temp file + os.replace) and only after a successful fit, so
a failed run never replaces the previous valid snapshot.

Anti-leakage at use time is enforced in PHP (CandidateModelService): a match may
only use this snapshot if generated_at < match.kickoff_at.

Usage:
  python tools/scripts/generate_latent_strength_snapshot.py [--php PATH] [--dry-run]
         [--compare "Real Madrid,Villarreal,..."]
  PHP binary: --php, else env ROBETTING_PHP, else "php".
"""

import argparse
import csv
import json
import math
import os
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from scipy.optimize import minimize

ROOT = Path(__file__).resolve().parents[2]
OUT_PATH = ROOT / 'tools/models/latent_strength_current.json'

FROM_SEASON = 2024
PRIOR_N = 3
MAXITER = 300
MODEL_VERSION = 'latent_ad_v1_hardsumzero_analyticjac'


def export_matches(php: str, cutoff: str) -> list:
    """Run the Artisan export (DB → CSV) and return rows sorted by kickoff."""
    with tempfile.TemporaryDirectory() as tmp:
        out = Path(tmp) / 'latent_fit_matches.csv'
        cmd = [php, 'artisan', 'robetting:export-latent-fit-matches',
               f'--before={cutoff}', f'--from-season={FROM_SEASON}', f'--output={out}']
        proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
        if proc.returncode != 0 or not out.exists():
            raise RuntimeError(f"Export failed ({proc.returncode}): {proc.stdout}\n{proc.stderr}")
        print(proc.stdout.strip())
        with open(out, newline='', encoding='utf-8') as f:
            rows = list(csv.DictReader(f))
    rows.sort(key=lambda r: (r['kickoff_at'], int(r['match_id'])))
    return rows


def fit(rows: list):
    team_ids = sorted({int(r['home_team_id']) for r in rows} | {int(r['away_team_id']) for r in rows})
    team_idx = {t: i for i, t in enumerate(team_ids)}
    N = len(team_ids)

    homes = np.array([team_idx[int(r['home_team_id'])] for r in rows], dtype=np.int64)
    aways = np.array([team_idx[int(r['away_team_id'])] for r in rows], dtype=np.int64)
    xh = np.array([float(r['home_goals']) for r in rows], dtype=np.float64)
    xa = np.array([float(r['away_goals']) for r in rows], dtype=np.float64)

    def expand(free):
        return np.concatenate([free, [-free.sum()]])

    def neg_wll(params):
        bh, ba = params[0], params[1]
        att = expand(params[2:2 + (N - 1)])
        def_ = expand(params[2 + (N - 1):2 + 2 * (N - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        wll = (xh * log_lh - lh_v + xa * log_la - la_v).sum()
        return -(wll) + PRIOR_N * (att ** 2).sum() + PRIOR_N * (def_ ** 2).sum()

    def analytic_gradient(params):
        bh, ba = params[0], params[1]
        att = expand(params[2:2 + (N - 1)])
        def_ = expand(params[2 + (N - 1):2 + 2 * (N - 1)])
        log_lh = bh + att[homes] + def_[aways]
        log_la = ba + att[aways] + def_[homes]
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        rh = xh - lh_v
        ra = xa - la_v
        g_bh = -rh.sum()
        g_ba = -ra.sum()
        g_att_full = -(np.bincount(homes, weights=rh, minlength=N) + np.bincount(aways, weights=ra, minlength=N)) + 2 * PRIOR_N * att
        g_def_full = -(np.bincount(aways, weights=rh, minlength=N) + np.bincount(homes, weights=ra, minlength=N)) + 2 * PRIOR_N * def_
        return np.concatenate([[g_bh, g_ba], g_att_full[:-1] - g_att_full[-1], g_def_full[:-1] - g_def_full[-1]])

    x0 = np.zeros(2 + 2 * (N - 1))
    x0[0] = 0.35
    x0[1] = 0.20

    t0 = time.perf_counter()
    res = minimize(neg_wll, x0, method='L-BFGS-B', jac=analytic_gradient,
                   options={'maxiter': MAXITER, 'ftol': 1e-10, 'gtol': 1e-7})
    elapsed = time.perf_counter() - t0

    att = expand(res.x[2:2 + (N - 1)])
    def_ = expand(res.x[2 + (N - 1):2 + 2 * (N - 1)])
    return res, elapsed, team_ids, team_idx, att, def_


def write_atomic(path: Path, payload: dict) -> None:
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix=path.name, suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as f:
            json.dump(payload, f, indent=2)
            f.write('\n')
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.remove(tmp)
        raise


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--php', default=os.environ.get('ROBETTING_PHP', 'php'))
    ap.add_argument('--dry-run', action='store_true', help='Fit and report, do not write the snapshot')
    ap.add_argument('--compare', default='', help='Comma-separated team names: print OLD vs NEW attack/defence')
    args = ap.parse_args()

    generated_at = datetime.now(timezone.utc).replace(microsecond=0).strftime('%Y-%m-%dT%H:%M:%SZ')
    rows = export_matches(args.php, generated_at)
    if not rows:
        print('No matches exported — snapshot NOT written.')
        return 1

    res, elapsed, team_ids, team_idx, att, def_ = fit(rows)
    last_kickoff = rows[-1]['kickoff_at']
    ok = bool(res.success) and np.all(np.isfinite(att)) and np.all(np.isfinite(def_))

    print(f"Finished valid matches (DB): {len(rows)}")
    print(f"Last match included:         {last_kickoff}")
    print(f"Teams with latent:           {len(team_ids)}")
    print(f"Fit success={res.success} nit={res.nit} elapsed={elapsed:.3f}s  message={res.message}")

    if args.compare:
        names = {}
        for r in rows:
            names[r['home_team_name']] = int(r['home_team_id'])
            names[r['away_team_name']] = int(r['away_team_id'])
        old = {}
        if OUT_PATH.exists():
            old_snap = json.loads(OUT_PATH.read_text(encoding='utf-8'))
            old = {int(t['team_id']): t for t in old_snap['teams']}
            print(f"OLD snapshot: generated_at={old_snap.get('generated_at')} "
                  f"last_match={old_snap.get('last_match_included_at')} "
                  f"matches={old_snap.get('match_count', old_snap.get('matches_used'))}")
        print(f"{'team':<16}{'att OLD':>9}{'att NEW':>9}{'Δatt':>9}{'def OLD':>9}{'def NEW':>9}{'Δdef':>9}")
        for name in [n.strip() for n in args.compare.split(',') if n.strip()]:
            tid = names.get(name)
            if tid is None:
                print(f"{name:<16}  not found in exported matches")
                continue
            na, nd = float(att[team_idx[tid]]), float(def_[team_idx[tid]])
            o = old.get(tid)
            oa, od = (float(o['attack']), float(o['defence'])) if o else (math.nan, math.nan)
            print(f"{name:<16}{oa:>9.4f}{na:>9.4f}{na - oa:>+9.4f}{od:>9.4f}{nd:>9.4f}{nd - od:>+9.4f}")

    if not ok:
        print('Fit NOT successful — previous snapshot left untouched.')
        return 1
    if args.dry_run:
        print('Dry run — snapshot NOT written.')
        return 0

    write_atomic(OUT_PATH, {
        'generated_at': generated_at,
        'last_match_included_at': last_kickoff,
        'match_count': len(rows),
        'model_version': MODEL_VERSION,
        'source': 'database',
        'methodology': {
            'history_from_season': f'{FROM_SEASON}/{(FROM_SEASON + 1) % 100:02d}',
            'selection': "status=finished, valid FT score, core leagues, kickoff_at < generated_at",
            'fitter': 'Poisson attack/defence, hard sum-to-zero, analytic jacobian, L-BFGS-B',
            'prior_n': PRIOR_N,
            'weights': 'uniform (no time decay)',
        },
        'teams': [
            {'team_id': tid, 'attack': float(att[team_idx[tid]]), 'defence': float(def_[team_idx[tid]])}
            for tid in team_ids
        ],
    })
    print(f"Saved: {OUT_PATH}  (generated_at {generated_at})")
    return 0


if __name__ == '__main__':
    sys.exit(main())
