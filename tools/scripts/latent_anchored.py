"""
Step C-LATENT-ANCHORED — experimental opt-in Latent mode.

Context: 'legacy' (core-only) is the approved production baseline. 'extended'
(joint re-fit of core+UEFA together) was benchmarked and REJECTED — it also
changed core teams' own Latent values and scored worse (logloss 0.9952 vs
0.9948 legacy). 'anchored' is architecturally different and lives in this
separate module (not inline in candidate47_feature_pipeline.py) to keep the
experimental surface isolated from the production-feeding module:

  - core teams' (bh, ba, attack, defence) are NEVER re-fit — they come
    verbatim, per walk-forward date, from the EXACT SAME legacy core-only
    fit used today (same _fit_latent() calls, same date-grouping, same
    prior_n ridge, same warm-start chaining — reused from
    candidate47_feature_pipeline, never duplicated).
  - ONLY non-core (UEFA) teams get a free parameter, optimized against a
    REDUCED likelihood where the core side of any match is a known constant.
  - No sum-to-zero reparametrization is needed for the free parameters:
    freezing the core side already removes the additive shift-invariance
    that sum-to-zero existed to resolve.
  - A non-core team is estimated ONLY if connected (directly or transitively
    through other non-core teams) to at least one core team via UEFA history
    strictly before the target's date — otherwise None, never an invented
    value. No new tuning: same prior_n=LATENT_PRIOR_N, same maxiter, same
    Poisson/WLL formula as the legacy fitter.

Public entry point: compute_latent_features_anchored(rows, core_history_rows,
uefa_history_rows=None, verbose=False) — see its docstring for the full
contract (targets vs core-history vs UEFA-history separation, anti-leakage).

Does not modify candidate47_feature_pipeline.py's legacy path in any way —
pure addition, reused via import only (_fit_latent, LATENT_PRIOR_N, MAXITER_WF).
"""
import bisect
import sys
import time
from collections import defaultdict
from pathlib import Path

import numpy as np
from scipy.optimize import minimize

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/scripts'))
import candidate47_feature_pipeline as pipe  # noqa: E402

_fit_latent = pipe._fit_latent
LATENT_PRIOR_N = pipe.LATENT_PRIOR_N
MAXITER_WF = pipe.MAXITER_WF


def _connected_to_core_component(prior_uefa_matches, core_team_set):
    """
    BFS over the undirected team graph induced by prior_uefa_matches
    (list of (home_id, away_id, hg, ag)), starting from every core team
    present in the graph.

    @return set of NON-core team ids reachable from at least one core team
            (directly or transitively through other non-core teams).
    """
    adj = defaultdict(set)
    all_teams = set()
    for h, a, _hg, _ag in prior_uefa_matches:
        adj[h].add(a)
        adj[a].add(h)
        all_teams.add(h)
        all_teams.add(a)

    visited = {t for t in all_teams if t in core_team_set}
    queue = list(visited)
    qi = 0
    while qi < len(queue):
        node = queue[qi]
        qi += 1
        for neighbor in adj[node]:
            if neighbor not in visited:
                visited.add(neighbor)
                queue.append(neighbor)

    return {t for t in visited if t not in core_team_set}


