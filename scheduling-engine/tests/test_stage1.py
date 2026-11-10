import dataclasses
import random
from itertools import product

import pytest
from app.engine.assignment_ilp import stage1
from app.engine.types import (Booking, Provider, Config, ADMIN, AFFILIATE, PC, ABA, AC,
                              COMPLETED, SOLVER_LIMIT, SOLVER_ERROR, FEASIBLE, NOT_FEASIBLE,
                              OPTIMAL)


def h(hour, minute=0):
    return hour * 60 + minute


FULL = ((h(9), h(17)),)


@pytest.fixture
def providers():
    return [
        Provider("A1", ADMIN, frozenset({PC, ABA, AC}), FULL),
        Provider("A2", ADMIN, frozenset({PC, ABA, AC}), FULL),
        Provider("F1", AFFILIATE, frozenset({PC}), FULL),
    ]


@pytest.fixture
def cfg():
    return Config()


def pc(id, s, e, provider):
    return Booking(id, PC, s, e, pinned=provider)


def un(id, service, s, e, admin):
    return Booking(id, service, s, e, kappa=admin)


# ------------------------------------------------------------------ proposal examples
def test_example1_reassign_ac_to_a2(providers, cfg):
    """A9: PC A1 9-10 (existing), AC 10-11 nasa A1. Bagong PC A1 10-11."""
    active = [pc("bk_0001", h(9), h(10), "A1"), un("bk_0002", AC, h(10), h(11), "A1")]
    c = pc("cand_0001", h(10), h(11), "A1")
    r = stage1(c, active, providers, cfg)
    assert (r.job_status, r.decision, r.solver_status) == (COMPLETED, FEASIBLE, OPTIMAL)
    assert r.z_star == 1
    assert r.reassignments == [("bk_0002", "A1", "A2")]
    assert (r.num_variables, r.num_constraints) == (2, 3)


def test_example2_no_admin_free_for_whole_aba(providers, cfg):
    """Isa lang ang busy sa bawat sandali, pero walang libre sa buong 9-11."""
    active = [pc("b1", h(9), h(10), "A1"), pc("b2", h(10), h(11), "A2")]
    c = Booking("cand", ABA, h(9), h(11))
    r = stage1(c, active, providers, cfg)
    assert (r.job_status, r.decision) == (COMPLETED, NOT_FEASIBLE)


# ------------------------------------------------------------------ early exits
def test_no_unpinned_means_feasible_z0(providers, cfg):
    r = stage1(pc("cand", h(10), h(11), "F1"), [], providers, cfg)
    assert (r.decision, r.z_star, r.solver_status, r.num_variables) == (FEASIBLE, 0, OPTIMAL, 0)


def test_pinned_provider_already_busy(providers, cfg):
    active = [pc("b1", h(10), h(11), "F1")]
    assert stage1(pc("cand", h(10), h(11), "F1"), active, providers, cfg).decision == NOT_FEASIBLE


def test_pinned_provider_outside_availability(providers, cfg):
    assert stage1(pc("cand", h(7), h(8), "F1"), [], providers, cfg).decision == NOT_FEASIBLE


def test_affiliate_pc_does_not_disturb_admins(providers, cfg):
    active = [un("ac", AC, h(10), h(11), "A1")]
    r = stage1(pc("cand", h(10), h(11), "F1"), active, providers, cfg)
    assert (r.decision, r.z_star, r.reassignments) == (FEASIBLE, 0, [])


# ------------------------------------------------------------------ assignment logic
def test_candidate_takes_free_admin_without_reassigning(providers, cfg):
    active = [un("x", AC, h(10), h(11), "A1")]
    r = stage1(Booking("cand", AC, h(10), h(11)), active, providers, cfg)
    assert r.decision == FEASIBLE and r.z_star == 0
    assert r.assignment == {"x": "A1", "cand": "A2"}


def test_three_simultaneous_unpinned_is_infeasible(providers, cfg):
    active = [un("x", ABA, h(10), h(12), "A1"), un("y", ABA, h(10), h(12), "A2")]
    assert stage1(Booking("cand", ABA, h(10), h(12)), active, providers, cfg).decision == NOT_FEASIBLE


def test_reassignment_frees_an_admin_and_tiebreak_prefers_lower_admin(providers, cfg):
    """x: A1 9-10, y: A2 10-11. Bagong ABA 9-11 -> kailangang pagsamahin sina x at y sa iisang admin.
    Dalawang paraan (Z*=1); tie-break piliin yung mas mababang admin: y A2 -> A1, ABA kay A2."""
    active = [un("x", AC, h(9), h(10), "A1"), un("y", AC, h(10), h(11), "A2")]
    r = stage1(Booking("cand", ABA, h(9), h(11)), active, providers, cfg)
    assert r.decision == FEASIBLE and r.z_star == 1
    assert r.reassignments == [("y", "A2", "A1")]
    assert r.assignment["cand"] == "A2"


