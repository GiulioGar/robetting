"""
Step 84 — joint home/away CORNER distribution (Gaussian copula on the Step 83 negative-binomial marginals)
and Over/Under probabilities of total corners. Offline experiment, reuses corner_experiment.py unchanged.

Joint pmf of two discrete counts with a Gaussian copula (rho = the copula parameter, NOT a Pearson correlation):
    P(Yh=i, Ya=j) = C(Fh(i), Fa(j)) - C(Fh(i-1), Fa(j)) - C(Fh(i), Fa(j-1)) + C(Fh(i-1), Fa(j-1))
    C(u, v) = Phi2(Phi^-1(u), Phi^-1(v); rho) = int_{-inf}^{a} phi(x) Phi((b - rho x)/sqrt(1-rho^2)) dx
The 1-D integral is evaluated by a trapezoid rule on a fixed grid (no scipy mvn calls). rho is estimated by
maximum likelihood of the observed (home, away) pairs on TRAIN only, given the already fitted marginals;
the grid is truncated at K corners per side and the table is renormalised (the lost mass is reported).
rho = 0 reproduces independence exactly (same truncation), so both approaches use the same code path.

Usage:
  py -3 tools/scripts/corner_joint.py --matches-tsv M.tsv --competitions-tsv C.tsv --out R.json
"""
import argparse
import csv
import json
import time

import numpy as np
from scipy.optimize import minimize_scalar
from scipy.special import ndtr, ndtri
from scipy.stats import nbinom

import corner_experiment as ce

K = 30                      # corners per side kept in the joint table
THRESHOLDS = (8.5, 9.5, 10.5)
X_LO, X_HI, DX = -8.0, 8.0, 0.005
RHO_BOUNDS = (-0.95, 0.95)
_X = np.arange(X_LO, X_HI + DX / 2, DX)
_PHI = np.exp(-0.5 * _X ** 2) / np.sqrt(2 * np.pi)


def nb_cdf(k, mu, phi):
    """P(Y <= k) for the NB2 used in Step 83 (k = -1 -> 0)."""
    r = 1.0 / phi
    return nbinom.cdf(k, r, r / (r + mu))


def _cum_integral(b, rho):
    """Rows: cumulative trapezoid of phi(x) * Phi((b - rho x)/s) on the grid, one row per b (b may be +-inf)."""
    s = np.sqrt(1.0 - rho ** 2)
    f = _PHI[None, :] * ndtr((np.asarray(b, float)[:, None] - rho * _X[None, :]) / s)
    cum = np.concatenate([np.zeros((f.shape[0], 1)), np.cumsum((f[:, :-1] + f[:, 1:]) / 2 * DX, axis=1)], axis=1)
    return cum


def _interp_rows(cum, a):
    """cum[row, :] evaluated at a[row] (a may be +-inf, clipped to the grid)."""
    pos = (np.clip(a, X_LO, X_HI) - X_LO) / DX
    i0 = np.minimum(np.floor(pos).astype(int), len(_X) - 2)
    fr = pos - i0
    rows = np.arange(len(a))
    return cum[rows, i0] * (1 - fr) + cum[rows, i0 + 1] * fr


def bvn_cdf_paired(a, b, rho, chunk=1000):
    """Phi2(a_k, b_k; rho) for paired arrays (a, b may be +-inf)."""
    a, b = np.asarray(a, float), np.asarray(b, float)
    out = np.empty(len(a))
    for s in range(0, len(a), chunk):
        out[s:s + chunk] = _interp_rows(_cum_integral(b[s:s + chunk], rho), a[s:s + chunk])
    return out


def _u_to_z(u):
    return ndtri(np.clip(u, 0.0, 1.0))      # 0 -> -inf, 1 -> +inf


