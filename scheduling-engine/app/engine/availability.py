"""
Net availability:  Λ'_p = Λ_p  minus  intervals ng pinned bookings ni p.
Lahat ay half-open [s, f).
"""


def merge_intervals(intervals) -> list:
    """I-sort at i-merge ang magkapatong O magkadikit na intervals."""
    items = sorted((s, f) for s, f in intervals if f > s)     # tanggal ang zero/negative length
    merged = []
    for s, f in items:
        if merged and s <= merged[-1][1]:                      # nag-ooverlap o dikit
            if f > merged[-1][1]:
                merged[-1] = (merged[-1][0], f)
        else:
            merged.append((s, f))
    return merged


def subtract_intervals(avail, blocked) -> list:
    """Ibawas ang lahat ng `blocked` sa `avail`. Pwedeng mahati ang isang interval."""
    blocked = merge_intervals(blocked)
    result = []
    for s, f in merge_intervals(avail):
        cur = s
        for bs, bf in blocked:
            if bf <= cur or bs >= f:                           # walang tama sa [cur, f)
                continue
            if bs > cur:
                result.append((cur, bs))
            cur = max(cur, bf)
            if cur >= f:
                break
        if cur < f:
            result.append((cur, f))
    return result


def fits(start: int, end: int, avail) -> bool:
    """True kung [start, end) ay buong nasa loob ng ISANG interval sa avail."""
    if end <= start:
        return False
    return any(s <= start and end <= f for s, f in avail)


def net_availability(providers, pinned) -> dict:
    """{provider_id: Λ'_p}. `pinned` = list ng Booking na may pinned provider."""
    result = {}
    for p in providers:
        blocked = [(b.start, b.end) for b in pinned if b.pinned == p.id]
        result[p.id] = subtract_intervals(merge_intervals(p.availability), blocked)
    return result
