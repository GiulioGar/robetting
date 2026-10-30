"""
GOAL B-P1 - prospective comparison  A: Bivariate Poisson (lambda3 = 0.15)  vs  B: independent Poissons.
Implements section 12 of knowledge/GOAL_RESEARCH_PROTOCOL.md. READ-ONLY on CSV exports; standard library only;
no DB/API, no model, no fit, no lambda change. Writes nothing unless --report-file is given.

INPUTS (CSV with a header row; exports to be produced read-only from the DB at analysis time)
  --predictions  one row per official prediction + match info. Columns:
      id, match_id, competition_id, purpose, model_key, model_version, artifact_sha256,
      generated_at, kickoff_at (prediction row), match_kickoff_at (current kickoff of the match),
      match_status, home_score_ft, away_score_ft, lambda_home, lambda_away, lambda3,
      latent_snapshot_generated_at, is_playoff (1/true = playoff / spareggio; 0/false otherwise)
      optional: structural_snapshot_version, has_availability
  --eligible     (optional) the DENOMINATOR: every core-league match of the season, one row per match. Columns:
      match_id, competition_id, kickoff_at, status, home_score_ft, away_score_ft, is_playoff
      Used only for coverage (eligible = non-playoff core competition match, kickoff >= T_freeze, finished, valid 90' score).
  The reference exporter is app/Console/Commands/ExportBp1Sample.php (read-only; is_playoff = round NOT like
  'Regular Season - N', the verifiable label rule).
  Timestamps are ISO-8601; naive values are read as UTC. 'NULL'/'' are treated as missing.

SAMPLE (protocol 12.3), a row is included only if ALL hold (first failing condition is its exclusion reason):
  purpose=official; model_key=candidate47_structural_log; model_version=1.1.0; artifact_sha256 = full SHA below;
  generated_at < kickoff_at; kickoff_at == match_kickoff_at; kickoff_at >= T_freeze; competition in 15..19;
  NOT a playoff (is_playoff = 0); match_status=finished; integer 90' score >= 0;
  lambda_home/away finite > 0, lambda3 == 0.15, lambda_i - lambda3 > 0;
  then ONE row per match (the first by generated_at, then id; the others are counted as duplicate_match).
  Playoff matches are EXCLUDED from the primary analysis (reason 'playoff'); those passing every other filter are
  reported in a separate DESCRIPTIVE block (N and mean dNLL, no CI, never part of the verdict).

METRICS (12.4). Primary: dNLL = mean(NLL_A - NLL_B) of the exact total T = home + away, negative = A better,
two-sided 95% percentile CI from a bootstrap of ISO-calendar-week blocks (all leagues together, blocks sorted
chronologically, 2000 reps, seed 20261031). Everything else is DESCRIPTIVE: RPS(T), LogLoss Over 2.5/3.5/4.5,
BTTS (closed form) LogLoss/Brier, exact-score NLL, full 1X2 LogLoss (grid 0..39, no 0..10 truncation, no
renormalisation), T distribution 0..5+, P(draw) vs frequency, per-league N and dNLL, per-latent-snapshot N/blocks/dNLL.
Formulas are the ones of goal_b1_total_goals_bp.py / goal_b2_joint_dependence_bp.py (copied, unchanged).

VERDICT (12.5), from the primary CI only: CI < 0 -> evidence for A; CI > 0 -> evidence for B; CI contains 0 ->
INCONCLUSIVE (no equivalence is ever declared). Technical warnings (insufficient N/blocks, missing denominator ...)
are printed but never change the verdict; their thresholds are reporting parameters, not protocol criteria.

Usage:
  py -I tools/scripts/goal_bp1_prospective_analysis.py --predictions P.csv --t-freeze 2026-11-01T12:00:00Z [--eligible E.csv]
  py -I tools/scripts/goal_bp1_prospective_analysis.py --self-test        (synthetic data only)
Freeze: record T_freeze (UTC, taken at freeze time), sha256 of this script and of the protocol (the report prints the
script hash and the input-file hashes + a hash of the extracted sample).
"""
import argparse
import csv
import hashlib
import math
import os
import random
import sys
import tempfile
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone

MODEL_KEY = 'candidate47_structural_log'
MODEL_VERSION = '1.1.0'
ARTIFACT_SHA = '9c11330ed725d20c1609098643051d69b12ab717e2688e9116b2a2d4fa7135d8'
CORE_COMPETITIONS = {15, 16, 17, 18, 19}
LAMBDA3 = 0.15
M = 80          # pmf support of T: 0..M-1
G = 40          # joint grid 0..G-1 per team
EPS = 1e-18
DEFAULT_REPS = 2000
DEFAULT_SEED = 20261031
WARN_MIN_N = 100       # reporting warning only, not a protocol criterion
WARN_MIN_BLOCKS = 20   # reporting warning only, not a protocol criterion