def _fit_latent_anchored(prior_uefa_matches, frozen_core, identified_noncore_ids,
                          prior_n=LATENT_PRIOR_N, maxiter=MAXITER_WF):
    """
    Fit ONLY attack/defence for identified_noncore_ids. bh, ba and every core
    team's attack/defence come from `frozen_core` (dict with 'bh', 'ba',
    'att': {team_id: value}, 'def': {team_id: value}) and are NEVER optimized.

    A match is usable only if BOTH its home and away team are either a known
    core team (in frozen_core['att']) or an identified non-core team — a match
    touching a non-identified non-core team carries no usable information for
    this fit and is silently dropped.

    Same WLL formula, same prior_n ridge, same zero-init convention as
    _fit_latent() — only the parameter vector is restricted to the free
    (non-core) attack/defence entries; no sum-to-zero reparametrization
    (unnecessary: freezing the core side already removes the additive
    shift-invariance that sum-to-zero existed to resolve).

    @return dict {team_id: (attack, defence)} for every id in identified_noncore_ids.
    """
    noncore_idx = {t: i for i, t in enumerate(identified_noncore_ids)}
    n_free = len(identified_noncore_ids)
    if n_free == 0:
        return {}

    bh, ba = frozen_core['bh'], frozen_core['ba']
    core_att, core_def = frozen_core['att'], frozen_core['def']

    usable = [
        m for m in prior_uefa_matches
        if (m[0] in core_att or m[0] in noncore_idx) and (m[1] in core_att or m[1] in noncore_idx)
    ]
    if not usable:
        return {t: (0.0, 0.0) for t in identified_noncore_ids}

    n = len(usable)
    xh = np.array([m[2] for m in usable], dtype=np.float64)
    xa = np.array([m[3] for m in usable], dtype=np.float64)

    home_att_fixed = np.zeros(n)
    home_def_fixed = np.zeros(n)
    away_att_fixed = np.zeros(n)
    away_def_fixed = np.zeros(n)
    home_free_idx = np.full(n, -1, dtype=np.int64)
    away_free_idx = np.full(n, -1, dtype=np.int64)

    for i, (h, a, _hg, _ag) in enumerate(usable):
        if h in core_att:
            home_att_fixed[i] = core_att[h]
            home_def_fixed[i] = core_def[h]
        else:
            home_free_idx[i] = noncore_idx[h]
        if a in core_att:
            away_att_fixed[i] = core_att[a]
            away_def_fixed[i] = core_def[a]
        else:
            away_free_idx[i] = noncore_idx[a]

    home_is_free = home_free_idx >= 0
    away_is_free = away_free_idx >= 0

    def gather(fixed, is_free, free_idx, free_arr):
        out = fixed.copy()
        out[is_free] = free_arr[free_idx[is_free]]
        return out

    def unpack(params):
        return params[:n_free], params[n_free:2 * n_free]

    def neg_wll(params):
        att_free, def_free = unpack(params)
        att_h = gather(home_att_fixed, home_is_free, home_free_idx, att_free)
        def_h = gather(home_def_fixed, home_is_free, home_free_idx, def_free)
        att_a = gather(away_att_fixed, away_is_free, away_free_idx, att_free)
        def_a = gather(away_def_fixed, away_is_free, away_free_idx, def_free)

        log_lh = bh + att_h + def_a
        log_la = ba + att_a + def_h
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        wll = (xh * log_lh - lh_v + xa * log_la - la_v).sum()
        return -(wll) + prior_n * (att_free ** 2).sum() + prior_n * (def_free ** 2).sum()

    def analytic_gradient(params):
        att_free, def_free = unpack(params)
        att_h = gather(home_att_fixed, home_is_free, home_free_idx, att_free)
        def_h = gather(home_def_fixed, home_is_free, home_free_idx, def_free)
        att_a = gather(away_att_fixed, away_is_free, away_free_idx, att_free)
        def_a = gather(away_def_fixed, away_is_free, away_free_idx, def_free)

        log_lh = bh + att_h + def_a
        log_la = ba + att_a + def_h
        lh_v = np.exp(np.clip(log_lh, -10, 5))
        la_v = np.exp(np.clip(log_la, -10, 5))
        rh = xh - lh_v
        ra = xa - la_v

        g_att_free = np.zeros(n_free)
        g_def_free = np.zeros(n_free)

        np.add.at(g_att_free, home_free_idx[home_is_free], -rh[home_is_free])
        np.add.at(g_att_free, away_free_idx[away_is_free], -ra[away_is_free])
        np.add.at(g_def_free, away_free_idx[away_is_free], -rh[away_is_free])
        np.add.at(g_def_free, home_free_idx[home_is_free], -ra[home_is_free])

        g_att_free += 2 * prior_n * att_free
        g_def_free += 2 * prior_n * def_free

        return np.concatenate([g_att_free, g_def_free])

    x0 = np.zeros(2 * n_free)
    res = minimize(neg_wll, x0, method='L-BFGS-B', jac=analytic_gradient,
                   options={'maxiter': maxiter, 'ftol': 1e-10, 'gtol': 1e-7})

    att_free, def_free = unpack(res.x)
    return {
        t: (float(att_free[noncore_idx[t]]), float(def_free[noncore_idx[t]]))
        for t in identified_noncore_ids
    }


