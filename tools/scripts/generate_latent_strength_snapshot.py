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
         [--latent-mode legacy|extended|anchored] [--output PATH] [--before ISO8601]
  PHP binary: --php, else env ROBETTING_PHP, else "php".

--latent-mode (default: legacy):
  'legacy'   — unchanged original behaviour (core leagues only).
  'extended' — forwarded verbatim to `robetting:export-latent-fit-matches
               --latent-mode=extended` (core leagues + UEFA Champions/Europa/
               Conference League, national cups excluded). REJECTED as a
               Candidate47 production mode (benchmarked worse than legacy) —
               kept here only for research/comparison snapshots.
  'anchored' — core teams' attack/defence/bh/ba come VERBATIM from the legacy
               core-only fit (never re-fit); ONLY non-core UEFA teams
               connected (directly or transitively) to a core team get an
               additional, separately-fitted value. See
               tools/scripts/latent_anchored.py for the math. Requires
               --output (must differ from latent_strength_current.json).

--output (default: tools/models/latent_strength_current.json) — override the
  output path. REQUIRED for --latent-mode=anchored, and required in practice
  for --latent-mode=extended: must NEVER point at the champion's
  latent_strength_current.json (no auto-suffixing — pass an explicit
  separate path, e.g. tools/models/latent_strength_champions_anchored.json).

--before (default: now) — explicit ISO8601 UTC cutoff. For --latent-mode=anchored
  this exact cutoff is used for BOTH the core-only and the core+UEFA extended
  export, so core and non-core history are evaluated as of the identical instant.
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

sys.path.insert(0, str(ROOT / 'tools/scripts'))
import latent_anchored  # noqa: E402 — Step C-LATENT-ANCHORED math, unmodified here.

FROM_SEASON = 2024
PRIOR_N = 3
MAXITER = 300
MODEL_VERSION = 'latent_ad_v1_hardsumzero_analyticjac'


def export_matches(php: str, cutoff: str, latent_mode: str) -> list:
    """Run the Artisan export (DB → CSV) and return rows sorted by kickoff."""
    with tempfile.TemporaryDirectory() as tmp:
        out = Path(tmp) / 'latent_fit_matches.csv'
        cmd = [php, 'artisan', 'robetting:export-latent-fit-matches',
               f'--before={cutoff}', f'--from-season={FROM_SEASON}', f'--latent-mode={latent_mode}', f'--output={out}']
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


def _to_latent_rows(export_rows: list) -> list:
    """Adapt export_matches() CSV-shaped rows (home_goals/away_goals) to the
    dict shape latent_anchored.py's functions expect (label_home_goals/
    label_away_goals) — pure I/O glue, no math."""
    return [
        {
            'home_team_id': r['home_team_id'],
            'away_team_id': r['away_team_id'],
            'kickoff_at': r['kickoff_at'],
            'label_home_goals': r['home_goals'],
            'label_away_goals': r['away_goals'],
        }
        for r in export_rows
    ]


