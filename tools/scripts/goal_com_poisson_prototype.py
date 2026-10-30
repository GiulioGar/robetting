"""
GOAL experimental prototype (Step 37) - COM-Poisson total + binomial split. SYNTHETIC checks only.

Joint distribution of (H, A) for one match, given lambda_home (lh) and lambda_away (la):
    T ~ COM-Poisson(s, nu)   P(T=t) = s^t / ((t!)^nu * Z),  Z = sum_j s^j / (j!)^nu,
                             with the intensity s solved so that E[T] = mu = lh + la EXACTLY
    H | T ~ Binomial(T, q),  q = lh / (lh + la),      A = T - H
    P(H=h, A=a) = P_T(h+a) * C(h+a, h) * q^h * (1-q)^a

nu = 1 -> Poisson(mu) and (H, A) independent Poisson(lh), Poisson(la). nu > 1 -> Var(T) < mu (underdispersion),
nu < 1 -> Var(T) > mu. Means of H and A are lh and la for every nu. Identities used as checks:
    Var(T)     = sigma2
    Var(H)     = lh*(1-q) + q^2 * sigma2        Var(A) = la*q + (1-q)^2 * sigma2
    Cov(H, A)  = q*(1-q) * (sigma2 - mu)

Numerics: log-space weights (t*ln s - nu*lgamma(t+1)), log-sum-exp normalisation, support extended until a rigorous
geometric tail bound (w_K / (1 - rho), rho = s/(K+1)^nu < 1, ratios decreasing in t) is below `tol`; the mass beyond
the support is REPORTED, never renormalised away. The mean equation is solved by a safeguarded Newton/bisection
iteration on eta = ln s (mean is increasing in eta, derivative = Var(T)).

This module does NOT estimate or optimise nu, uses no real data, no DB/API, no model; standard library only.
It is independent of the production engine and of Candidate47.

Usage:
  py -I tools/scripts/goal_com_poisson_prototype.py --self-test
  py -I tools/scripts/goal_com_poisson_prototype.py --demo --lh 1.6 --la 1.2 --nu 1.3      (synthetic inputs)
"""
import argparse
import math
import random
import sys
import time
from decimal import Decimal, getcontext

TOL = 1e-15        # tail-mass bound (relative to Z) defining the support length
KMAX = 20000       # hard cap on the support length
_LG = [0.0]        # cache of lgamma(i + 1) = ln(i!)


def _lgfact(n):
    while len(_LG) <= n:
        _LG.append(_LG[-1] + math.log(len(_LG)))
    return _LG


# ----------------------------------------------------------------------------- COM-Poisson
def _logw(eta, nu, k):
    lg = _lgfact(k)
    return [t * eta - nu * lg[t] for t in range(k + 1)]   # indices 0..k (k included)


def com_support(eta, nu, tol=TOL):
    """-> (K, logZ, tail_bound_rel): terms 0..K-1 kept, tail_bound_rel bounds sum_{t>=K} w_t / Z."""
    k = 32
    while True:
        lw = _logw(eta, nu, k)                       # 0..k ; we keep 0..k-1 and bound the tail from k
        kept = lw[:k]
        m = max(kept)
        logz = m + math.log(sum(math.exp(x - m) for x in kept))
        rho = math.exp(eta - nu * math.log(k + 1))
        if rho < 1.0:
            tail = math.exp(lw[k] - logz) / (1.0 - rho)
            if tail <= tol:
                return k, logz, tail
        if k >= KMAX:
            raise RuntimeError(f'COM-Poisson support exceeds KMAX={KMAX} (eta={eta}, nu={nu})')
        k *= 2


def _moments(eta, nu, tol=TOL):
    k, logz, tail = com_support(eta, nu, tol)
    lw = _logw(eta, nu, k)
    p = [math.exp(lw[t] - logz) for t in range(k)]
    mean = math.fsum(t * pt for t, pt in enumerate(p))
    var = math.fsum((t - mean) ** 2 * pt for t, pt in enumerate(p))
    return mean, var, p, k, tail