def compute_latent_features_anchored(rows, core_history_rows, uefa_history_rows=None, verbose=False):
    """
    'anchored' mode — opt-in, never calls/alters candidate47_feature_pipeline's
    legacy compute_latent_features() in any way.

    rows: target matches to annotate (core-league matches, UEFA matches, or a
      mix — generic; NEVER added to core_history_rows/uefa_history_rows by
      this function, so a UEFA target never becomes its own history/anchor —
      history and target stay separate exactly as required).
    core_history_rows: the core-league match population whose OWN walk-forward
      fit defines the frozen core trajectory — IDENTICAL methodology/calls to
      compute_latent_features()'s legacy loop (same _fit_latent, same
      date-grouping, same warm-start chaining, reused by import). Typically
      the same population used to build the standard Candidate47 training
      targets.
    uefa_history_rows: extra CL/EL/ECL match results feeding ONLY the
      non-core graph/fit — never become targets, never alter
      core_history_rows' own trajectory. Caller must exclude any match_id
      already a target (see candidate47_feature_pipeline.load_latent_history_csv()'s
      exclude_match_ids) to avoid double-counting.

    @return dict row_index -> (attack_home, defence_home, attack_away, defence_away)
      — core-side entries are ALWAYS the frozen legacy value (never None; 0.0
      convention for a core team genuinely unseen yet, same as legacy);
      non-core-side entries are the anchored estimate when identifiable
      (directly or transitively connected to a core team via matches
      strictly before the target's date), otherwise None — never an
      invented/imputed value.
    """
    uefa_history_rows = uefa_history_rows or []

    core_team_ids = sorted(
        set(r['home_team_id'] for r in core_history_rows) | set(r['away_team_id'] for r in core_history_rows)
    )
    core_team_idx = {t: i for i, t in enumerate(core_team_ids)}
    core_team_set = set(core_team_ids)
    n_core_teams = len(core_team_ids)

    # ── Phase 1: precompute the frozen core trajectory — IDENTICAL math/calls
    # to compute_latent_features()'s own legacy loop (same _fit_latent, same
    # date-grouping, same warm-start chaining). trajectory_states[i] is the
    # state in effect STRICTLY BEFORE core_sorted_dates[i] (pre-existing
    # legacy semantics, unchanged) — looked up via bisect for any target or
    # UEFA-history date in Phase 2 below, so a target between two core dates
    # correctly sees everything strictly before it, never a stale snapshot.
    core_date_groups = defaultdict(list)
    for r in core_history_rows:
        core_date_groups[r['kickoff_at'][:10]].append(r)
    core_sorted_dates = sorted(core_date_groups.keys())

    trajectory_states = []
    prior_core_matches = []
    current_core_params = None
    fits_done_core = 0

    def _state_from(att, def_, bh, ba):
        return {
            'bh': bh, 'ba': ba,
            'att': {tid: float(att[core_team_idx[tid]]) for tid in core_team_ids},
            'def': {tid: float(def_[core_team_idx[tid]]) for tid in core_team_ids},
        }

    for date in core_sorted_dates:
        if len(prior_core_matches) == 0:
            bh, ba, att, def_ = _fit_latent([], n_core_teams, x0=current_core_params)
        else:
            m_arr = [(core_team_idx[m[0]], core_team_idx[m[1]], m[2], m[3]) for m in prior_core_matches]
            bh, ba, att, def_ = _fit_latent(m_arr, n_core_teams, x0=current_core_params)
            fits_done_core += 1
        current_core_params = (bh, ba, att, def_)
        trajectory_states.append(_state_from(att, def_, bh, ba))
        for r in core_date_groups[date]:
            prior_core_matches.append((r['home_team_id'], r['away_team_id'],
                                        int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))

    if prior_core_matches:
        m_arr = [(core_team_idx[m[0]], core_team_idx[m[1]], m[2], m[3]) for m in prior_core_matches]
        bh, ba, att, def_ = _fit_latent(m_arr, n_core_teams, x0=current_core_params)
    else:
        bh, ba, att, def_ = 0.35, 0.20, np.zeros(n_core_teams), np.zeros(n_core_teams)
    final_state = _state_from(att, def_, bh, ba)

    def frozen_core_at(date_str):
        """State strictly before date_str — bisect over core_sorted_dates."""
        j = bisect.bisect_left(core_sorted_dates, date_str)
        return trajectory_states[j] if j < len(trajectory_states) else final_state

    # ── Phase 2: UEFA history + targets — identified/connected non-core teams
    # get a fresh anchored fit per target date, frozen core looked up above.
    uefa_date_groups = defaultdict(list)
    for r in uefa_history_rows:
        uefa_date_groups[r['kickoff_at'][:10]].append(r)

    target_date_groups = defaultdict(list)
    for idx, r in enumerate(rows):
        target_date_groups[r['kickoff_at'][:10]].append((idx, r))

    sorted_dates = sorted(set(uefa_date_groups) | set(target_date_groups))

    prior_uefa_matches = []
    latent_feats = {}
    fits_done_anchor = 0
    t0 = time.perf_counter()

    for date in sorted_dates:
        target_group = target_date_groups.get(date, [])
        if target_group:
            frozen_core_state = frozen_core_at(date)

            needed_noncore = set()
            for _idx, r in target_group:
                if r['home_team_id'] not in core_team_set:
                    needed_noncore.add(r['home_team_id'])
                if r['away_team_id'] not in core_team_set:
                    needed_noncore.add(r['away_team_id'])

            identified = []
            anchored_values = {}
            if needed_noncore:
                connected = _connected_to_core_component(prior_uefa_matches, core_team_set)
                identified = sorted(needed_noncore & connected)
                if identified:
                    anchored_values = _fit_latent_anchored(prior_uefa_matches, frozen_core_state, identified)
                    fits_done_anchor += 1

            for idx, r in target_group:
                h, a = r['home_team_id'], r['away_team_id']
                if h in core_team_set:
                    att_h = frozen_core_state['att'].get(h, 0.0)
                    def_h = frozen_core_state['def'].get(h, 0.0)
                else:
                    att_h, def_h = anchored_values.get(h, (None, None))
                if a in core_team_set:
                    att_a = frozen_core_state['att'].get(a, 0.0)
                    def_a = frozen_core_state['def'].get(a, 0.0)
                else:
                    att_a, def_a = anchored_values.get(a, (None, None))
                latent_feats[idx] = (att_h, def_h, att_a, def_a)

        if date in uefa_date_groups:
            for r in uefa_date_groups[date]:
                prior_uefa_matches.append((r['home_team_id'], r['away_team_id'],
                                            int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))

    if verbose:
        print(f"Anchored: core fits={fits_done_core}  anchor fits={fits_done_anchor}  "
              f"elapsed={time.perf_counter() - t0:.2f}s")

    return latent_feats


