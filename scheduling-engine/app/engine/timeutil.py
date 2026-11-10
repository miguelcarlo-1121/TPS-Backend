"""
Time helpers: ISO date/time <-> integer minutes.
T = 1440 * D + m   (D = day index, m = minute of day, 0..1439)
"""
from datetime import date, timedelta

EPOCH = date(2000, 1, 1)   # fixed reference para laging pareho ang day index


def to_minutes(date_str: str, time_str: str) -> int:
    """('2026-10-14', '09:30') -> minutes since EPOCH."""
    d = date.fromisoformat(date_str)            # raises ValueError kung mali
    parts = time_str.split(":")
    if len(parts) != 2 or not all(p.isdigit() for p in parts):
        raise ValueError(f"Invalid time (use 24-hour HH:MM): {time_str!r}")
    hh, mm = int(parts[0]), int(parts[1])
    if not (0 <= hh <= 23 and 0 <= mm <= 59):
        raise ValueError(f"Time out of range: {time_str!r}")
    return 1440 * (d - EPOCH).days + hh * 60 + mm


def to_iso(minutes: int) -> tuple:
    """minutes -> ('2026-10-14', '09:30')."""
    day, m = divmod(minutes, 1440)
    return (EPOCH + timedelta(days=day)).isoformat(), f"{m // 60:02d}:{m % 60:02d}"


def day_of(minutes: int) -> int:
    return minutes // 1440


def minute_of_day(minutes: int) -> int:
    """Minute of day (0..1439)."""
    return minutes % 1440


def iso_weekday(minutes: int) -> int:
    """ISO weekday (Monday=1 ... Sunday=7) for the given absolute minutes."""
    day = minutes // 1440
    return (EPOCH + timedelta(days=day)).isoweekday()


def time_to_minute_of_day(time_str: str) -> int:
    """'15:00' -> 900 (minute of day)."""
    parts = time_str.split(":")
    if len(parts) != 2 or not all(p.isdigit() for p in parts):
        raise ValueError(f"Invalid time (use 24-hour HH:MM): {time_str!r}")
    hh, mm = int(parts[0]), int(parts[1])
    if not (0 <= hh <= 23 and 0 <= mm <= 59):
        raise ValueError(f"Time out of range: {time_str!r}")
    return hh * 60 + mm
