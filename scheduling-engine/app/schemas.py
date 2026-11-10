"""
Contract A (request) at Contract B (response), schema_version 2.0.

Dito: STRUCTURE lang ang sinusuri (uri, format ng petsa/oras, enum, unknown fields)
 -> mali = HTTP 400 INVALID_SCHEMA.
Ang SEMANTICS (end != start + duration, nasa past, ABA naka-pin sa affiliate, sirang
kasalukuyang state) ay sa engine -> HTTP 200, job_status INVALID + code.
"""
import re
from datetime import date, datetime
from typing import List, Literal, Optional

from pydantic import BaseModel, ConfigDict, Field, field_validator

Service = Literal["PC", "ABA", "AC"]
Role = Literal["ADMIN_PSYCHOLOGIST", "AFFILIATE"]
BookingStatus = Literal["HELD", "PAID", "CONFIRMED"]

_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
_TIME = re.compile(r"^([01]\d|2[0-3]):[0-5]\d$")           # 24-hour HH:MM lang


def _valid_date(v: str) -> str:
    if not _DATE.match(v):
        raise ValueError("date must be YYYY-MM-DD")
    date.fromisoformat(v)
    return v


def _valid_time(v: str) -> str:
    if not _TIME.match(v):
        raise ValueError("time must be 24-hour HH:MM")
    return v


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")               # unknown fields = rejected


# ----------------------------------------------------------------- Contract A
class ConfigIn(Strict):
    horizon_days: int = Field(7, ge=1, le=31)
    slot_minutes: int = Field(30, ge=1, le=1440)
    max_recommendations: int = Field(3, ge=1, le=20)
    max_candidate_checks: int = Field(500, ge=1, le=5000)
    time_limit_ms: int = Field(2000, ge=1, le=60000)
    mip_gap: float = Field(0.0, ge=0.0, le=1.0)
    max_bookings: int = Field(500, ge=1, le=5000)

    @field_validator("slot_minutes")
    @classmethod
    def _divides_day(cls, v: int) -> int:
        if 1440 % v != 0:
            raise ValueError("slot_minutes must divide 1440")
        return v


class AvailabilityIn(Strict):
    date: str
    start: str
    end: str

    _d = field_validator("date")(classmethod(lambda cls, v: _valid_date(v)))
    _s = field_validator("start", "end")(classmethod(lambda cls, v: _valid_time(v)))


class ProviderIn(Strict):
    provider_id: str = Field(min_length=1, max_length=64)
    role: Role
    eligible_services: List[Service]
    availability: List[AvailabilityIn] = []


class ActiveBookingIn(Strict):
    booking_id: str = Field(min_length=1, max_length=64)
    service: Service
    date: str
    start: str
    end: str
    pinned_provider_id: Optional[str] = None                # PC lang
    assigned_admin_id: Optional[str] = None                 # ABA/AC lang (kappa)
    status: BookingStatus

    _d = field_validator("date")(classmethod(lambda cls, v: _valid_date(v)))
    _s = field_validator("start", "end")(classmethod(lambda cls, v: _valid_time(v)))


class CandidateIn(Strict):
    booking_id: str = Field(min_length=1, max_length=64)
    service: Service
    date: str
    start: str
    end: str
    pinned_provider_id: Optional[str] = None

    _d = field_validator("date")(classmethod(lambda cls, v: _valid_date(v)))
    _s = field_validator("start", "end")(classmethod(lambda cls, v: _valid_time(v)))


class HistoryIn(Strict):
    weekday: int = Field(ge=1, le=7)
    start: str

    _s = field_validator("start")(classmethod(lambda cls, v: _valid_time(v)))


class SolveRequest(Strict):
    schema_version: Literal["2.0"]
    job_id: str = Field(min_length=1, max_length=64)
    run_at: str
    config: ConfigIn = ConfigIn()
    providers: List[ProviderIn]
    active_bookings: List[ActiveBookingIn] = []
    candidate: CandidateIn
    history: Optional[HistoryIn] = None

    @field_validator("run_at")
    @classmethod
    def _valid_run_at(cls, v: str) -> str:
        try:
            datetime.fromisoformat(v)
        except ValueError:
            raise ValueError("run_at must be ISO 8601, hal. 2026-10-14T08:00:00+08:00")
        return v


# ----------------------------------------------------------------- Contract B
class AssignmentItem(BaseModel):
    booking_id: str
    admin_id: str


class ReassignmentItem(BaseModel):
    booking_id: str
    from_admin_id: Optional[str]
    to_admin_id: str


class Stage1Out(BaseModel):
    z_star: int
    solver_status: str
    assignment: List[AssignmentItem]
    reassignments: List[ReassignmentItem]


class RecommendationOut(BaseModel):
    rank: Optional[int] = None
    tier: Optional[str] = None
    provider_id: Optional[str] = None                       # laging None para sa ABA/AC
    date: Optional[str] = None
    start: Optional[str] = None
    end: Optional[str] = None
    status: str                                             # RECOMMENDATION_FOUND | NO_ALTERNATIVE


class InvalidItem(BaseModel):
    code: str


class MetricsOut(BaseModel):
    runtime_ms: float
    num_variables: Optional[int] = None
    num_constraints: Optional[int] = None
    candidate_checks: int = 0


class SolveResponse(BaseModel):
    schema_version: str = "2.0"
    job_id: str
    job_status: str                                         # COMPLETED | INVALID | SOLVER_LIMIT | SOLVER_ERROR
    decision: Optional[str] = None                          # FEASIBLE | NOT_FEASIBLE
    stage1: Optional[Stage1Out] = None
    recommendations: List[RecommendationOut] = []
    invalid: List[InvalidItem] = []
    reason: Optional[str] = None                            # para sa SOLVER_LIMIT / SOLVER_ERROR
    metrics: MetricsOut