def _run_anchored(args) -> int:
    """
    Step C-LATENT-ANCHORED wiring — NO new math here, only I/O glue around
    latent_anchored.compute_current_anchored_snapshot():
      1. core-league-only export (--latent-mode=legacy) -> frozen core trajectory input.
      2. core+UEFA extended export (--latent-mode=extended), minus any match_id
         already in the core export -> non-core graph/fit input (UEFA-only rows).
      3. compute_current_anchored_snapshot() -> core (frozen) + identified
         non-core (anchored) teams, same schema as latent_strength_current.json.
    SAME --before cutoff passed to both exports (core and non-core history
    evaluated as-of the identical instant — requirement 7, no leakage).
    """
    if not args.output:
        print("ERROR: --output is required for --latent-mode=anchored.", file=sys.stderr)
        return 1

    out_path = Path(args.output)
    if out_path.resolve() == OUT_PATH.resolve():
        print(
            "ERROR: --output must not be latent_strength_current.json for --latent-mode=anchored "
            "— refusing to overwrite the champion snapshot.",
            file=sys.stderr,
        )
        return 1

    cutoff = args.before or datetime.now(timezone.utc).replace(microsecond=0).strftime('%Y-%m-%dT%H:%M:%SZ')

    print(f"[anchored] Fetching CORE-league-only history (legacy eligibility) before {cutoff} ...")
    core_export_rows = export_matches(args.php, cutoff, 'legacy')
    print(f"[anchored] Core rows: {len(core_export_rows)}")

    if not core_export_rows:
        print("No core matches exported — snapshot NOT written.")
        return 1

    print(f"[anchored] Fetching CORE+UEFA extended history before {cutoff} ...")
    extended_export_rows = export_matches(args.php, cutoff, 'extended')
    print(f"[anchored] Extended rows: {len(extended_export_rows)}")

    core_match_ids = {r['match_id'] for r in core_export_rows}
    uefa_only_rows = [r for r in extended_export_rows if r['match_id'] not in core_match_ids]
    print(f"[anchored] UEFA-only history rows (extended minus core duplicates): {len(uefa_only_rows)}")

    core_history_rows = _to_latent_rows(core_export_rows)
    uefa_history_rows = _to_latent_rows(uefa_only_rows)

    snap = latent_anchored.compute_current_anchored_snapshot(core_history_rows, uefa_history_rows)

    core_team_ids = snap['core_team_ids']
    noncore_identified = snap['noncore_identified']
    all_noncore_seen = sorted({
        t for r in uefa_history_rows for t in (r['home_team_id'], r['away_team_id'])
        if t not in set(core_team_ids)
    })

    print(f"\nCore teams (frozen legacy values, unchanged): {len(core_team_ids)}")
    print(f"Non-core teams seen in UEFA history:          {len(all_noncore_seen)}")
    print(f"Non-core teams IDENTIFIED (connected, anchored): {len(noncore_identified)}")
    print(f"Non-core teams NOT identifiable (absent, never invented): "
          f"{len(all_noncore_seen) - len(noncore_identified)}")

    all_kickoffs = [r['kickoff_at'] for r in core_export_rows] + [r['kickoff_at'] for r in uefa_only_rows]
    last_match_included_at = max(all_kickoffs) if all_kickoffs else None

    payload = {
        'generated_at': cutoff,
        'last_match_included_at': last_match_included_at,
        'match_count_core': len(core_export_rows),
        'match_count_uefa_history': len(uefa_only_rows),
        'model_version': MODEL_VERSION,
        'source': 'database',
        'latent_mode': 'anchored',
        'methodology': {
            'history_from_season': f'{FROM_SEASON}/{(FROM_SEASON + 1) % 100:02d}',
            'core_selection': 'status=finished, valid FT score, core leagues only, kickoff_at < generated_at '
                              '— IDENTICAL to legacy (core attack/defence/bh/ba never re-fit by UEFA history)',
            'noncore_selection': 'core leagues + UEFA Champions/Europa/Conference League, kickoff_at < generated_at '
                                 '— used ONLY to identify+fit non-core teams connected (directly or '
                                 'transitively) to a core team; disconnected non-core teams are absent, '
                                 'never invented',
            'fitter': 'Poisson attack/defence, hard sum-to-zero (core only), reduced/anchored fit for '
                      'non-core (no sum-to-zero needed — see tools/scripts/latent_anchored.py)',
            'prior_n': PRIOR_N,
            'weights': 'uniform (no time decay)',
        },
        'teams': snap['teams'],
    }

    write_atomic(out_path, payload)
    print(f"\nSaved: {out_path}  (generated_at {cutoff})")
    print(f"Total teams in snapshot: {len(snap['teams'])} ({len(core_team_ids)} core + {len(noncore_identified)} anchored non-core)")

    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--php', default=os.environ.get('ROBETTING_PHP', 'php'))
    ap.add_argument('--dry-run', action='store_true', help='Fit and report, do not write the snapshot')
    ap.add_argument('--compare', default='', help='Comma-separated team names: print OLD vs NEW attack/defence')
    ap.add_argument('--latent-mode', default='legacy', choices=['legacy', 'extended', 'anchored'],
                     help='Competition population / fitting strategy (legacy|extended|anchored). '
                          'legacy/extended forward verbatim to robetting:export-latent-fit-matches '
                          '--latent-mode=.... anchored freezes core at the legacy fit and adds only '
                          'connected non-core (UEFA) teams — see tools/scripts/latent_anchored.py.')
    ap.add_argument('--output', default=None,
                     help='Override output path (default: tools/models/latent_strength_current.json). '
                          'Use a separate path (e.g. latent_strength_extended.json / '
                          'latent_strength_champions_anchored.json) for anything other than legacy, '
                          'to never overwrite the champion snapshot. REQUIRED for --latent-mode=anchored.')
    ap.add_argument('--before', default=None,
                     help='Explicit ISO8601 UTC cutoff (default: now). For --latent-mode=anchored this '
                          'SAME cutoff is used for both the core-only and the core+UEFA extended export, '
                          'so core and non-core history are evaluated as-of the identical instant.')
    args = ap.parse_args()

    if args.latent_mode == 'anchored':
        return _run_anchored(args)

    out_path = Path(args.output) if args.output else OUT_PATH

    generated_at = args.before or datetime.now(timezone.utc).replace(microsecond=0).strftime('%Y-%m-%dT%H:%M:%SZ')
    rows = export_matches(args.php, generated_at, args.latent_mode)
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

    write_atomic(out_path, {
        'generated_at': generated_at,
        'last_match_included_at': last_kickoff,
        'match_count': len(rows),
        'model_version': MODEL_VERSION,
        'source': 'database',
        'latent_mode': args.latent_mode,
        'methodology': {
            'history_from_season': f'{FROM_SEASON}/{(FROM_SEASON + 1) % 100:02d}',
            'selection': (
                "status=finished, valid FT score, kickoff_at < generated_at, "
                + ("core leagues only" if args.latent_mode == 'legacy'
                   else "core leagues + UEFA Champions/Europa/Conference League")
            ),
            'fitter': 'Poisson attack/defence, hard sum-to-zero, analytic jacobian, L-BFGS-B',
            'prior_n': PRIOR_N,
            'weights': 'uniform (no time decay)',
        },
        'teams': [
            {'team_id': tid, 'attack': float(att[team_idx[tid]]), 'defence': float(def_[team_idx[tid]])}
            for tid in team_ids
        ],
    })
    print(f"Saved: {out_path}  (generated_at {generated_at})")
    return 0


if __name__ == '__main__':
    sys.exit(main())
