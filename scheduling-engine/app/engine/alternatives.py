"""
STAGE 2 — History-based candidate generator.

Rank priority:
  1. Same weekday as the historical appointment.
  2. Closest starting time to the historical starting time.
  3. Closest to the originally requested schedule.
  4. Earlier candidate start time as a deterministic tie-breaker.

Classification:
  - HISTORY_MATCH if both weekday and start time match history.
  - HISTORY_NEAR otherwise.

Provider rules:
  - PC: retains originally selected psychologist (c.pinned).
  - ABA/AC: None (admin assignment remains internal).
"""
from typing import Iterator, Optional, Tuple

from .timeutil import iso_weekday, minute_of_day
from .types import Booking, Config, HistoryPref, PC, HISTORY_MATCH, HISTORY_NEAR


def ordered_candidates(c: Booking, history: HistoryPref, t_run: int,
                       cfg: Config, providers: list = None) -> Iterator[Tuple[str, Optional[str], int]]:
    """Yield (tier, provider_id, start).
    tier: HISTORY_MATCH | HISTORY_NEAR
    provider_id: c.pinned para sa PC, None para sa ABA/AC
    """
    day = c.start // 1440
    n_slots = cfg.horizon_days * 1440 // cfg.slot_minutes          # 168 kung H=7, g=60
    grid = [1440 * day + j * cfg.slot_minutes for j in range(n_slots)]
    # lampas na ang t_run, at hindi na uulitin ang oras na pinili na ng client
    grid = [a for a in grid if a >= t_run and a != c.start]

    # Deterministic ranking according to 4 priorities:
    # 1. Same weekday as historical appointment (0 if matching, 1 otherwise)
    # 2. Closest starting time to historical starting time
    # 3. Closest to originally requested schedule
    # 4. Earlier candidate start time as tie-breaker
    grid.sort(key=lambda a: (
        0 if iso_weekday(a) == history.weekday else 1,
        abs(minute_of_day(a) - history.start_min),
        abs(a - c.start),
        a,
    ))

    provider = c.pinned if c.service == PC else None

    for a in grid:
        is_match = (iso_weekday(a) == history.weekday and minute_of_day(a) == history.start_min)
        tier = HISTORY_MATCH if is_match else HISTORY_NEAR
        yield (tier, provider, a)
