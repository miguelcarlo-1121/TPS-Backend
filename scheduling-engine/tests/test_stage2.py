import dataclasses
import pytest

from app.engine.alternatives import ordered_candidates
from app.engine.assignment_ilp import stage1
from app.engine.orchestrator import check_and_recommend
from app.engine.timeutil import to_minutes
from app.engine.types import (Booking, Provider, Config, HistoryPref, ADMIN, AFFILIATE, PC, ABA, AC,
                              COMPLETED, INVALID, FEASIBLE, NOT_FEASIBLE, NO_ALTERNATIVE, NO_HISTORY,
                              HISTORY_MATCH, HISTORY_NEAR,
                              INVALID_CANDIDATE, INVALID_STATE, PAYLOAD_TOO_LARGE, DURATION)


def h(hour, minute=0):
    return hour * 60 + minute


# Base day: 2026-10-14 (Wednesday, ISO weekday = 3)
BASE_DATE = "2026-10-14"
BASE_MIN = to_minutes(BASE_DATE, "00:00")


def dt(date_str, time_str):
    return to_minutes(date_str, time_str)


def slot(hour, minute=0, days_offset=0):
    return BASE_MIN + days_offset * 1440 + h(hour, minute)


FULL = tuple(
    (slot(9, 0, d), slot(17, 0, d))
    for d in range(8)
)


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


# ------------------------------------------------------------
# Case A: Requested schedule is FEASIBLE
# Expected: Stage 1 returns FEASIBLE. Stage 2 is skipped.
# ------------------------------------------------------------
def test_case_a_feasible_skips_stage2(providers, cfg):
    c = pc("c", slot(10), slot(11), "F1")
    hist = HistoryPref(weekday=3, start_min=h(15))
    r = check_and_recommend(c, [], providers, t_run=slot(8), cfg=cfg, history=hist)
    assert r.job_status == COMPLETED
    assert r.decision == FEASIBLE
    assert r.recommendations == []
    assert r.candidate_checks == 0
    assert r.stage1 is not None and r.stage1.decision == FEASIBLE


# ------------------------------------------------------------
# Case B: Requested schedule is NOT_FEASIBLE and historical matching schedule is FEASIBLE
# Expected: Historical candidate is checked first and returned as HISTORY_MATCH.
# ------------------------------------------------------------
def test_case_b_not_feasible_history_match(providers, cfg):
    # ABA 9-11 is blocked because A1 has PC 9-10 and A2 has PC 10-11
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    # Client previously had Wednesday (3) at 14:00
    hist = HistoryPref(weekday=3, start_min=h(14))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert r.decision == NOT_FEASIBLE
    assert len(r.recommendations) > 0
    first = r.recommendations[0]
    assert first.tier == HISTORY_MATCH
    assert first.start == slot(14)
    assert first.end == slot(16)


# ------------------------------------------------------------
# Case C: Historical matching schedule is NOT_FEASIBLE but nearby historical alternatives are FEASIBLE
# Expected: Return feasible alternatives classified as HISTORY_NEAR.
# ------------------------------------------------------------
def test_case_c_history_near_when_match_not_feasible(providers, cfg):
    # ABA 9-11 blocked; and historical 14:00 is also blocked on Wednesday
    active = [
        pc("b1", slot(9), slot(10), "A1"),
        pc("b2", slot(10), slot(11), "A2"),
        pc("b3", slot(14), slot(16), "A1"),
        pc("b4", slot(14), slot(16), "A2"),
    ]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert r.decision == NOT_FEASIBLE
    assert len(r.recommendations) > 0
    # Match was blocked, so all recommendations are HISTORY_NEAR
    assert all(rec.tier == HISTORY_NEAR for rec in r.recommendations)
    # The nearest start times on the same weekday (13:00 or 15:00 / 16:00 / 11:00) come first
    starts = [rec.start for rec in r.recommendations]
    assert slot(14) not in starts  # 14 was blocked


# ------------------------------------------------------------
# Case D: No completed appointment history
# Expected: NO_HISTORY, empty recommendations, and zero candidate checks.
# ------------------------------------------------------------
def test_case_d_no_history(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=None)

    assert r.decision == NOT_FEASIBLE
    assert r.recommendations == NO_HISTORY
    assert len(r.recommendations) == 0
    assert r.candidate_checks == 0


# ------------------------------------------------------------
# Case E: History exists but no candidate is feasible
# Expected: NO_ALTERNATIVE.
# ------------------------------------------------------------
def test_case_e_history_exists_no_candidate_feasible(providers):
    # Limit max_checks to 1 where that single candidate is blocked
    active = [
        pc("b1", slot(9), slot(10), "A1"),
        pc("b2", slot(10), slot(11), "A2"),
        pc("b3", slot(14), slot(16), "A1"),
        pc("b4", slot(14), slot(16), "A2"),
    ]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    # If max_checks=1, the engine only checks the historical match (14:00), which is blocked
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=Config(max_checks=1), history=hist)

    assert r.decision == NOT_FEASIBLE
    assert r.recommendations == NO_ALTERNATIVE
    assert r.candidate_checks == 1