def com_pmf(mu, nu, tol=TOL):
    """COM-Poisson pmf with E[T] = mu exactly (to ~1e-13). Returns a dict."""
    if not (mu > 0 and nu > 0 and math.isfinite(mu) and math.isfinite(nu)):
        raise ValueError('mu and nu must be finite and > 0')
    eta = nu * math.log(max(mu + (nu - 1.0) / (2.0 * nu), 0.05))      # asymptotic start
    f = lambda e: _moments(e, nu, tol)
    lo = hi = None
    it = 0
    m, v, p, k, tail = f(eta)
    it += 1
    step = 1.0
    if m < mu:
        lo = eta
        while True:
            eta += step
            m, v, p, k, tail = f(eta)
            it += 1
            if m >= mu:
                hi = eta
                break
            lo = eta
            step *= 2
    else:
        hi = eta
        while True:
            eta -= step
            m, v, p, k, tail = f(eta)
            it += 1
            if m <= mu:
                lo = eta
                break
            hi = eta
            step *= 2
    # safeguarded Newton on [lo, hi]
    eta = 0.5 * (lo + hi)
    for _ in range(200):
        m, v, p, k, tail = f(eta)
        it += 1
        err = m - mu
        if abs(err) <= 1e-14 * max(1.0, mu):
            break
        if err > 0:
            hi = min(hi, eta)
        else:
            lo = max(lo, eta)
        new = eta - err / v if v > 0 else 0.5 * (lo + hi)
        if not (lo < new < hi):
            new = 0.5 * (lo + hi)
        eta = new
    else:
        raise RuntimeError('mean inversion did not converge')
    return {'pmf': p, 'eta': eta, 'nu': nu, 'mu': mu, 'K': k, 'tail_bound': tail, 'iterations': it,
            'mean': m, 'var': v}


# ----------------------------------------------------------------------------- joint and markets
def joint(lh, la, nu, tol=TOL):
    """Joint P(H=h, A=a) on the triangle h + a < K. Returns dict with rows J[h][a] and diagnostics."""
    if not (lh > 0 and la > 0):
        raise ValueError('lambda_home and lambda_away must be > 0')
    mu = lh + la
    q = lh / mu
    c = com_pmf(mu, nu, tol)
    pT, K = c['pmf'], c['K']
    lg = _lgfact(K)
    lq, lq1 = math.log(q), math.log1p(-q)
    J = [[pT[h + a] * math.exp(lg[h + a] - lg[h] - lg[a] + h * lq + a * lq1) for a in range(K - h)] for h in range(K)]
    return {'J': J, 'pT': pT, 'K': K, 'q': q, 'mu': mu, 'lh': lh, 'la': la, 'nu': nu,
            'tail_bound': c['tail_bound'], 'iterations': c['iterations'], 'var_T_model': c['var']}


def markets(jd):
    """All markets derived from the joint pmf (no renormalisation; 'missing' = mass beyond the support)."""
    J, K = jd['J'], jd['K']
    ph = pd = pa = 0.0
    p_h, p_a = [0.0] * K, [0.0] * K
    p_t = [0.0] * K
    for h in range(K):
        for a in range(K - h):
            v = J[h][a]
            p_h[h] += v
            p_a[a] += v
            p_t[h + a] += v
            if h > a:
                ph += v
            elif h == a:
                pd += v
            else:
                pa += v
    total = ph + pd + pa
    btts = 1.0 - p_h[0] - p_a[0] + J[0][0]
    over = {line: math.fsum(p_t[int(line) + 1:]) for line in (0.5, 1.5, 2.5, 3.5, 4.5)}
    return {'p_total': p_t, 'p_home': p_h, 'p_away': p_a, '1x2': (ph, pd, pa), 'draw': pd, 'btts': btts,
            'over': over, 'mass': total, 'missing': 1.0 - total, 'p00': J[0][0]}