def test_deterministic(providers, cfg):
    active = [un("x", AC, h(9), h(10), "A1"), un("y", AC, h(10), h(11), "A2")]
    c = Booking("cand", ABA, h(9), h(11))
    a, b = stage1(c, active, providers, cfg), stage1(c, active, providers, cfg)
    assert dataclasses.replace(a, runtime_ms=0) == dataclasses.replace(b, runtime_ms=0)


def test_tiny_time_limit_never_raises(providers):
    cfg = Config(time_limit_s=1e-9)
    active = [un("x", AC, h(9), h(10), "A1"), un("y", AC, h(10), h(11), "A2")]
    r = stage1(Booking("cand", ABA, h(9), h(11)), active, providers, cfg)
    assert r.job_status in (COMPLETED, SOLVER_LIMIT, SOLVER_ERROR)


# ------------------------------------------------------------------ brute-force oracle (A2)
def _overlap(a, b):
    return a[0] < b[1] and b[0] < a[1]


def brute_force(c, active, providers):
    """Independent na sagot: hindi gumagamit ng net_availability o ng ILP."""
    admins = sorted(p.id for p in providers if p.role == ADMIN)
    raw = {p.id: p.availability for p in providers}
    inside = lambda b, p: any(s <= b.start and b.end <= f for s, f in raw[p])

    pinned = [b for b in active if b.pinned] + ([c] if c.pinned else [])
    # pinned candidate: kasya at walang overlap sa ibang pinned ng provider
    if c.pinned:
        if not inside(c, c.pinned):
            return (NOT_FEASIBLE, None)
        if any(b.pinned == c.pinned and _overlap((b.start, b.end), (c.start, c.end)) for b in active if b.pinned):
            return (NOT_FEASIBLE, None)

    U_old = [b for b in active if not b.pinned]
    U = U_old + ([c] if not c.pinned else [])
    best = None
    for combo in product(admins, repeat=len(U)):
        ok = True
        for b, adm in zip(U, combo):
            if not inside(b, adm) or any(p.pinned == adm and _overlap((p.start, p.end), (b.start, b.end)) for p in pinned):
                ok = False
                break
        if ok:
            for (b1, a1), (b2, a2) in ((x, y) for i, x in enumerate(zip(U, combo)) for y in list(zip(U, combo))[i + 1:]):
                if a1 == a2 and _overlap((b1.start, b1.end), (b2.start, b2.end)):
                    ok = False
                    break
        if ok:
            z = sum(1 for b, adm in zip(U, combo) if b in U_old and adm != b.kappa)
            best = z if best is None else min(best, z)
    return (FEASIBLE, best) if best is not None else (NOT_FEASIBLE, None)


def random_instance(seed, providers):
    rng = random.Random(seed)
    active, taken = [], {"A1": [], "A2": [], "F1": []}      # taken = intervals per provider (pinned + assigned)
    for n in range(rng.randint(0, 6)):
        service = rng.choice([PC, PC, ABA, AC])
        dur = {PC: 60, ABA: 120, AC: 60}[service]
        s = h(rng.randint(9, 16 if dur == 60 else 15))
        iv = (s, s + dur)
        if service == PC:
            prov = rng.choice(["A1", "A2", "F1"])
            if not any(_overlap(iv, t) for t in taken[prov]):
                taken[prov].append(iv)
                active.append(Booking(f"b{n}", PC, s, s + dur, pinned=prov))
        else:
            for adm in rng.sample(["A1", "A2"], 2):
                if not any(_overlap(iv, t) for t in taken[adm]):
                    taken[adm].append(iv)
                    active.append(Booking(f"b{n}", service, s, s + dur, kappa=adm))
                    break
    service = rng.choice([PC, ABA, AC])
    dur = {PC: 60, ABA: 120, AC: 60}[service]
    s = h(rng.randint(9, 16 if dur == 60 else 15))
    c = Booking("cand", service, s, s + dur, pinned=rng.choice(["A1", "A2", "F1"]) if service == PC else None)
    return c, active


@pytest.mark.parametrize("seed", range(300))
def test_ilp_matches_bruteforce(seed, providers, cfg):
    c, active = random_instance(seed, providers)
    r = stage1(c, active, providers, cfg)
    assert r.job_status == COMPLETED
    expected_decision, expected_z = brute_force(c, active, providers)
    assert (r.decision, r.z_star) == (expected_decision, expected_z), (c, active)
    if r.decision == FEASIBLE:
        assert len(r.reassignments) == r.z_star
