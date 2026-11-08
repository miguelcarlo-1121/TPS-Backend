from app.engine.availability import merge_intervals, subtract_intervals, fits, net_availability
from app.engine.types import Booking, Provider, ADMIN, AFFILIATE, PC, ABA, AC


def h(hour, minute=0):
    return hour * 60 + minute


# ---------- merge ----------
def test_merge_overlapping_and_adjacent():
    assert merge_intervals([(h(9), h(12)), (h(12), h(17))]) == [(h(9), h(17))]
    assert merge_intervals([(h(9), h(11)), (h(10), h(13))]) == [(h(9), h(13))]


def test_merge_sorts_and_keeps_gaps():
    assert merge_intervals([(h(14), h(15)), (h(9), h(10))]) == [(h(9), h(10)), (h(14), h(15))]


def test_merge_drops_empty():
    assert merge_intervals([(h(9), h(9)), (h(10), h(9))]) == []


# ---------- subtract ----------
def test_subtract_from_start():
    assert subtract_intervals([(h(9), h(17))], [(h(9), h(10))]) == [(h(10), h(17))]


def test_subtract_two_adjacent_blocks():
    assert subtract_intervals([(h(9), h(17))], [(h(9), h(10)), (h(10), h(11))]) == [(h(11), h(17))]


def test_subtract_middle_splits_interval():
    assert subtract_intervals([(h(9), h(17))], [(h(12), h(13))]) == [(h(9), h(12)), (h(13), h(17))]


def test_subtract_outside_changes_nothing():
    assert subtract_intervals([(h(9), h(17))], [(h(18), h(19))]) == [(h(9), h(17))]


def test_subtract_everything():
    assert subtract_intervals([(h(9), h(17))], [(h(8), h(18))]) == []


# ---------- fits ----------
def test_fits_inside():
    assert fits(h(10), h(11), [(h(9), h(17))])


def test_fits_exact_boundaries():
    assert fits(h(9), h(17), [(h(9), h(17))])


def test_not_fit_partially_outside():
    assert not fits(h(8), h(10), [(h(9), h(17))])


def test_not_fit_across_gap():
    # kalahati sa isa, kalahati sa isa pa -> hindi kasya
    assert not fits(h(11), h(14), [(h(9), h(12)), (h(13), h(17))])


# ---------- net availability ----------
def test_net_availability_subtracts_pinned_of_that_provider_only():
    full = ((h(9), h(17)),)
    providers = [Provider("A1", ADMIN, frozenset({PC, ABA, AC}), full),
                 Provider("A2", ADMIN, frozenset({PC, ABA, AC}), full)]
    pinned = [Booking("b1", PC, h(9), h(10), pinned="A1"),
              Booking("b2", PC, h(10), h(11), pinned="A1")]
    net = net_availability(providers, pinned)
    assert net["A1"] == [(h(11), h(17))]
    assert net["A2"] == [(h(9), h(17))]