def compute_current_anchored_snapshot(core_history_rows, uefa_history_rows=None):
    """
    Public "as of now" wrapper — NO new math: computes the frozen core
    trajectory's FINAL state (same _fit_latent calls, same date-grouping as
    compute_latent_features_anchored()'s own Phase 1) and, separately,
    identifies + anchored-fits every non-core team in uefa_history_rows that
    is connected (directly or transitively) to a core team — the exact same
    _connected_to_core_component()/_fit_latent_anchored() calls, evaluated
    ONCE over the full history (no walk-forward target dates involved) since
    there is no "target row" for a runtime snapshot — only "state as of the
    cutoff implied by core_history_rows/uefa_history_rows themselves" (both
    exported with the SAME --before cutoff by the caller).

    Used only by generate_latent_strength_snapshot.py --latent-mode=anchored.
    Never called by compute_latent_features_anchored() and does not change
    its behaviour in any way.

    @return {
      'core_team_ids': [...],
      'noncore_identified': [...],
      'teams': [{'team_id': int, 'attack': float, 'defence': float}, ...]
          — core teams (frozen legacy values) + identified non-core
          (anchored), in the SAME list-of-dicts schema as
          latent_strength_current.json's own 'teams' field. A non-core team
          that is not identifiable is simply ABSENT from this list — never
          an invented/null entry.
    }
    """
    uefa_history_rows = uefa_history_rows or []

    core_team_ids = sorted(
        set(r['home_team_id'] for r in core_history_rows) | set(r['away_team_id'] for r in core_history_rows)
    )
    core_team_idx = {t: i for i, t in enumerate(core_team_ids)}
    core_team_set = set(core_team_ids)
    n_core_teams = len(core_team_ids)

    # Legacy core fit over ALL core_history_rows — identical math/calls to
    # compute_latent_features_anchored()'s Phase-1 final_state computation.
    core_date_groups = defaultdict(list)
    for r in core_history_rows:
        core_date_groups[r['kickoff_at'][:10]].append(r)

    prior_core_matches = []
    current_core_params = None
    for date in sorted(core_date_groups.keys()):
        if len(prior_core_matches) == 0:
            bh, ba, att, def_ = _fit_latent([], n_core_teams, x0=current_core_params)
        else:
            m_arr = [(core_team_idx[m[0]], core_team_idx[m[1]], m[2], m[3]) for m in prior_core_matches]
            bh, ba, att, def_ = _fit_latent(m_arr, n_core_teams, x0=current_core_params)
        current_core_params = (bh, ba, att, def_)
        for r in core_date_groups[date]:
            prior_core_matches.append((r['home_team_id'], r['away_team_id'],
                                        int(float(r['label_home_goals'])), int(float(r['label_away_goals']))))

    if prior_core_matches:
        m_arr = [(core_team_idx[m[0]], core_team_idx[m[1]], m[2], m[3]) for m in prior_core_matches]
        bh, ba, att, def_ = _fit_latent(m_arr, n_core_teams, x0=current_core_params)
    else:
        bh, ba, att, def_ = 0.35, 0.20, np.zeros(n_core_teams), np.zeros(n_core_teams)

    frozen_core_state = {
        'bh': bh, 'ba': ba,
        'att': {tid: float(att[core_team_idx[tid]]) for tid in core_team_ids},
        'def': {tid: float(def_[core_team_idx[tid]]) for tid in core_team_ids},
    }

    # Non-core: identify connected teams over ALL uefa_history_rows, fit once.
    uefa_matches = [
        (r['home_team_id'], r['away_team_id'],
         int(float(r['label_home_goals'])), int(float(r['label_away_goals'])))
        for r in uefa_history_rows
    ]
    all_noncore_seen = {
        t for r in uefa_history_rows for t in (r['home_team_id'], r['away_team_id'])
        if t not in core_team_set
    }
    connected = _connected_to_core_component(uefa_matches, core_team_set) if all_noncore_seen else set()
    identified = sorted(all_noncore_seen & connected)

    anchored_values = {}
    if identified:
        anchored_values = _fit_latent_anchored(uefa_matches, frozen_core_state, identified)

    teams = (
        [
            {'team_id': int(tid), 'attack': frozen_core_state['att'][tid], 'defence': frozen_core_state['def'][tid]}
            for tid in core_team_ids
        ]
        + [
            {'team_id': int(tid), 'attack': anchored_values[tid][0], 'defence': anchored_values[tid][1]}
            for tid in identified
        ]
    )

    return {
        'core_team_ids': core_team_ids,
        'noncore_identified': identified,
        'teams': teams,
    }
