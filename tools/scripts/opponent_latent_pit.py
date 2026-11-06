"""
Step 73 — experimental Opponent Latent PIT features.

Describes how strong the opponents in a team's Recent window were, using the
anchored Latent attack/defence estimate each opponent had STRICTLY BEFORE the
date of the match it played against the team (point-in-time), never today's
snapshot.

NO new fitting math. Everything is delegated to latent_anchored.py:
  - core teams        : frozen legacy walk-forward state strictly before the date;
  - non-core teams    : anchored fit over UEFA history strictly before the date,
                        free parameters = EVERY non-core team with prior history
                        (the "probe rows" trick, see _build_probe_rows) so the
                        joint fit equals the one behind the production snapshot
                        (verified: bit-identical at the snapshot cutoff).

Why probe rows: compute_latent_features_anchored() only frees the non-core
teams that appear in the target rows of a date; matches against any other
non-core team are dropped, which silently yields 0.0 for teams whose opponents
are all non-core. Passing one probe row per non-core team restores the joint fit.

Conventions (explicit, no imputation):
  - attack and defence are returned and averaged separately (defence is the
    Latent defence parameter: lower = stronger defence); no attack-defence
    composite, no goal/shot coefficient;
  - an opponent without an estimate (never connected to a core team, or no
    result in the history before the date) is MISSING: it is excluded from the
    means and counted in matches_total - matches_covered; never replaced by 0;
  - a core team with no core-league match before the date carries only the
    ridge prior (0.0): treated as MISSING for the same reason;
  - date granularity is the one of latent_anchored (kickoff_at[:10]): results of
    the same calendar day are NOT used.

Support: number of history matches (core-league + UEFA, as fed to the fit) of the
opponent strictly before the date. It is a count, not an uncertainty; no
threshold or reliability score is derived from it.

Single entry point for training examples and live prediction:
  compute_opponent_latent_features(windows, core_history_rows, uefa_history_rows)

Run as a script for a JSON batch:
  py -3 tools/scripts/opponent_latent_pit.py --legacy-csv L.csv --extended-csv E.csv \
      --windows-json W.json --out OUT.json
(CSVs from `robetting:export-latent-fit-matches --latent-mode=legacy|extended`
with the same --before cutoff.)
"""
import argparse
import bisect
import csv
import json
import sys
import time
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))

import candidate47_feature_pipeline as pipe  # noqa: E402
import latent_anchored as anchored  # noqa: E402

DEFAULT_WINDOW = 10


def _day(kickoff_at):
    return str(kickoff_at)[:10]


def load_fit_histories(legacy_csv, extended_csv):
    """(core_history_rows, uefa_history_rows) from two ExportLatentFitMatches CSVs.

    The extended export re-includes every core match, so core match_ids are
    excluded from the UEFA history (same guard as load_latent_history_csv)."""
    with open(legacy_csv, newline='', encoding='utf-8') as f:
        core_ids = {r['match_id'] for r in csv.DictReader(f)}
    core = pipe.load_latent_history_csv(legacy_csv, set())
    uefa = pipe.load_latent_history_csv(extended_csv, core_ids)
    return core, uefa


def recent_window(team_matches, ref_kickoff, n=DEFAULT_WINDOW):
    """Last n matches strictly before ref_kickoff, newest first.

    team_matches: list of dicts with 'kickoff_at', 'opponent_id' (any order),
    already limited to the matches counted by the caller's Recent definition.
    Returns [(date 'YYYY-MM-DD', opponent_id as str), ...]."""
    ref = str(ref_kickoff)[:19].replace('T', ' ')
    prior = [m for m in team_matches if str(m['kickoff_at'])[:19].replace('T', ' ') < ref]
    prior.sort(key=lambda m: str(m['kickoff_at'])[:19].replace('T', ' '), reverse=True)
    return [(_day(m['kickoff_at']), str(m['opponent_id'])) for m in prior[:n]]


def _team_dates(core_history_rows, uefa_history_rows):
    idx = defaultdict(list)
    for rows in (core_history_rows, uefa_history_rows):
        for r in rows:
            d = _day(r['kickoff_at'])
            idx[r['home_team_id']].append(d)
            idx[r['away_team_id']].append(d)
    for v in idx.values():
        v.sort()
    return idx


def support_matches(team_dates, team_id, date):
    """History matches of team_id strictly before date (date granularity)."""
    return bisect.bisect_left(team_dates.get(team_id, ()), date)