def moments_from_joint(jd):
    J, K = jd['J'], jd['K']
    eh = ea = 0.0
    for h in range(K):
        for a in range(K - h):
            eh += h * J[h][a]
            ea += a * J[h][a]
    vh = va = cov = vt = 0.0
    for h in range(K):
        for a in range(K - h):
            v = J[h][a]
            vh += (h - eh) ** 2 * v
            va += (a - ea) ** 2 * v
            cov += (h - eh) * (a - ea) * v
            vt += (h + a - eh - ea) ** 2 * v
    return {'E_H': eh, 'E_A': ea, 'Var_H': vh, 'Var_A': va, 'Cov': cov, 'Var_T': vt}


def pois_pmf(lam, n):
    return [math.exp(t * math.log(lam) - lam - _lgfact(n)[t]) for t in range(n)]


# ----------------------------------------------------------------------------- reference with Decimal (independent path)
def decimal_mean_var(eta, nu, k=400, prec=60):
    getcontext().prec = prec
    e, n = Decimal(repr(eta)), Decimal(repr(nu))
    lnfact, w = Decimal(0), []
    for t in range(k):
        if t > 0:
            lnfact += Decimal(t).ln()
        w.append((Decimal(t) * e - n * lnfact).exp())
    z = sum(w)
    mean = sum(Decimal(t) * x for t, x in enumerate(w)) / z
    var = sum((Decimal(t) - mean) ** 2 * x for t, x in enumerate(w)) / z
    return float(mean), float(var)


# ----------------------------------------------------------------------------- self-test (synthetic)
class _Fail(Exception):
    pass


