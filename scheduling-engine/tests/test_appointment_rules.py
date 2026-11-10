import dataclasses
import pytest

from app.engine.alternatives import ordered_candidates
from app.engine.assignment_ilp import stage1
from app.engine.orchestrator import check_and_recommend
from app.engine.timeutil import to_minutes
from app.engine.types import (Booking, Provider, Config, HistoryPref, ADMIN, AFFILIATE, PC, ABA, AC,
                              COMPLETED, INVALID, FEASIBLE, NOT_FEASIBLE, DURATION,
                              INVALID_CANDIDATE, INVALID_STATE)
from app.engine.validate import check_candidate, check_state


def h(hour, minute=0):
    return hour * 60 + minute


BASE_DATE = "2026-10-14"  # Wednesday
BASE_MIN = to_minutes(BASE_DATE, "00:00")


def slot(hour, minute=0, days_offset=0):
    return BASE_MIN + days_offset * 1440 + h(hour, minute)


FULL = tuple((slot(9, 0, d), slot(17, 0, d)) for d in range(8))


@pytest.fixture
def providers():
    return [
        Provider("A1", ADMIN, frozenset({PC, ABA, AC}), FULL),
        Provider("A2", ADMIN, frozenset({PC, ABA, AC}), FULL),
        Provider("F1", AFFILIATE, frozenset({PC}), FULL),
    ]


@pytest.fixture
def single_admin():
    return [
        Provider("A1", ADMIN, frozenset({PC, ABA, AC}), FULL),
    ]


@pytest.fixture
def cfg_30():
    return Config(slot_minutes=30)


# --------------------------------------------------------------------------
# 1. Service Appointment Durations
# - PC and AC appointments must last exactly 60 minutes.
# - ABA appointments must last exactly 120 minutes (2 hours).
# --------------------------------------------------------------------------
def test_durations_constants():
    assert DURATION[PC] == 60
    assert DURATION[AC] == 60
    assert DURATION[ABA] == 120


def test_candidate_durations_enforced(providers):
    pmap = {p.id: p for p in providers}
    t_run = slot(8)

    # PC: 60 minutes required
    assert check_candidate(Booking("c1", PC, slot(9), slot(10), pinned="A1"), pmap, t_run) is None
    assert check_candidate(Booking("c2", PC, slot(9), slot(9, 30), pinned="A1"), pmap, t_run) == INVALID_CANDIDATE
    assert check_candidate(Booking("c3", PC, slot(9), slot(11), pinned="A1"), pmap, t_run) == INVALID_CANDIDATE

    # AC: 60 minutes required
    assert check_candidate(Booking("c4", AC, slot(9), slot(10)), pmap, t_run) is None
    assert check_candidate(Booking("c5", AC, slot(9), slot(9, 30)), pmap, t_run) == INVALID_CANDIDATE
    assert check_candidate(Booking("c6", AC, slot(9), slot(11)), pmap, t_run) == INVALID_CANDIDATE

    # ABA: 120 minutes required (2 hours)
    assert check_candidate(Booking("c7", ABA, slot(9), slot(11)), pmap, t_run) is None
    assert check_candidate(Booking("c8", ABA, slot(9), slot(10)), pmap, t_run) == INVALID_CANDIDATE
    assert check_candidate(Booking("c9", ABA, slot(9), slot(10, 30)), pmap, t_run) == INVALID_CANDIDATE


# --------------------------------------------------------------------------
# 2. 30-Minute Booking Intervals
# - Slots generated every 30 minutes (e.g., 9:00, 9:30, 10:00, 10:30, etc.)
# --------------------------------------------------------------------------
def test_slots_generated_every_30_minutes(providers, cfg_30):
    c = Booking("c", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(14))
    candidates = list(ordered_candidates(c, hist, t_run=slot(8), cfg=cfg_30, providers=providers))

    starts = [start for _, _, start in candidates]
    # Verify all generated start times are aligned to 30-minute boundaries
    assert all((s - BASE_MIN) % 30 == 0 for s in starts)

    # Check presence of both :00 and :30 slots
    minutes_mod_60 = set((s - BASE_MIN) % 60 for s in starts)
    assert 0 in minutes_mod_60
    assert 30 in minutes_mod_60


