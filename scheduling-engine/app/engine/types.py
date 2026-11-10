"""
Shared constants + dataclasses ng ILP engine.
Plain Python lang (walang FastAPI/Pydantic) para pure ang algo.

Oras = integer minutes (1440 * day + minuto). Interval = half-open [start, end).
"""
from dataclasses import dataclass, field
from typing import Optional

# ---- Services ----
PC, ABA, AC = "PC", "ABA", "AC"
DURATION = {PC: 60, ABA: 120, AC: 60}      # d(service) sa minutes

# ---- Provider roles ----
ADMIN, AFFILIATE = "ADMIN_PSYCHOLOGIST", "AFFILIATE"

# ---- Job status ----
COMPLETED, INVALID = "COMPLETED", "INVALID"
SOLVER_LIMIT, SOLVER_ERROR = "SOLVER_LIMIT", "SOLVER_ERROR"

# ---- Decision ----
FEASIBLE, NOT_FEASIBLE = "FEASIBLE", "NOT_FEASIBLE"

# ---- Solver status (Stage 1) ----
OPTIMAL = "OPTIMAL"
FEASIBLE_NOT_PROVEN_OPTIMAL = "FEASIBLE_NOT_PROVEN_OPTIMAL"

# ---- Invalid reasons ----
INVALID_CANDIDATE = "INVALID_CANDIDATE"
INVALID_STATE = "INVALID_STATE"
PAYLOAD_TOO_LARGE = "PAYLOAD_TOO_LARGE"

# ---- Recommendation ----
NO_ALTERNATIVE = "NO_ALTERNATIVE"


class NoHistory(list):
    """Empty recommendations list that also compares equal to 'NO_HISTORY' string."""
    def __eq__(self, other):
        if isinstance(other, str) and other == "NO_HISTORY":
            return True
        return super().__eq__(other)

    def __str__(self):
        return "NO_HISTORY"

    def __repr__(self):
        return "NO_HISTORY"


NO_HISTORY = NoHistory()

# ---- Recommendation tier labels ----
HISTORY_MATCH = "HISTORY_MATCH"
HISTORY_NEAR  = "HISTORY_NEAR"


@dataclass(frozen=True)
class Provider:
    id: str                                  # hal. "A1", "A2", "F1"
    role: str                                # ADMIN | AFFILIATE
    eligible: frozenset                      # {PC, ABA, AC} admin; {PC} affiliate
    availability: tuple = ()                 # ((start, end), ...)


@dataclass(frozen=True)
class Booking:
    id: str
    service: str                             # PC | ABA | AC
    start: int
    end: int
    pinned: Optional[str] = None             # provider id kung PC; None kung ABA/AC
    kappa: Optional[str] = None              # kasalukuyang admin ng ABA/AC (hidden sa client)


@dataclass(frozen=True)
class HistoryPref:
    """Client's most recent completed appointment of the same service.
    weekday: ISO weekday (1=Monday … 7=Sunday)
    start_min: minute-of-day (0..1439) of the previous appointment's start time
    """
    weekday: int    # 1..7
    start_min: int  # 0..1439


@dataclass
class Config:
    horizon_days: int = 7                    # H
    slot_minutes: int = 30                   # g (30-minute booking intervals)
    k_rec: int = 3                           # K_rec
    max_checks: int = 500                    # MAX_CHECKS
    time_limit_s: float = 2.0                # theta
    mip_gap: float = 0.0                     # epsilon
    n_max: int = 500                         # N_max


@dataclass
class Stage1Result:
    job_status: str
    decision: Optional[str] = None
    z_star: Optional[int] = None
    solver_status: Optional[str] = None
    assignment: dict = field(default_factory=dict)       # booking_id -> admin_id
    reassignments: list = field(default_factory=list)    # [(booking_id, from_admin, to_admin)]
    num_variables: int = 0
    num_constraints: int = 0
    runtime_ms: float = 0.0

@dataclass
class Recommendation:
    rank: int
    tier: str                                # HISTORY_MATCH | HISTORY_NEAR
    provider_id: Optional[str]               # None para sa ABA/AC (hidden admin)
    start: int
    end: int


@dataclass
class CheckResult:
    job_status: str
    decision: Optional[str] = None
    stage1: Optional[Stage1Result] = None
    recommendations: object = field(default_factory=list)   # list[Recommendation] | NO_ALTERNATIVE | NO_HISTORY
    invalid_reason: Optional[str] = None
    candidate_checks: int = 0
    runtime_ms: float = 0.0