def self_test():
    checks, worst = 0, {}

    def check(name, err, tol):
        nonlocal checks
        checks += 1
        worst[name] = max(worst.get(name, 0.0), err)
        if not err <= tol:
            raise _Fail(f'{name}: error {err:.3e} > tol {tol:.1e}')

    lam_grid = [(0.30, 0.25), (0.60, 0.50), (1.60, 1.20), (2.50, 1.80), (4.50, 3.50), (6.00, 3.20), (0.05, 0.04),
                (10.0, 8.0), (0.02, 3.0), (3.0, 0.02)]
    nu_grid = [0.4, 0.5, 0.8, 1.0, 1.2, 1.5, 2.0, 3.0, 5.0]

    # 1-3: mass, means, variances, covariance, mean inversion, tail bound
    maxK, maxit = 0, 0
    for lh, la in lam_grid:
        for nu in nu_grid:
            jd = joint(lh, la, nu)
            mk, mo = markets(jd), moments_from_joint(jd)
            mu, q, s2 = lh + la, jd['q'], mo['Var_T']
            check('mass: |1 - sum(joint)| - tail_bound', max(0.0, abs(1 - mk['mass']) - 5 * jd['tail_bound']), 1e-13)
            check('E[T] = lh+la (mean inversion)', abs(mo['E_H'] + mo['E_A'] - mu) / max(1, mu), 1e-11)
            check('E[H] = lh', abs(mo['E_H'] - lh) / max(1, lh), 1e-11)
            check('E[A] = la', abs(mo['E_A'] - la) / max(1, la), 1e-11)
            check('Var(T) joint vs pmf', abs(s2 - jd['var_T_model']) / max(1, s2), 1e-10)
            check('Var(H) = lh(1-q) + q^2 sigma2', abs(mo['Var_H'] - (lh * (1 - q) + q * q * s2)) / max(1, s2), 1e-10)
            check('Var(A) = la q + (1-q)^2 sigma2', abs(mo['Var_A'] - (la * q + (1 - q) ** 2 * s2)) / max(1, s2), 1e-10)
            check('Cov(H,A) = q(1-q)(sigma2 - mu)', abs(mo['Cov'] - q * (1 - q) * (s2 - mu)) / max(1, s2), 1e-10)
            if nu > 1.0:
                check('nu>1: Var(T) < mu', max(0.0, s2 - mu), 0.0)
                check('nu>1: Cov < 0', max(0.0, mo['Cov']), 0.0)
            if nu < 1.0:
                check('nu<1: Var(T) > mu', max(0.0, mu - s2), 0.0)
                check('nu<1: Cov > 0', max(0.0, -mo['Cov']), 0.0)
            maxK, maxit = max(maxK, jd['K']), max(maxit, jd['iterations'])
            # internal consistency of market derivations
            check('1X2 sums to mass', abs(sum(mk['1x2']) - mk['mass']), 1e-15)
            check('BTTS = direct sum over h>=1,a>=1',
                  abs(mk['btts'] - math.fsum(jd['J'][h][a] for h in range(1, jd['K']) for a in range(1, jd['K'] - h))), 1e-13)

    # 4: marginal of H via an independent path (math.comb) and P(H=0) = pgf of T at (1-q)
    for lh, la, nu in ((1.6, 1.2, 1.5), (0.6, 0.5, 0.8), (4.5, 3.5, 2.0)):
        jd = joint(lh, la, nu)
        mk, q, pT = markets(jd), jd['q'], jd['pT']
        for h in range(0, 8):
            ref = math.fsum(pT[t] * math.comb(t, h) * q ** h * (1 - q) ** (t - h) for t in range(h, jd['K']))
            check('P(H=h) via math.comb path', abs(mk['p_home'][h] - ref), 1e-13)
        check('P(H=0) = E[(1-q)^T]', abs(mk['p_home'][0] - math.fsum(p * (1 - q) ** t for t, p in enumerate(pT))), 1e-13)

    # 5: nu = 1 reproduces independent Poissons
    for lh, la in lam_grid:
        jd = joint(lh, la, 1.0)
        K, mk = jd['K'], markets(jd)
        ph, pa = pois_pmf(lh, K), pois_pmf(la, K)
        check('nu=1: joint == Poisson(lh) x Poisson(la)',
              max(abs(jd['J'][h][a] - ph[h] * pa[a]) for h in range(K) for a in range(K - h)), 1e-13)
        c = com_pmf(lh + la, 1.0)
        check('nu=1: T == Poisson(mu)', max(abs(x - y) for x, y in zip(c['pmf'], pois_pmf(lh + la, c['K']))), 1e-13)
        check('nu=1: Var(T) = mu', abs(c['var'] - (lh + la)) / max(1, lh + la), 1e-11)
        check('nu=1: BTTS closed form', abs(mk['btts'] - (1 - math.exp(-lh) - math.exp(-la) + math.exp(-(lh + la)))), 1e-13)
        check('nu=1: P(0-0) = exp(-mu)', abs(mk['p00'] - math.exp(-(lh + la))), 1e-14)
    for lh, la in ((1.6, 1.2), (0.6, 0.5)):                     # continuity around nu = 1
        a, b = markets(joint(lh, la, 1.0))['btts'], markets(joint(lh, la, 1.0 + 1e-7))['btts']
        check('continuity at nu=1', abs(a - b), 1e-6)

    # 6: Var(T) decreasing in nu at fixed mean (observed on the grid)
    for lh, la in lam_grid:
        v = [com_pmf(lh + la, nu)['var'] for nu in nu_grid]
        check('Var(T) non-increasing in nu', max(0.0, max(v[i + 1] - v[i] for i in range(len(v) - 1))), 1e-9)

    # 7: independent high-precision (Decimal) reference for the normalisation / mean / variance
    for mu, nu in ((2.9, 1.5), (0.45, 0.7), (6.5, 2.5), (12.0, 1.1)):
        c = com_pmf(mu, nu)
        m_ref, v_ref = decimal_mean_var(c['eta'], nu)
        check('Decimal reference: mean', abs(m_ref - mu) / max(1, mu), 1e-12)
        check('Decimal reference: variance', abs(v_ref - c['var']) / max(1, v_ref), 1e-11)

    # 8: stability - extreme parameters and a random batch (timing), tail bound always honoured
    for mu, nu in ((0.02, 0.5), (0.02, 5.0), (30.0, 1.0), (30.0, 3.0), (8.0, 0.4), (3.0, 20.0)):
        c = com_pmf(mu, nu)
        check('extreme params: mean', abs(sum(t * p for t, p in enumerate(c['pmf'])) - mu) / max(1, mu), 1e-11)
        check('extreme params: tail bound <= tol', max(0.0, c['tail_bound'] - TOL), 0.0)
        maxK = max(maxK, c['K'])
    rng = random.Random(2026)
    t0 = time.perf_counter()
    n_batch = 200
    for _ in range(n_batch):
        lh, la, nu = 0.5 + 3.5 * rng.random(), 0.4 + 2.6 * rng.random(), 0.7 + 1.6 * rng.random()
        jd = joint(lh, la, nu)
        mo = moments_from_joint(jd)
        check('random batch: E[H]', abs(mo['E_H'] - lh), 1e-11)
        check('random batch: Cov identity', abs(mo['Cov'] - jd['q'] * (1 - jd['q']) * (mo['Var_T'] - lh - la)), 1e-10)
    per_match_ms = 1000 * (time.perf_counter() - t0) / n_batch

    print(f'self-test OK: {checks} checks (synthetic data only)')
    print(f'max support length K = {maxK}; max mean-inversion iterations = {maxit}; tail-mass bound per call <= {TOL:g}')
    print(f'cost: {per_match_ms:.2f} ms per match (joint + moments, includes the O(K^2) joint and its verification), '
          f'{n_batch} random synthetic matches')
    print('worst error per check (tolerances in the code):')
    for k in sorted(worst):
        print(f'  {k:<45} {worst[k]:.2e}')


