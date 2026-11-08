import pytest
from app.engine.timeutil import to_minutes, to_iso, day_of


def test_epoch_is_zero():
    assert to_minutes("2000-01-01", "00:00") == 0


def test_next_day_offset():
    assert to_minutes("2000-01-02", "01:30") == 1440 + 90


def test_roundtrip():
    for d, t in [("2026-10-14", "09:30"), ("2026-12-31", "23:59"), ("2027-01-01", "00:00")]:
        assert to_iso(to_minutes(d, t)) == (d, t)


def test_day_of():
    assert day_of(to_minutes("2000-01-03", "10:00")) == 2


@pytest.mark.parametrize("bad", ["25:00", "9:5x", "09:60", "9", "09:00:00", "12:00 PM"])
def test_bad_time_raises(bad):
    with pytest.raises(ValueError):
        to_minutes("2026-10-14", bad)


def test_bad_date_raises():
    with pytest.raises(ValueError):
        to_minutes("2026-13-40", "09:00")
