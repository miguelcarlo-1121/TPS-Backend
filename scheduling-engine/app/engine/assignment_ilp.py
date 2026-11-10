"""
STAGE 1 — ILP admin assignment (minimum reassignment).

    min  Z = sum_{i in U_old} (1 - y[i, kappa_i])
    s.t. C1  sum_a y[i,a] = 1                         (i in U)
         C3  y[i,a] = 0  kung [s_i,f_i) hindi kasya sa Λ'_a
         C4  sum_{i: s_i <= s_k < f_i} y[i,a] <= 1     (a in A, k in U)
         C5  y binary

U     = ABA/AC bookings (active + candidate kung ABA/AC)
U_old = U na existing na (may kappa)
Gamit: scipy.optimize.milp (HiGHS). Determinism = fixed ordering + single solve.
"""
import time

import numpy as np
from scipy.optimize import milp, LinearConstraint, Bounds
from scipy.sparse import lil_matrix

from .availability import net_availability, fits
from .types import (Booking, Config, Stage1Result, ADMIN,
                    COMPLETED, SOLVER_LIMIT, SOLVER_ERROR, FEASIBLE, NOT_FEASIBLE,
                    OPTIMAL, FEASIBLE_NOT_PROVEN_OPTIMAL)


def _ms(t0: float) -> float:
    return (time.perf_counter() - t0) * 1000.0