# --------------------------------------------------------------------------
# 3. ABA Appointments Block the Entire 2-Hour Period
# - ABA 9:00 - 11:00 blocks 9:00-10:00, 9:30-10:30, 10:00-11:00, 10:30-11:30
# - Allows adjacent bookings at 8:00-9:00 and 11:00-12:00
# --------------------------------------------------------------------------
def test_aba_blocks_full_2_hour_period(single_admin, cfg_30):
    # Admin A1 has an active ABA booking from 9:00 to 11:00
    active = [Booking("b_aba", ABA, slot(9), slot(11), kappa="A1")]

    # Overlaps within the 2-hour window on the only available admin (A1)
    # Starts at 09:00 (full start overlap)
    c1 = Booking("c1", AC, slot(9), slot(10))
    assert stage1(c1, active, single_admin, cfg_30).decision == NOT_FEASIBLE

    # Starts at 09:30 (internal overlap starting 30 min in)
    c2 = Booking("c2", AC, slot(9, 30), slot(10, 30))
    assert stage1(c2, active, single_admin, cfg_30).decision == NOT_FEASIBLE

    # Starts at 10:00 (internal overlap starting 60 min in)
    c3 = Booking("c3", AC, slot(10), slot(11))
    assert stage1(c3, active, single_admin, cfg_30).decision == NOT_FEASIBLE

    # Starts at 10:30 (boundary overlap spanning 10:30 - 11:00)
    c4 = Booking("c4", AC, slot(10, 30), slot(11, 30))
    assert stage1(c4, active, single_admin, cfg_30).decision == NOT_FEASIBLE

    # Adjacent slots outside the 2-hour period must be FEASIBLE
    # Prior adjacent (08:00 - 09:00)
    c_prior = Booking("c_prior", AC, slot(8), slot(9))
    # Note: single_admin raw availability starts at 9:00 so 8:00 is outside availability,
    # but 11:00 - 12:00 is within 09:00-17:00 availability:
    c_after = Booking("c_after", AC, slot(11), slot(12))
    assert stage1(c_after, active, single_admin, cfg_30).decision == FEASIBLE


# --------------------------------------------------------------------------
# 4. Overlapping Appointments Are Rejected (including 30 min apart)
# --------------------------------------------------------------------------
def test_overlapping_appointments_rejected(single_admin, cfg_30):
    # Active PC on A1 at 09:00 - 10:00
    active = [Booking("b1", PC, slot(9), slot(10), pinned="A1")]

    # PC candidate starting 30 minutes apart at 09:30 - 10:30
    c_overlap = Booking("c_overlap", PC, slot(9, 30), slot(10, 30), pinned="A1")
    assert stage1(c_overlap, active, single_admin, cfg_30).decision == NOT_FEASIBLE

    # State validation also flags overlapping appointments
    invalid_active = [
        Booking("b1", PC, slot(9), slot(10), pinned="A1"),
        Booking("b2", PC, slot(9, 30), slot(10, 30), pinned="A1"),
    ]
    assert check_state(invalid_active, single_admin) == INVALID_STATE

    # Non-overlapping adjacent appointment at 10:00 - 11:00 is FEASIBLE
    c_valid = Booking("c_valid", PC, slot(10), slot(11), pinned="A1")
    assert stage1(c_valid, active, single_admin, cfg_30).decision == FEASIBLE


# --------------------------------------------------------------------------
# 5. Alternative Recommendations Follow 30-Minute Intervals and Service Durations
# --------------------------------------------------------------------------
def test_recommendations_follow_30min_interval_and_service_durations(providers, cfg_30):
    # A1 has PC at 09:00 - 10:00, A2 has PC at 10:00 - 11:00
    active = [
        Booking("b1", PC, slot(9), slot(10), pinned="A1"),
        Booking("b2", PC, slot(10), slot(11), pinned="A2"),
    ]
    # Request ABA at 09:00 - 11:00 (blocked)
    c_aba = Booking("c_aba", ABA, slot(9), slot(11))
    hist = HistoryPref(weekday=3, start_min=h(10))

    r = check_and_recommend(c_aba, active, providers, t_run=slot(8), cfg=cfg_30, history=hist)
    assert r.decision == NOT_FEASIBLE
    assert len(r.recommendations) > 0

    for rec in r.recommendations:
        # Must start on a 30-minute interval
        assert (rec.start - BASE_MIN) % 30 == 0
        # Must preserve ABA full 2-hour duration (120 minutes)
        assert rec.end - rec.start == 120
        # Admin assignment must remain internal (provider_id is None)
        assert rec.provider_id is None

    # Check for PC service
    active_pc = [Booking("b1", PC, slot(9), slot(10), pinned="F1")]
    c_pc = Booking("c_pc", PC, slot(9), slot(10), pinned="F1")
    r_pc = check_and_recommend(c_pc, active_pc, providers, t_run=slot(8), cfg=cfg_30, history=hist)

    for rec in r_pc.recommendations:
        # Must start on a 30-minute interval
        assert (rec.start - BASE_MIN) % 30 == 0
        # Must preserve PC 60-minute duration
        assert rec.end - rec.start == 60
        # Must retain selected psychologist F1
        assert rec.provider_id == "F1"