def demo(lh, la, nu):
    jd = joint(lh, la, nu)
    mk, mo = markets(jd), moments_from_joint(jd)
    print(f'SYNTHETIC input lh={lh} la={la} nu={nu} | q={jd["q"]:.4f} K={jd["K"]} tail_bound={jd["tail_bound"]:.1e} missing mass={mk["missing"]:.1e}')
    print(f'E[H]={mo["E_H"]:.6f} E[A]={mo["E_A"]:.6f} Var(T)={mo["Var_T"]:.6f} (mu={lh + la:.6f}) Cov(H,A)={mo["Cov"]:+.6f}')
    print('T pmf 0..6:', ' '.join(f'{x:.5f}' for x in mk['p_total'][:7]))
    print(f'1X2: H {mk["1x2"][0]:.5f}  D {mk["1x2"][1]:.5f}  A {mk["1x2"][2]:.5f} | BTTS {mk["btts"]:.5f} | P(0-0) {mk["p00"]:.5f}')
    print('Over:', ', '.join(f'{k}: {v:.5f}' for k, v in mk['over'].items()))
    print('P(H=0..3):', ' '.join(f'{x:.5f}' for x in mk['p_home'][:4]), '| P(A=0..3):', ' '.join(f'{x:.5f}' for x in mk['p_away'][:4]))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--self-test', action='store_true')
    ap.add_argument('--demo', action='store_true')
    ap.add_argument('--lh', type=float, default=1.6)
    ap.add_argument('--la', type=float, default=1.2)
    ap.add_argument('--nu', type=float, default=1.3)
    a = ap.parse_args()
    if a.self_test:
        try:
            self_test()
        except _Fail as e:
            print('SELF-TEST FAILED:', e)
            sys.exit(1)
    elif a.demo:
        demo(a.lh, a.la, a.nu)
    else:
        ap.error('use --self-test or --demo')


if __name__ == '__main__':
    main()