def stage1(c: Booking, active: list, providers: list, cfg: Config) -> Stage1Result:
    t0 = time.perf_counter()

    # ---- 1. Hatiin ang bookings ------------------------------------------
    pinned_active = [b for b in active if b.pinned is not None]
    pinned_all = pinned_active + ([c] if c.pinned is not None else [])
    U_old = [b for b in active if b.pinned is None]
    U = U_old + ([c] if c.pinned is None else [])
    admins = sorted(p.id for p in providers if p.role == ADMIN)     # fixed order
    n_admin = len(admins)

    # ---- 2. Pinned candidate: simple check lang (walang solver) ----------
    # Kasya ba sa availability ng provider NA BAWAS ang IBANG pinned niya?
    # (net_availability ng pinned_active lang, hindi kasama ang c)
    if c.pinned is not None:
        net_without_c = net_availability(providers, pinned_active)
        if not fits(c.start, c.end, net_without_c.get(c.pinned, [])):
            return Stage1Result(COMPLETED, NOT_FEASIBLE, runtime_ms=_ms(t0))

    # ---- 3. Walang ABA/AC -> wala nang ililipat --------------------------
    if not U:
        return Stage1Result(COMPLETED, FEASIBLE, z_star=0, solver_status=OPTIMAL,
                            runtime_ms=_ms(t0))
    if n_admin == 0:
        return Stage1Result(COMPLETED, NOT_FEASIBLE, runtime_ms=_ms(t0))

    # ---- 4. Net availability (kasama na ang c kung pinned) ---------------
    net = net_availability(providers, pinned_all)

    # ---- 5. Sorting (determinism) ----------------------------------------
    U.sort(key=lambda b: (b.start, b.id))
    old_ids = {b.id for b in U_old}
    n = len(U)
    nv = n * n_admin

    def idx(i, a):
        return i * n_admin + a

    # ---- 6. Objective:  Z = |U_old| - sum y[i, kappa_i] ------------------
    obj = np.zeros(nv)
    for i, b in enumerate(U):
        if b.id in old_ids and b.kappa in admins:
            obj[idx(i, admins.index(b.kappa))] = -1.0

    # ---- 7. C1: assignment rows ------------------------------------------
    A_eq = lil_matrix((n, nv))
    for i in range(n):
        for a in range(n_admin):
            A_eq[i, idx(i, a)] = 1.0

    # ---- 8. C3: availability (bounds) ------------------------------------
    lb = np.zeros(nv)
    ub = np.ones(nv)
    for i, b in enumerate(U):
        for a, adm in enumerate(admins):
            if not fits(b.start, b.end, net[adm]):
                ub[idx(i, a)] = 0.0
    # Early exit: may booking na walang kahit isang admin na pwede -> infeasible na agad
    # (hindi na kailangan ng solver; malaking tipid sa Stage 2)
    for i in range(n):
        if all(ub[idx(i, a)] == 0 for a in range(n_admin)):
            return Stage1Result(COMPLETED, NOT_FEASIBLE, runtime_ms=_ms(t0))

    # ---- 9. C4: capacity rows (dedupe) -----------------------------------
    cap_rows = []                                   # list ng (a, [i, ...])
    seen = set()
    for a in range(n_admin):
        for k in range(n):
            members = tuple(i for i in range(n) if U[i].start <= U[k].start < U[i].end)
            key = (a, members)
            if key not in seen:
                seen.add(key)
                cap_rows.append((a, members))
    A_cap = lil_matrix((len(cap_rows), nv))
    for r, (a, members) in enumerate(cap_rows):
        for i in members:
            A_cap[r, idx(i, a)] = 1.0

    num_constraints = n + len(cap_rows)
    constraints = [LinearConstraint(A_eq.tocsr(), 1, 1),
                   LinearConstraint(A_cap.tocsr(), -np.inf, 1)]
    bounds = Bounds(lb, ub)
    integrality = np.ones(nv)
    options = {"time_limit": cfg.time_limit_s, "mip_rel_gap": cfg.mip_gap, "presolve": True}

    # ---- 10. Solve --------------------------------------------------------
    try:
        res = milp(obj, constraints=constraints, integrality=integrality,
                   bounds=bounds, options=options)
    except Exception:
        return Stage1Result(SOLVER_ERROR, num_variables=nv,
                            num_constraints=num_constraints, runtime_ms=_ms(t0))

    # ---- 11. Status mapping ----------------------------------------------
    # 0 optimal | 1 limit | 2 infeasible | 3 unbounded | 4 other
    if res.status == 2:
        return Stage1Result(COMPLETED, NOT_FEASIBLE, num_variables=nv,
                            num_constraints=num_constraints, runtime_ms=_ms(t0))
    if res.status == 1 and res.x is None:
        return Stage1Result(SOLVER_LIMIT, num_variables=nv,
                            num_constraints=num_constraints, runtime_ms=_ms(t0))
    if res.status not in (0, 1) or res.x is None:
        return Stage1Result(SOLVER_ERROR, num_variables=nv,
                            num_constraints=num_constraints, runtime_ms=_ms(t0))

    solver_status = OPTIMAL if res.status == 0 else FEASIBLE_NOT_PROVEN_OPTIMAL
    x = np.round(res.x).astype(int)

    # ---- 12. Z* ------------------------------------------------------------
    def reassigned(xv):
        count = 0
        for i, b in enumerate(U):
            if b.id in old_ids:
                a_new = next(a for a in range(n_admin) if xv[idx(i, a)] == 1)
                if admins[a_new] != b.kappa:
                    count += 1
        return count

    z_star = reassigned(x)

    # ---- 13. Tie-break (OPTIMAL lang): Z <= Z*, mas mababang admin number --
    if solver_status == OPTIMAL:
        keep_row = np.zeros((1, nv))
        for i, b in enumerate(U):
            if b.id in old_ids and b.kappa in admins:
                keep_row[0, idx(i, admins.index(b.kappa))] = 1.0
        n_old_valid = int(keep_row.sum())
        # sum y[i,kappa_i] >= n_old - z_star
        tie_cons = constraints + [LinearConstraint(keep_row, n_old_valid - z_star, np.inf)]
        tie_obj = np.zeros(nv)
        for i in range(n):
            for a in range(n_admin):
                tie_obj[idx(i, a)] = a + 1                     # index(a)
        try:
            res2 = milp(tie_obj, constraints=tie_cons, integrality=integrality,
                        bounds=bounds, options=options)
            if res2.x is not None and res2.status in (0, 1):
                x2 = np.round(res2.x).astype(int)
                if reassigned(x2) <= z_star:
                    x = x2
        except Exception:
            pass                                               # panatilihin ang unang sagot

    # ---- 14. Output --------------------------------------------------------
    assignment = {}
    for i, b in enumerate(U):
        chosen = [a for a in range(n_admin) if x[idx(i, a)] == 1]
        if len(chosen) != 1:
            return Stage1Result(SOLVER_ERROR, num_variables=nv,
                                num_constraints=num_constraints, runtime_ms=_ms(t0))
        assignment[b.id] = admins[chosen[0]]

    reassignments = [(b.id, b.kappa, assignment[b.id])
                     for b in U if b.id in old_ids and assignment[b.id] != b.kappa]

    return Stage1Result(COMPLETED, FEASIBLE, z_star=len(reassignments),
                        solver_status=solver_status, assignment=assignment,
                        reassignments=reassignments, num_variables=nv,
                        num_constraints=num_constraints, runtime_ms=_ms(t0))
