import pytest
from app.engine.validate import check_size, check_candidate, check_state
from app.engine.types import (Booking, Provider, Config, ADMIN, AFFILIATE, PC, ABA, AC,
                              INVALID_CANDIDATE, INVALID_STATE, PAYLOAD_TOO_LARGE)


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
def pmap(providers):
    return {p.id: p for p in providers}


def pc(id, s, e, provider):
    return Booking(id, PC, s, e, pinned=provider)


def un(id, service, s, e, admin):
    return Booking(id, service, s, e, kappa=admin)


# ---------------- check_size ----------------
def test_size_ok_and_too_large():
    assert check_size(499, Config(n_max=500)) is None
    assert check_size(500, Config(n_max=500)) == PAYLOAD_TOO_LARGE


# ---------------- check_candidate ----------------
def test_valid_candidates(pmap):
    assert check_candidate(pc("c", h(10), h(11), "A1"), pmap, t_run=0) is None
    assert check_candidate(Booking("c", ABA, h(9), h(11)), pmap, t_run=0) is None
    assert check_candidate(Booking("c", AC, h(9), h(10)), pmap, t_run=0) is None


def test_end_must_equal_start_plus_duration(pmap):
    assert check_candidate(Booking("c", ABA, h(9), h(10)), pmap, 0) == INVALID_CANDIDATE   # dapat 2 oras
    assert check_candidate(pc("c", h(9), h(11), "A1"), pmap, 0) == INVALID_CANDIDATE       # dapat 1 oras


def test_start_in_the_past(pmap):
    assert check_candidate(pc("c", h(9), h(10), "A1"), pmap, t_run=h(12)) == INVALID_CANDIDATE


def test_start_equal_to_t_run_is_ok(pmap):
    assert check_candidate(pc("c", h(12), h(13), "A1"), pmap, t_run=h(12)) is None


def test_missing_id_or_unknown_service(pmap):
    assert check_candidate(Booking("", PC, h(9), h(10), pinned="A1"), pmap, 0) == INVALID_CANDIDATE
    assert check_candidate(Booking("c", "XYZ", h(9), h(10)), pmap, 0) == INVALID_CANDIDATE


def test_pc_needs_known_pinned_provider(pmap):
    assert check_candidate(Booking("c", PC, h(9), h(10)), pmap, 0) == INVALID_CANDIDATE            # walang pinned
    assert check_candidate(pc("c", h(9), h(10), "NOPE"), pmap, 0) == INVALID_CANDIDATE            # hindi existing


def test_aba_ac_cannot_be_pinned(pmap):
    assert check_candidate(Booking("c", ABA, h(9), h(11), pinned="F1"), pmap, 0) == INVALID_CANDIDATE  # affiliate
    assert check_candidate(Booking("c", ABA, h(9), h(11), pinned="A1"), pmap, 0) == INVALID_CANDIDATE  # kahit admin


# ---------------- check_state ----------------
def test_empty_and_valid_state(providers):
    assert check_state([], providers) is None
    active = [pc("b1", h(9), h(10), "A1"), un("b2", AC, h(10), h(11), "A1")]
    assert check_state(active, providers) is None


def test_two_unpinned_overlap_same_admin(providers):
    active = [un("a", AC, h(10), h(11), "A1"), un("b", AC, h(10), h(11), "A1")]
    assert check_state(active, providers) == INVALID_STATE


def test_two_unpinned_overlap_different_admins_is_ok(providers):
    active = [un("a", AC, h(10), h(11), "A1"), un("b", AC, h(10), h(11), "A2")]
    assert check_state(active, providers) is None


def test_adjacent_bookings_are_not_overlap(providers):
    active = [un("a", AC, h(10), h(11), "A1"), un("b", AC, h(11), h(12), "A1")]
    assert check_state(active, providers) is None


def test_unpinned_outside_availability(providers):
    assert check_state([un("a", AC, h(7), h(8), "A1")], providers) == INVALID_STATE


def test_unpinned_overlaps_pinned_pc_of_same_admin(providers):
    # PC ni A1 9-10, tapos AC 9-10 naka-assign din kay A1 -> double-booked
    active = [pc("p", h(9), h(10), "A1"), un("a", AC, h(9), h(10), "A1")]
    assert check_state(active, providers) == INVALID_STATE


def test_unpinned_without_valid_admin(providers):
    assert check_state([Booking("a", AC, h(10), h(11))], providers) == INVALID_STATE            # walang kappa
    assert check_state([un("a", AC, h(10), h(11), "F1")], providers) == INVALID_STATE           # affiliate
    assert check_state([un("a", AC, h(10), h(11), "XX")], providers) == INVALID_STATE           # hindi existing


def test_pinned_outside_availability(providers):
    assert check_state([pc("p", h(7), h(8), "F1")], providers) == INVALID_STATE


def test_two_pinned_overlap_same_provider(providers):
    active = [pc("p1", h(10), h(11), "F1"), pc("p2", h(10), h(11), "F1")]
    assert check_state(active, providers) == INVALID_STATE


def test_pinned_unknown_provider(providers):
    assert check_state([pc("p", h(10), h(11), "NOPE")], providers) == INVALID_STATE