def copula_loglik(yh, ya, mu_h, phi_h, mu_a, phi_a, rho):
    """Sum of log P(yh, ya) of the observed pairs under the copula joint (cell probabilities, not densities)."""
    fh1, fh0 = nb_cdf(yh, mu_h, phi_h), nb_cdf(yh - 1, mu_h, phi_h)
    fa1, fa0 = nb_cdf(ya, mu_a, phi_a), nb_cdf(ya - 1, mu_a, phi_a)
    a1, a0, b1, b0 = _u_to_z(fh1), _u_to_z(fh0), _u_to_z(fa1), _u_to_z(fa0)
    n = len(yh)
    C = bvn_cdf_paired(np.concatenate([a1, a0, a1, a0]), np.concatenate([b1, b1, b0, b0]), rho)
    p = C[:n] - C[n:2 * n] - C[2 * n:3 * n] + C[3 * n:]
    return float(np.log(np.maximum(p, 1e-300)).sum())


def fit_rho(yh, ya, mu_h, phi_h, mu_a, phi_a):
    """ML estimate of the copula parameter on the given (TRAIN) pairs only."""
    res = minimize_scalar(lambda r: -copula_loglik(yh, ya, mu_h, phi_h, mu_a, phi_a, r), bounds=RHO_BOUNDS, method='bounded',
                          options={'xatol': 1e-3})
    return float(res.x)


def joint_pmf(mu_h, phi_h, mu_a, phi_a, rho, k=K):
    """(k+1)x(k+1) renormalised joint pmf of (home, away) corners and the truncated mass before renormalisation."""
    ks = np.arange(-1, k + 1)
    a = _u_to_z(nb_cdf(ks, mu_h, phi_h))
    b = _u_to_z(nb_cdf(ks, mu_a, phi_a))
    cum = _cum_integral(b, rho)                               # (k+2, nx): one row per b_j
    pos = (np.clip(a, X_LO, X_HI) - X_LO) / DX
    i0 = np.minimum(np.floor(pos).astype(int), len(_X) - 2)
    fr = pos - i0
    C = (cum[:, i0] * (1 - fr) + cum[:, i0 + 1] * fr).T       # C[i, j] = Phi2(a_i, b_j)
    P = np.maximum(C[1:, 1:] - C[:-1, 1:] - C[1:, :-1] + C[:-1, :-1], 0.0)
    mass = float(P.sum())
    return P / mass, mass


def total_pmf(P):
    k = P.shape[0]
    out = np.zeros(2 * k - 1)
    for i in range(k):
        out[i:i + k] += P[i]
    return out


def over_probs(tot_pmf, thresholds=THRESHOLDS):
    cdf = np.cumsum(tot_pmf)
    return [float(1.0 - cdf[int(np.floor(t))]) for t in thresholds]


def moments(tot_pmf):
    t = np.arange(len(tot_pmf))
    m = float((t * tot_pmf).sum())
    return m, float((((t - m) ** 2) * tot_pmf).sum())


def binary_metrics(p, y):
    p = np.clip(p, 1e-12, 1 - 1e-12)
    order = np.argsort(p)
    chunks = np.array_split(order, 5)
    ece = sum(len(c) / len(p) * abs(p[c].mean() - y[c].mean()) for c in chunks)
    return {'brier': float(((p - y) ** 2).mean()), 'logloss': float(-(y * np.log(p) + (1 - y) * np.log(1 - p)).mean()),
            'mean_pred': float(p.mean()), 'mean_obs': float(y.mean()), 'ece5': float(ece)}


def paired_ci(diff, resamples=2000, seed=84):
    rng = np.random.default_rng(seed)
    n = len(diff)
    m = np.array([diff[rng.integers(0, n, n)].mean() for _ in range(resamples)])
    return float(np.percentile(m, 2.5)), float(np.percentile(m, 97.5))