# ------------------------------------------------------------
# Case F: PC recommendation
# Expected: Original selected psychologist is preserved.
# ------------------------------------------------------------
def test_case_f_pc_preserves_psychologist(providers, cfg):
    # F1 is busy at 10:00-11:00
    active = [pc("b1", slot(10), slot(11), "F1")]
    c = pc("c", slot(10), slot(11), "F1")
    hist = HistoryPref(weekday=3, start_min=h(10))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert r.decision == NOT_FEASIBLE
    assert len(r.recommendations) > 0
    assert all(rec.provider_id == "F1" for rec in r.recommendations)


# ------------------------------------------------------------
# Case G: ABA and AC recommendations
# Expected: No Affiliate assignment and no Admin identity exposed in client-facing recommendations.
# ------------------------------------------------------------
def test_case_g_aba_ac_no_provider_exposed(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(10))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert r.decision == NOT_FEASIBLE
    assert len(r.recommendations) > 0
    assert all(rec.provider_id is None for rec in r.recommendations)


# ------------------------------------------------------------
# Case H: Recommendation validation
# Expected: Every returned recommendation passes an independent Stage 1 feasibility check.
# ------------------------------------------------------------
def test_case_h_recommendation_validation(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert len(r.recommendations) > 0
    for rec in r.recommendations:
        c2 = dataclasses.replace(c, start=rec.start, end=rec.end, pinned=rec.provider_id)
        s1 = stage1(c2, active, providers, cfg)
        assert s1.job_status == COMPLETED
        assert s1.decision == FEASIBLE


# ------------------------------------------------------------
# Case I: Maximum candidate checks
# Expected: Stage 2 never exceeds MAX_CHECKS.
# ------------------------------------------------------------
def test_case_i_max_checks_respected(providers):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=Config(max_checks=2), history=hist)
    assert r.candidate_checks <= 2


# ------------------------------------------------------------
# Case J: Deterministic ranking
# Expected: Identical inputs produce the same candidate order.
# ------------------------------------------------------------
def test_case_j_deterministic_ranking(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))

    r1 = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)
    r2 = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    assert dataclasses.replace(r1, runtime_ms=0) == dataclasses.replace(r2, runtime_ms=0)


# ------------------------------------------------------------
# Case K: Requested time must not be recommended again
# ------------------------------------------------------------
def test_case_k_requested_time_not_recommended(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(9))  # history has the same time as requested
    r = check_and_recommend(c, active, providers, t_run=slot(8), cfg=cfg, history=hist)

    for rec in r.recommendations:
        assert rec.start != c.start


# ------------------------------------------------------------
# Case L: Only future candidate schedules within configured horizon
# ------------------------------------------------------------
def test_case_l_only_future_within_horizon(providers, cfg):
    active = [pc("b1", slot(9), slot(10), "A1"), pc("b2", slot(10), slot(11), "A2")]
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    t_run = slot(10)  # run_at is 10:00

    r = check_and_recommend(c, active, providers, t_run=t_run, cfg=cfg, history=hist)

    max_end = slot(0, 0, cfg.horizon_days)
    for rec in r.recommendations:
        assert rec.start >= t_run
        assert rec.start < max_end


# ------------------------------------------------------------
# Validation checks (Part 0)
# ------------------------------------------------------------
def test_invalid_candidate(providers, cfg):
    r = check_and_recommend(Booking("c", ABA, slot(9), slot(10)), [], providers, 0, cfg)  # ABA must be 2 hrs
    assert (r.job_status, r.invalid_reason) == (INVALID, INVALID_CANDIDATE)


def test_invalid_state(providers, cfg):
    active = [un("a", AC, slot(10), slot(11), "A1"), un("b", AC, slot(10), slot(11), "A1")]
    r = check_and_recommend(pc("c", slot(14), slot(15), "F1"), active, providers, 0, cfg)
    assert (r.job_status, r.invalid_reason) == (INVALID, INVALID_STATE)


def test_payload_too_large(providers):
    active = [pc("a", slot(9), slot(10), "F1"), pc("b", slot(10), slot(11), "F1")]
    r = check_and_recommend(pc("c", slot(11), slot(12), "F1"), active, providers, 0, Config(n_max=2))
    assert (r.job_status, r.invalid_reason) == (INVALID, PAYLOAD_TOO_LARGE)