def _build_probe_rows(requested_by_date, core_team_set, noncore_first):
    """One probe row per (date, requested core team) and per (date, non-core team
    with prior history). Probe rows are targets only: latent_anchored never adds
    them to the history."""
    anchor = sorted(core_team_set)[0]
    rows, slots = [], {}
    for date in sorted(requested_by_date):
        for t in sorted(requested_by_date[date] & core_team_set):
            slots[(t, date)] = (len(rows), 'home')
            rows.append({'home_team_id': t, 'away_team_id': anchor, 'kickoff_at': date + 'T12:00:00+00:00',
                         'label_home_goals': '0', 'label_away_goals': '0'})
        for t, first in noncore_first:
            if first >= date:
                continue  # no history strictly before date -> cannot be connected
            slots[(t, date)] = (len(rows), 'home')
            rows.append({'home_team_id': t, 'away_team_id': anchor, 'kickoff_at': date + 'T12:00:00+00:00',
                         'label_home_goals': '0', 'label_away_goals': '0'})
    return rows, slots


def build_pit_lookup(requests, core_history_rows, uefa_history_rows):
    """Point-in-time Latent for each requested (team_id, 'YYYY-MM-DD').

    @return {(team_id, date): (attack, defence) | None}
      None = no estimate available strictly before date (missing, never 0.0)."""
    if not core_history_rows:
        raise ValueError('core_history_rows is empty: the frozen core trajectory cannot be built')
    requests = {(str(t), _day(d)) for t, d in requests}
    if not requests:
        return {}
    core_team_set = set(r['home_team_id'] for r in core_history_rows) | set(r['away_team_id'] for r in core_history_rows)
    team_dates = _team_dates(core_history_rows, uefa_history_rows)
    noncore = {t for r in uefa_history_rows for t in (r['home_team_id'], r['away_team_id'])} - core_team_set
    noncore_first = sorted((t, team_dates[t][0]) for t in noncore)

    by_date = defaultdict(set)
    for t, d in requests:
        by_date[d].add(t)
    probe_rows, slots = _build_probe_rows(by_date, core_team_set, noncore_first)
    feats = anchored.compute_latent_features_anchored(probe_rows, core_history_rows, uefa_history_rows)

    out = {}
    for t, d in requests:
        n_prior = support_matches(team_dates, t, d)
        slot = slots.get((t, d))
        if n_prior == 0 or slot is None:
            out[(t, d)] = None
            continue
        att, de = feats[slot[0]][0], feats[slot[0]][1]
        out[(t, d)] = (att, de) if att is not None and de is not None else None
    return out


def summarize_window(opponents, lookup, team_dates):
    """Features of one Recent window. opponents: [(date, opponent_id), ...]."""
    atts, defs, support, missing = [], [], [], []
    for date, opp in opponents:
        date, opp = _day(date), str(opp)
        v = lookup.get((opp, date))
        if v is None:
            missing.append(opp)
            continue
        atts.append(v[0])
        defs.append(v[1])
        support.append(support_matches(team_dates, opp, date))
    k = len(atts)
    return {
        'matches_total': len(opponents),
        'matches_covered': k,
        'opp_latent_attack_mean': (sum(atts) / k) if k else None,
        'opp_latent_defence_mean': (sum(defs) / k) if k else None,
        'opp_support_matches': support,
        'opp_support_min': min(support) if k else None,
        'opp_support_mean': (sum(support) / k) if k else None,
        'missing_opponent_ids': missing,
    }


def compute_opponent_latent_features(windows, core_history_rows, uefa_history_rows):
    """Batch entry point (training and prediction use this same function).

    windows: {key: [(date, opponent_id), ...]} — each window already restricted by the
    caller to matches before its reference time (see recent_window()).
    @return {key: summarize_window(...)}; one core trajectory, one anchored fit per date."""
    requests = {(str(o), _day(d)) for opps in windows.values() for d, o in opps}
    lookup = build_pit_lookup(requests, core_history_rows, uefa_history_rows)
    team_dates = _team_dates(core_history_rows, uefa_history_rows)
    return {k: summarize_window(opps, lookup, team_dates) for k, opps in windows.items()}


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[1])
    ap.add_argument('--legacy-csv', required=True)
    ap.add_argument('--extended-csv', required=True)
    ap.add_argument('--windows-json', required=True, help='{key: [[date, opponent_id], ...]}')
    ap.add_argument('--out', required=True)
    a = ap.parse_args(argv)
    core, uefa = load_fit_histories(a.legacy_csv, a.extended_csv)
    with open(a.windows_json, encoding='utf-8') as f:
        windows = {k: [(d, o) for d, o in v] for k, v in json.load(f).items()}
    t0 = time.perf_counter()
    res = compute_opponent_latent_features(windows, core, uefa)
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, indent=1)
    print('windows=%d elapsed=%.1fs' % (len(res), time.perf_counter() - t0))


if __name__ == '__main__':
    main()