PRED_REQUIRED = ['id', 'match_id', 'competition_id', 'purpose', 'model_key', 'model_version', 'artifact_sha256',
                 'generated_at', 'kickoff_at', 'match_kickoff_at', 'match_status', 'home_score_ft', 'away_score_ft',
                 'lambda_home', 'lambda_away', 'lambda3', 'latent_snapshot_generated_at', 'is_playoff']
ELIG_REQUIRED = ['match_id', 'competition_id', 'kickoff_at', 'status', 'home_score_ft', 'away_score_ft', 'is_playoff']
REASON_ORDER = ['not_official', 'wrong_model_key', 'wrong_version', 'wrong_sha', 'invalid_timestamp', 'not_pre_match',
                'kickoff_changed', 'before_freeze', 'not_core_competition', 'invalid_playoff_flag', 'playoff',
                'not_finished', 'invalid_score', 'invalid_lambda', 'duplicate_match']
PRIMARY = 'nll_T'
METRICS = [('nll_T', 'NLL(T) exact total [PRIMARY]'), ('rps_T', 'RPS(T)'), ('ll_o25', 'LogLoss Over 2.5'),
           ('ll_o35', 'LogLoss Over 3.5'), ('ll_o45', 'LogLoss Over 4.5'), ('ll_btts', 'LogLoss BTTS yes'),
           ('br_btts', 'Brier BTTS yes'), ('nll_score', 'NLL exact score'), ('ll_1x2', 'LogLoss 1X2 (full)')]


# ----------------------------------------------------------------------------- math (copied from B1/B2)
def log_pois(k, mu):
    return k * math.log(mu) - mu - math.lgamma(k + 1)


def pmf_independent(lam):
    return [math.exp(log_pois(t, lam)) for t in range(M)]