def run(rows, train_years=(ce.TRAIN_YEAR,), test_years=(ce.TEST_YEAR,)):
    fm = ce.fit_marginals(rows, train_years=train_years, test_years=test_years)
    tr, te, Y, mus, phis = fm['tr'], fm['te'], fm['Y'], fm['mus'], fm['phis']
    mh, ma = mus['home']['nb'], mus['away']['nb']
    ph, pa = phis['home']['nb'], phis['away']['nb']
    t0 = time.perf_counter()
    rho = fit_rho(Y['home'][tr], Y['away'][tr], mh[tr], ph, ma[tr], pa)
    t_rho = time.perf_counter() - t0

    t1 = time.perf_counter()
    idx = np.where(te)[0]
    yt = Y['home'][idx] + Y['away'][idx]
    over = {m: np.zeros((len(idx), len(THRESHOLDS))) for m in ('independent', 'copula')}
    cond_mean = {m: np.zeros(len(idx)) for m in over}
    cond_var = {m: np.zeros(len(idx)) for m in over}
    implied_corr, mass_lost = [], []
    for n, i in enumerate(idx):
        for name, r in (('independent', 0.0), ('copula', rho)):
            P, mass = joint_pmf(mh[i], ph, ma[i], pa, r)
            tp = total_pmf(P)
            over[name][n] = over_probs(tp)
            cond_mean[name][n], cond_var[name][n] = moments(tp)
            mass_lost.append(1.0 - mass)
            if name == 'copula':
                g = np.arange(P.shape[0])
                eh, ea = (P.sum(1) * g).sum(), (P.sum(0) * g).sum()
                vh, va = (P.sum(1) * (g - eh) ** 2).sum(), (P.sum(0) * (g - ea) ** 2).sum()
                implied_corr.append(float(((np.outer(g - eh, g - ea) * P).sum()) / np.sqrt(vh * va)))
    t_joint = time.perf_counter() - t1

    out = {'n_train': int(tr.sum()), 'n_test': int(te.sum()), 'rho_copula_train': rho, 'implied_pearson_corr_test_mean': float(np.mean(implied_corr)),
           'raw_corr_test': ce.corr(Y['home'][te], Y['away'][te]), 'max_truncated_mass': float(np.max(mass_lost)),
           'thresholds': {}, 'totals': {}, 'seconds': {'rho_fit': round(t_rho, 1), 'joint_test': round(t_joint, 1)}}
    for k, t in enumerate(THRESHOLDS):
        y = (yt > t).astype(float)
        res = {m: binary_metrics(over[m][:, k], y) for m in over}
        pi, pc = np.clip(over['independent'][:, k], 1e-12, 1 - 1e-12), np.clip(over['copula'][:, k], 1e-12, 1 - 1e-12)
        ll = lambda p: -(y * np.log(p) + (1 - y) * np.log(1 - p))
        d_ll, d_br = ll(pc) - ll(pi), (pc - y) ** 2 - (pi - y) ** 2
        res['delta_logloss_copula_minus_indep'] = {'mean': float(d_ll.mean()), 'ci95': paired_ci(d_ll), 'copula_better_share': float((d_ll < 0).mean())}
        res['delta_brier_copula_minus_indep'] = {'mean': float(d_br.mean()), 'ci95': paired_ci(d_br)}
        out['thresholds'][str(t)] = res
    out['totals'] = {'observed_mean': float(yt.mean()), 'observed_var': float(yt.var())}
    for m in over:
        out['totals'][m] = {'pred_mean': float(cond_mean[m].mean()),
                            'pred_var_total': float(cond_var[m].mean() + cond_mean[m].var())}
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--matches-tsv', required=True)
    ap.add_argument('--competitions-tsv', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    t0 = time.perf_counter()
    with open(a.competitions_tsv, newline='', encoding='utf-8-sig') as f:
        core_ids = {int(r['id']) for r in csv.DictReader(f, delimiter='\t') if r['slug'] in ce.CORE_SLUGS}
    res = run(ce.build_dataset(ce.load_matches(a.matches_tsv), core_ids))
    res['seconds']['total'] = round(time.perf_counter() - t0, 1)
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, indent=1)
    print(json.dumps({k: res[k] for k in ('n_test', 'rho_copula_train', 'implied_pearson_corr_test_mean', 'raw_corr_test', 'max_truncated_mass', 'seconds')}))


if __name__ == '__main__':
    main()