def pmf_bivariate(lh, la, l3):
    mu = lh + la - 2.0 * l3
    pm = [log_pois(t, mu) for t in range(M)]
    out = []
    for t in range(M):
        s = 0.0
        for k in range(t // 2 + 1):
            s += math.exp(log_pois(k, l3) + pm[t - 2 * k])
        out.append(s)
    return out


def total_scores(pmf, t_obs):
    nll = -math.log(pmf[t_obs])
    cum, rps = 0.0, 0.0
    for k in range(M - 1):
        cum += pmf[k]
        rps += (cum - (1.0 if t_obs <= k else 0.0)) ** 2
    return nll, rps


def pois_arr(mu, n):
    if mu <= 0:
        return [1.0] + [0.0] * (n - 1)
    lm = math.log(mu)
    return [math.exp(k * lm - mu - math.lgamma(k + 1)) for k in range(n)]


def joint_grid(lh, la, l3):
    p1, p2 = pois_arr(lh - l3, G), pois_arr(la - l3, G)
    if l3 <= 0:
        return [[p1[x] * p2[y] for y in range(G)] for x in range(G)]
    p3 = pois_arr(l3, G)
    kmax = max(k for k in range(G) if p3[k] >= EPS)
    return [[sum(p3[k] * p1[x - k] * p2[y - k] for k in range(min(x, y, kmax) + 1)) for y in range(G)] for x in range(G)]


def log_joint(x, y, lh, la, l3):
    if l3 <= 0:
        return (x * math.log(lh) - lh - math.lgamma(x + 1)) + (y * math.log(la) - la - math.lgamma(y + 1))
    l1, l2 = lh - l3, la - l3
    terms = []
    for k in range(min(x, y) + 1):
        terms.append(k * math.log(l3) - l3 - math.lgamma(k + 1)
                     + (x - k) * math.log(l1) - l1 - math.lgamma(x - k + 1)
                     + (y - k) * math.log(l2) - l2 - math.lgamma(y - k + 1))
    m = max(terms)
    return m + math.log(sum(math.exp(t - m) for t in terms))


def p_btts(lh, la, l3):
    return 1.0 - math.exp(-lh) - math.exp(-la) + math.exp(-(lh + la - l3))


def result_probs(grid):
    ph = pd = pa = 0.0
    for x in range(G):
        for y in range(G):
            if x > y:
                ph += grid[x][y]
            elif x == y:
                pd += grid[x][y]
            else:
                pa += grid[x][y]
    return ph, pd, pa


def _ll(p, y):
    return -math.log(p if y else 1.0 - p)


def row_metrics(lh, la, l3, hg, ag):
    """All per-match scores for A (lambda3 = l3) and B (lambda3 = 0); returns dict with _a/_b/d_ keys + extras."""
    t = hg + ag
    y_btts = 1 if (hg > 0 and ag > 0) else 0
    res = 0 if hg > ag else (1 if hg == ag else 2)
    out = {'t': t, 'btts': y_btts, 'draw': 1 if res == 1 else 0}
    resid = 0.0
    for name, l3_ in (('a', l3), ('b', 0.0)):
        pmf = pmf_bivariate(lh, la, l3_) if l3_ > 0 else pmf_independent(lh + la)
        nll, rps = total_scores(pmf, t)
        grid = joint_grid(lh, la, l3_)
        probs = result_probs(grid)
        resid = max(resid, abs(1.0 - sum(probs)), abs(1.0 - sum(pmf)))
        pb = p_btts(lh, la, l3_)
        out['nll_T_' + name], out['rps_T_' + name] = nll, rps
        for line, k in (('o25', 3), ('o35', 4), ('o45', 5)):
            out[f'll_{line}_{name}'] = _ll(sum(pmf[k:]), 1 if t >= k else 0)
        out['ll_btts_' + name] = _ll(pb, y_btts)
        out['br_btts_' + name] = (pb - y_btts) ** 2
        out['nll_score_' + name] = -log_joint(hg, ag, lh, la, l3_)
        out['ll_1x2_' + name] = -math.log(probs[res])
        out['pdraw_' + name] = probs[1]
        out['pmf_' + name] = pmf
    out['resid'] = resid
    for k, _ in METRICS:
        out['d_' + k] = out[k + '_a'] - out[k + '_b']
    return out


# ----------------------------------------------------------------------------- inputs / filters
def val(row, col):
    v = row.get(col)
    if v is None:
        return None
    v = v.strip()
    return None if v in ('', 'NULL', 'null', 'None') else v


def parse_utc(s):
    s = s.strip().replace('Z', '+00:00')
    if ' ' in s and 'T' not in s:
        s = s.replace(' ', 'T', 1)
    d = datetime.fromisoformat(s)
    return d.replace(tzinfo=timezone.utc) if d.tzinfo is None else d.astimezone(timezone.utc)


def to_int(s):
    try:
        f = float(s)
    except (TypeError, ValueError):
        return None
    return int(f) if f.is_integer() and f >= 0 else None


def read_csv(path, required):
    with open(path, newline='', encoding='utf-8') as f:
        rd = csv.DictReader(f)
        missing = [c for c in required if c not in (rd.fieldnames or [])]
        if missing:
            raise SystemExit(f'{path}: missing columns {missing}')
        return list(rd)


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def playoff_flag(r):
    """True / False, or None when the flag is not an unambiguous boolean (never guessed)."""
    v = (val(r, 'is_playoff') or '').lower()
    return True if v in ('1', 'true', 'yes') else False if v in ('0', 'false', 'no') else None


def classify(r, t_freeze, allow_playoff=False):
    """-> (reason or None, parsed dict). Reasons follow REASON_ORDER (duplicates are handled afterwards).
    allow_playoff=True only lets playoff matches through, for the separate descriptive block."""
    if val(r, 'purpose') != 'official':
        return 'not_official', None
    if val(r, 'model_key') != MODEL_KEY:
        return 'wrong_model_key', None
    if val(r, 'model_version') != MODEL_VERSION:
        return 'wrong_version', None
    if val(r, 'artifact_sha256') != ARTIFACT_SHA:
        return 'wrong_sha', None
    try:
        gen, ko, mko = parse_utc(val(r, 'generated_at')), parse_utc(val(r, 'kickoff_at')), parse_utc(val(r, 'match_kickoff_at'))
    except (AttributeError, TypeError, ValueError):
        return 'invalid_timestamp', None
    if not gen < ko:
        return 'not_pre_match', None
    if ko != mko:
        return 'kickoff_changed', None
    if ko < t_freeze:
        return 'before_freeze', None
    comp = to_int(val(r, 'competition_id'))
    if comp not in CORE_COMPETITIONS:
        return 'not_core_competition', None
    flag = playoff_flag(r)
    if flag is None:
        return 'invalid_playoff_flag', None
    if flag and not allow_playoff:
        return 'playoff', None
    if val(r, 'match_status') != 'finished':
        return 'not_finished', None
    hg, ag = to_int(val(r, 'home_score_ft')), to_int(val(r, 'away_score_ft'))
    if hg is None or ag is None or hg + ag >= M - 1 or max(hg, ag) >= G - 1:
        return 'invalid_score', None
    try:
        lh, la, l3 = float(val(r, 'lambda_home')), float(val(r, 'lambda_away')), float(val(r, 'lambda3'))
    except (TypeError, ValueError):
        return 'invalid_lambda', None
    if not (all(math.isfinite(v) for v in (lh, la, l3)) and lh > 0 and la > 0 and abs(l3 - LAMBDA3) < 1e-12
            and lh - l3 > 0 and la - l3 > 0):
        return 'invalid_lambda', None
    return None, {'id': to_int(val(r, 'id')), 'match_id': val(r, 'match_id'), 'comp': comp, 'gen': gen, 'ko': ko,
                  'week': ko.isocalendar()[:2], 'hg': hg, 'ag': ag, 'lh': lh, 'la': la, 'l3': l3,
                  'snap': val(r, 'latent_snapshot_generated_at') or 'n/a'}


# ----------------------------------------------------------------------------- statistics
def mean(xs):
    return sum(xs) / len(xs)


def block_bootstrap(rows, keys, reps, seed):
    """Joint percentile CIs: blocks = ISO week (sorted), same resamples for all keys."""
    parts = defaultdict(lambda: [[] for _ in keys])
    for r in rows:
        for i, k in enumerate(keys):
            parts[r['week']][i].append(r[k])
    # math.fsum: exactly rounded block sums, so the result does not depend on the row order
    bl = [[len(parts[w][0])] + [math.fsum(v) for v in parts[w]] for w in sorted(parts)]
    nb = len(bl)
    rng = random.Random(seed)
    dist = [[] for _ in keys]
    for _ in range(reps):
        n, s = 0, [0.0] * len(keys)
        for _ in range(nb):
            b = bl[rng.randrange(nb)]
            n += b[0]
            for i in range(len(keys)):
                s[i] += b[1 + i]
        for i in range(len(keys)):
            dist[i].append(s[i] / n)
    cis = []
    for d in dist:
        d.sort()
        cis.append((d[int(0.025 * reps)], d[int(0.975 * reps) - 1]))
    return cis, nb


def verdict(lo, hi):
    if hi < 0:
        return 'EVIDENCE FOR A (Bivariate Poisson): CI entirely < 0'
    if lo > 0:
        return 'EVIDENCE FOR B (independent Poissons): CI entirely > 0'
    return 'INCONCLUSIVE: CI contains 0 (no equivalence is declared)'


# ----------------------------------------------------------------------------- report
def build_report(pred_path, elig_path, t_freeze, reps=DEFAULT_REPS, seed=DEFAULT_SEED,
                 warn_n=WARN_MIN_N, warn_blocks=WARN_MIN_BLOCKS):
    """Returns (lines, result dict)."""
    L, warns = [], []
    p = L.append
    rows_in = read_csv(pred_path, PRED_REQUIRED)
    script_sha = sha256_file(os.path.abspath(__file__))

    # ---- filters
    reasons, row_reason, accepted, po_accepted = Counter(), {}, [], []
    status_break = Counter()
    for r in rows_in:
        why, parsed = classify(r, t_freeze)
        if why is None:
            accepted.append(parsed)
        else:
            reasons[why] += 1
            row_reason.setdefault(val(r, 'match_id'), []).append(why)
            if why == 'not_finished':
                status_break[val(r, 'match_status') or 'NULL'] += 1
            if why == 'playoff':      # separate descriptive block: playoff rows passing every other filter
                why2, parsed2 = classify(r, t_freeze, allow_playoff=True)
                if why2 is None:
                    po_accepted.append(parsed2)
    key = lambda x: (x['gen'], x['id'] if x['id'] is not None else 0)
    accepted.sort(key=key)
    seen, sample = set(), []
    for a in accepted:
        if a['match_id'] in seen:
            reasons['duplicate_match'] += 1
            row_reason.setdefault(a['match_id'], []).append('duplicate_match')
        else:
            seen.add(a['match_id'])
            sample.append(a)
    po_accepted.sort(key=key)
    seen_po, po_sample = set(), []
    for a in po_accepted:
        if a['match_id'] not in seen_po:
            seen_po.add(a['match_id'])
            po_sample.append(a)

    p('GOAL B-P1 prospective analysis (protocol section 12)')
    p(f'T_freeze (UTC): {t_freeze.isoformat()} | model {MODEL_KEY} v{MODEL_VERSION} | sha {ARTIFACT_SHA}')
    p(f'script sha256: {script_sha}')
    p(f'predictions file: {pred_path} sha256 {sha256_file(pred_path)}')
    p(f'rows read: {len(rows_in)} | included in primary sample: {len(sample)}')
    p('exclusion reasons (first failing condition): ' + (', '.join(f'{k}={reasons[k]}' for k in REASON_ORDER if reasons[k]) or 'none'))
    if status_break:
        p('  not_finished by status: ' + ', '.join(f'{k}={v}' for k, v in sorted(status_break.items())))

    if len(sample) < 2:
        warns.append('N < 2: primary analysis NOT computable.')
        p('')
        p('NO ANALYSIS: fewer than 2 included matches.')
        L.extend(['WARNING: ' + w for w in warns])
        return L, {'n': len(sample), 'reasons': dict(reasons), 'verdict': None, 'warnings': warns, 'playoff': {'n': len(po_sample)}}

    # ---- per-match metrics
    for s in sample:
        s.update(row_metrics(s['lh'], s['la'], s['l3'], s['hg'], s['ag']))
    sample_hash = hashlib.sha256('\n'.join(sorted(
        f"{s['id']}|{s['match_id']}|{s['lh']!r}|{s['la']!r}|{s['l3']!r}|{s['hg']}|{s['ag']}" for s in sample)).encode()).hexdigest()
    p(f'sample sha256: {sample_hash}')
    p(f'max residual probability mass (pmf support 0..{M-1}, grid 0..{G-1}): {max(s["resid"] for s in sample):.2e}')

    keys = ['d_' + k for k, _ in METRICS]
    cis, nb = block_bootstrap(sample, keys, reps, seed)
    n = len(sample)
    sizes = Counter(s['week'] for s in sample)
    p('')
    p(f'N = {n} matches | blocks (ISO weeks) = {nb} | matches per block: min {min(sizes.values())}, max {max(sizes.values())} '
      f'| bootstrap {reps} reps, seed {seed}, percentile 95%')
    if nb < 2:
        warns.append('fewer than 2 blocks: the block bootstrap CI is degenerate.')
    if n < warn_n:
        warns.append(f'N={n} < {warn_n} (reporting parameter): precision likely insufficient for a reliable inference.')
    if nb < warn_blocks:
        warns.append(f'blocks={nb} < {warn_blocks} (reporting parameter): block-bootstrap CI may be unreliable.')

    prim_i = [k for k, _ in METRICS].index(PRIMARY)
    lo, hi = cis[prim_i]
    dm = mean([s['d_' + PRIMARY] for s in sample])
    p('')
    p('PRIMARY  dNLL(T) = A - B (negative = A better)')
    p(f'  mean {dm:+.5f}   95% CI [{lo:+.5f}, {hi:+.5f}]')
    p(f'  VERDICT: {verdict(lo, hi)}')

    p('')
    p('DESCRIPTIVE METRICS (no decisions)   mean A | mean B | dA-B [95% CI]')
    for i, (k, lab) in enumerate(METRICS):
        p(f"  {lab:<30} {mean([s[k + '_a'] for s in sample]):9.5f} {mean([s[k + '_b'] for s in sample]):9.5f} "
          f"{mean([s['d_' + k] for s in sample]):+9.5f} [{cis[i][0]:+.5f}, {cis[i][1]:+.5f}]")
    p('  (RPS(T) is ordinal over an unbounded support, not divided by K-1.)')

    p('')
    p('T DISTRIBUTION  observed freq | mean P (A) | mean P (B)')
    for lab, a, b in (('0', 0, 0), ('1', 1, 1), ('2', 2, 2), ('3', 3, 3), ('4', 4, 4), ('5+', 5, M - 1)):
        p(f"  {lab:<3} {sum(1 for s in sample if a <= s['t'] <= b) / n:8.4f} "
          f"{mean([sum(s['pmf_a'][a:b + 1]) for s in sample]):8.4f} {mean([sum(s['pmf_b'][a:b + 1]) for s in sample]):8.4f}")
    p(f"  draw: observed {mean([s['draw'] for s in sample]):.4f} | P(A) {mean([s['pdraw_a'] for s in sample]):.4f} | P(B) {mean([s['pdraw_b'] for s in sample]):.4f}")

    p('')
    p('PER LEAGUE (N, mean dNLL)')
    for c in sorted({s['comp'] for s in sample}):
        rs = [s for s in sample if s['comp'] == c]
        p(f"  {c}: N={len(rs):>4}  dNLL {mean([s['d_' + PRIMARY] for s in rs]):+.5f}")

    p('')
    p('PER LATENT SNAPSHOT (latent_snapshot_generated_at): N, blocks, mean dNLL')
    for sn in sorted({s['snap'] for s in sample}):
        rs = [s for s in sample if s['snap'] == sn]
        p(f"  {sn}: N={len(rs):>4}  blocks={len({s['week'] for s in rs}):>3}  dNLL {mean([s['d_' + PRIMARY] for s in rs]):+.5f}")

    p('')
    p(f"PLAYOFF MATCHES (excluded from the primary analysis; DESCRIPTIVE only, no CI, not part of the verdict): "
      f"rows excluded as playoff = {reasons['playoff']}, valid for the descriptive block N = {len(po_sample)}")
    po_res = {'n': len(po_sample), 'mean': None}
    if po_sample:
        for s in po_sample:
            s.update(row_metrics(s['lh'], s['la'], s['l3'], s['hg'], s['ag']))
        po_res['mean'] = mean([s['d_' + PRIMARY] for s in po_sample])
        p(f"  mean NLL(T): A {mean([s[PRIMARY + '_a'] for s in po_sample]):.5f}  B {mean([s[PRIMARY + '_b'] for s in po_sample]):.5f}  "
          f"dNLL {po_res['mean']:+.5f}")

    # ---- coverage
    cov = None
    if elig_path:
        elig = read_csv(elig_path, ELIG_REQUIRED)
        p('')
        p(f'COVERAGE (denominator file {elig_path} sha256 {sha256_file(elig_path)})')
        sample_ids = {s['match_id'] for s in sample}
        by_comp, by_week, miss = defaultdict(lambda: [0, 0]), defaultdict(lambda: [0, 0]), Counter()
        bad_flag = po_elig = 0
        for e in elig:
            try:
                ko = parse_utc(val(e, 'kickoff_at'))
            except (AttributeError, TypeError, ValueError):
                continue
            comp = to_int(val(e, 'competition_id'))
            if (comp not in CORE_COMPETITIONS or ko < t_freeze or val(e, 'status') != 'finished'
                    or to_int(val(e, 'home_score_ft')) is None or to_int(val(e, 'away_score_ft')) is None):
                continue
            fl = playoff_flag(e)
            if fl is None:
                bad_flag += 1
                continue
            if fl:
                po_elig += 1      # playoff matches are not in the primary denominator
                continue
            hit = 1 if val(e, 'match_id') in sample_ids else 0
            by_comp[comp][0] += 1
            by_comp[comp][1] += hit
            by_week[ko.isocalendar()[:2]][0] += 1
            by_week[ko.isocalendar()[:2]][1] += hit
            if not hit:
                miss[(row_reason.get(val(e, 'match_id')) or ['no_official_row'])[0]] += 1
        tot_e, tot_s = sum(v[0] for v in by_comp.values()), sum(v[1] for v in by_comp.values())
        p(f'  eligible finished matches: {tot_e} | in sample: {tot_s} | coverage {100 * tot_s / tot_e:.1f}%' if tot_e else '  no eligible match in the denominator file')
        for c in sorted(by_comp):
            e_, s_ = by_comp[c]
            p(f'  league {c}: eligible {e_:>4}  in sample {s_:>4}  coverage {100 * s_ / e_:5.1f}%')
        p('  by ISO week (eligible / in sample): ' + ', '.join(f'{w[0]}-W{w[1]:02d}: {v[0]}/{v[1]}' for w, v in sorted(by_week.items())))
        p('  eligible but NOT in sample, by reason: ' + (', '.join(f'{k}={v}' for k, v in sorted(miss.items())) or 'none'))
        p(f'  finished playoff matches (not in the primary denominator): {po_elig}')
        if bad_flag:
            warns.append(f'{bad_flag} eligible-file row(s) with an invalid is_playoff flag were left out of the denominator.')
        extra = sample_ids - {val(e, 'match_id') for e in elig}
        if extra:
            warns.append(f'{len(extra)} sample match(es) not present in the denominator file.')
        cov = {'eligible': tot_e, 'in_sample': tot_s, 'missing': dict(miss)}
    else:
        warns.append('no --eligible file: coverage (denominator) not computed.')

    p('')
    p('Selection note: the sample is determined by the capture process (not random); declared, not corrected.')
    for w in warns:
        p('WARNING: ' + w)
    return L, {'n': n, 'blocks': nb, 'reasons': dict(reasons), 'primary_mean': dm, 'primary_ci': (lo, hi),
               'verdict': verdict(lo, hi), 'warnings': warns, 'sample_hash': sample_hash, 'coverage': cov, 'playoff': po_res,
               'metrics': {k: (mean([s['d_' + k] for s in sample]), cis[i]) for i, (k, _) in enumerate(METRICS)}}


# ----------------------------------------------------------------------------- self-test (synthetic data only)
def _rpois(rng, mu):
    L, k, pr = math.exp(-mu), 0, 1.0
    while True:
        pr *= rng.random()
        if pr <= L:
            return k
        k += 1


def _base(i, **over):
    r = {'id': str(i), 'match_id': str(1000 + i), 'competition_id': '15', 'purpose': 'official', 'model_key': MODEL_KEY,
         'model_version': MODEL_VERSION, 'artifact_sha256': ARTIFACT_SHA, 'generated_at': '2026-11-05 10:00:00',
         'kickoff_at': '2026-11-07 15:00:00', 'match_kickoff_at': '2026-11-07 15:00:00', 'match_status': 'finished',
         'home_score_ft': '2', 'away_score_ft': '1', 'lambda_home': '1.6', 'lambda_away': '1.2', 'lambda3': '0.15',
         'latent_snapshot_generated_at': '2026-10-30 13:57:40', 'is_playoff': '0'}
    r.update({k: str(v) for k, v in over.items()})
    return r


def _write(path, rows):
    cols = list(rows[0].keys())
    with open(path, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(rows)


def self_test():
    ok = 0

    def check(cond, msg):
        nonlocal ok
        if not cond:
            raise AssertionError(msg)
        ok += 1

    tf = parse_utc('2026-11-01T00:00:00Z')

    # 1. formulas
    for lh, la in ((1.6, 1.2), (0.6, 0.55), (5.9, 3.1)):
        pa, pb = pmf_bivariate(lh, la, 0.15), pmf_independent(lh + la)
        check(abs(sum(pa) - 1) < 1e-12 and abs(sum(pb) - 1) < 1e-12, 'pmf mass')
        check(abs(sum(t * q for t, q in enumerate(pa)) - (lh + la)) < 1e-9 and abs(sum(t * q for t, q in enumerate(pb)) - (lh + la)) < 1e-9, 'E[T]')
        check(sum((t - lh - la) ** 2 * q for t, q in enumerate(pa)) > sum((t - lh - la) ** 2 * q for t, q in enumerate(pb)), 'BP has larger Var(T)')
        for l3 in (0.15, 0.0):
            g = joint_grid(lh, la, l3)
            check(abs(sum(map(sum, g)) - 1) < 1e-12, 'grid mass')
            check(abs(sum(g[x][y] for x in range(1, G) for y in range(1, G)) - p_btts(lh, la, l3)) < 1e-12, 'BTTS closed form')
            tt = [sum(g[x][t - x] for x in range(t + 1)) for t in range(0, 20)]
            ref = pmf_bivariate(lh, la, l3) if l3 > 0 else pmf_independent(lh + la)
            check(max(abs(a - b) for a, b in zip(tt, ref)) < 1e-12, 'T from joint grid == T representation')
            check(abs(math.exp(log_joint(2, 1, lh, la, l3)) - g[2][1]) < 1e-12, 'exact score')
    m = row_metrics(1.6, 1.2, 0.15, 2, 1)
    check(abs(m['d_nll_T'] - (m['nll_T_a'] - m['nll_T_b'])) < 1e-15, 'delta definition')

    # 2. bootstrap helpers
    rows = [{'week': (2026, 45 + i % 3), 'd_x': 0.5} for i in range(30)]
    cis, nb = block_bootstrap(rows, ['d_x'], 200, 1)
    check(nb == 3 and abs(cis[0][0] - 0.5) < 1e-12 and abs(cis[0][1] - 0.5) < 1e-12, 'constant -> degenerate CI')
    rows = [{'week': (2026, 40 + i % 10), 'd_x': (i % 7) * 0.01} for i in range(200)]
    a1, _ = block_bootstrap(rows, ['d_x'], 300, 5)
    rng = random.Random(3)
    rng.shuffle(rows)
    a2, _ = block_bootstrap(rows, ['d_x'], 300, 5)
    check(a1 == a2, 'bootstrap independent of row order')
    check(verdict(-0.2, -0.1).startswith('EVIDENCE FOR A') and verdict(0.1, 0.2).startswith('EVIDENCE FOR B')
          and verdict(-0.1, 0.1).startswith('INCONCLUSIVE') and verdict(0.0, 0.1).startswith('INCONCLUSIVE'), 'verdict rules')

    with tempfile.TemporaryDirectory() as tmp:
        pp, ep = os.path.join(tmp, 'p.csv'), os.path.join(tmp, 'e.csv')

        # 3. filters: one violating row per reason + duplicates + one valid row
        rows = [_base(1)]
        bad = [('not_official', dict(purpose='public_latest')), ('wrong_model_key', dict(model_key='full59')),
               ('wrong_version', dict(model_version='1.0.0')), ('wrong_sha', dict(artifact_sha256='9c11330ed725')),
               ('invalid_timestamp', dict(generated_at='garbage')),
               ('not_pre_match', dict(generated_at='2026-11-07 15:00:00')),
               ('kickoff_changed', dict(match_kickoff_at='2026-11-08 15:00:00')),
               ('before_freeze', dict(generated_at='2026-10-20 10:00:00', kickoff_at='2026-10-31 15:00:00', match_kickoff_at='2026-10-31 15:00:00')),
               ('not_core_competition', dict(competition_id='2')), ('playoff', dict(is_playoff='1')),
               ('playoff', dict(is_playoff='true', match_status='scheduled')), ('invalid_playoff_flag', dict(is_playoff='maybe')),
               ('not_finished', dict(match_status='scheduled')),
               ('not_finished', dict(match_status='abandoned')),
               ('invalid_score', dict(home_score_ft='NULL')), ('invalid_score', dict(away_score_ft='-1')),
               ('invalid_lambda', dict(lambda3='0.2')), ('invalid_lambda', dict(lambda_home='nan')),
               ('invalid_lambda', dict(lambda_away='0.1', lambda3='0.15'))]
        for j, (_, over) in enumerate(bad):
            rows.append(_base(10 + j, **over))
        rows.append(_base(100, match_id='1001', generated_at='2026-11-05 12:00:00'))   # duplicate of match 1001 (later)
        _write(pp, rows)
        lines, res = build_report(pp, None, tf, reps=50)
        exp = Counter(w for w, _ in bad)
        exp['duplicate_match'] = 1
        check(res['n'] == 1, f"only the valid row included, got {res['n']}")
        check(res['playoff']['n'] == 1, f"one playoff row valid for the descriptive block, got {res['playoff']}")
        check(all(res['reasons'].get(k, 0) == v for k, v in exp.items()) and sum(res['reasons'].values()) == sum(exp.values()),
              f"exclusion counts {res['reasons']} vs {dict(exp)}")
        check(any('N < 2' in w for w in res['warnings']), 'N<2 warning')

        # 4. coverage with denominator
        rows = [_base(i, match_id=2000 + i, kickoff_at=f'2026-11-{7 + i % 3:02d} 15:00:00', match_kickoff_at=f'2026-11-{7 + i % 3:02d} 15:00:00')
                for i in range(1, 7)]
        rows[4]['artifact_sha256'] = 'x' * 64            # eligible, row excluded: wrong_sha
        rows[5]['match_status'] = 'finished'
        _write(pp, rows[:5] + [rows[5]])
        elig = [dict(match_id=2000 + i, competition_id=15, kickoff_at=f'2026-11-{7 + i % 3:02d} 15:00:00', status='finished',
                     home_score_ft=1, away_score_ft=0, is_playoff=0) for i in range(1, 9)]   # 8 eligible; 2000+7, 2000+8 have no row
        elig.append(dict(match_id=9999, competition_id=15, kickoff_at='2026-10-01 15:00:00', status='finished', home_score_ft=1, away_score_ft=0, is_playoff=0))  # before freeze: not eligible
        elig.append(dict(match_id=9997, competition_id=15, kickoff_at='2026-11-08 15:00:00', status='finished', home_score_ft=2, away_score_ft=2, is_playoff=1))  # playoff: not in the primary denominator
        elig.append(dict(match_id=9998, competition_id=15, kickoff_at='2026-11-09 15:00:00', status='scheduled', home_score_ft='', away_score_ft='', is_playoff=0))  # not finished: not eligible
        _write(ep, [{k: str(v) for k, v in e.items()} for e in elig])
        lines, res = build_report(pp, ep, tf, reps=50)
        check(res['n'] == 5 and res['coverage']['eligible'] == 8 and res['coverage']['in_sample'] == 5, f"coverage {res['coverage']}")
        check(res['coverage']['missing'] == {'wrong_sha': 1, 'no_official_row': 2}, f"missing reasons {res['coverage']['missing']}")

        # 5. end-to-end on synthetic outcomes drawn from a MORE dependent truth (lambda3 = 0.8): A must beat B
        rng = random.Random(11)
        rows, N = [], 2500
        d0 = datetime(2026, 11, 2, 15, 0, tzinfo=timezone.utc)
        for i in range(N):
            lh, la, t3 = 0.9 + 1.6 * rng.random(), 0.7 + 1.2 * rng.random(), 0.8
            x3 = _rpois(rng, t3)
            hg, ag = _rpois(rng, lh - t3) + x3, _rpois(rng, la - t3) + x3
            ko = (d0 + timedelta(days=(i * 150) // N)).strftime('%Y-%m-%d %H:%M:%S')
            rows.append(_base(i + 1, match_id=5000 + i, competition_id=15 + i % 5, lambda_home=f'{lh:.6f}', lambda_away=f'{la:.6f}',
                              home_score_ft=hg, away_score_ft=ag, kickoff_at=ko, match_kickoff_at=ko, generated_at='2026-10-31 23:59:59'))
        _write(pp, rows)
        lines, res = build_report(pp, None, tf, reps=300)
        check(res['n'] == N and res['blocks'] >= 8, f"synthetic N/blocks {res['n']}/{res['blocks']}")
        check(res['primary_mean'] < 0, f"A should beat B when truth is strongly dependent: {res['primary_mean']}")
        check(res['verdict'].startswith('EVIDENCE FOR A'), res['verdict'])
        l2, res2 = build_report(pp, None, tf, reps=300)
        check(lines == l2 and res['primary_ci'] == res2['primary_ci'], 'deterministic')
        # same data, A truth independent -> B should be (weakly) better or equal; sign check only
        rows = [dict(r) for r in rows]
        for r in rows:
            lh, la = float(r['lambda_home']), float(r['lambda_away'])
            r['home_score_ft'], r['away_score_ft'] = _rpois(rng, lh), _rpois(rng, la)
        _write(pp, rows)
        lines, res3 = build_report(pp, None, tf, reps=300)
        check(res3['primary_mean'] > 0, f"B should (weakly) win when truth is independent: {res3['primary_mean']}")
    return ok


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--predictions')
    ap.add_argument('--eligible')
    ap.add_argument('--t-freeze', help='UTC ISO timestamp of the effective protocol freeze')
    ap.add_argument('--reps', type=int, default=DEFAULT_REPS)
    ap.add_argument('--seed', type=int, default=DEFAULT_SEED)
    ap.add_argument('--warn-min-n', type=int, default=WARN_MIN_N)
    ap.add_argument('--warn-min-blocks', type=int, default=WARN_MIN_BLOCKS)
    ap.add_argument('--report-file')
    ap.add_argument('--self-test', action='store_true')
    a = ap.parse_args()
    if a.self_test:
        print(f'self-test OK ({self_test()} checks, synthetic data only)')
        return
    if not a.predictions or not a.t_freeze:
        ap.error('--predictions and --t-freeze are required (or --self-test)')
    lines, _ = build_report(a.predictions, a.eligible, parse_utc(a.t_freeze), a.reps, a.seed, a.warn_min_n, a.warn_min_blocks)
    text = '\n'.join(lines)
    print(text)
    if a.report_file:
        with open(a.report_file, 'w', encoding='utf-8') as f:
            f.write(text + '\n')


if __name__ == '__main__':
    main()
