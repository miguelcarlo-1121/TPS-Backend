"""Contract <-> engine types. Dito lang nagko-convert ng petsa/oras <-> minutes."""
from datetime import datetime

from .engine.types import (Booking, Config, HistoryPref, Provider, CheckResult, FEASIBLE, INVALID,
                           SOLVER_LIMIT, SOLVER_ERROR, NO_ALTERNATIVE, NO_HISTORY)
from .engine.timeutil import to_minutes, to_iso, time_to_minute_of_day
from .schemas import (SolveRequest, SolveResponse, Stage1Out, AssignmentItem, ReassignmentItem,
                      RecommendationOut, InvalidItem, MetricsOut)


def to_engine(req: SolveRequest):
    """SolveRequest -> (candidate, active, providers, t_run, cfg, history)"""
    run = datetime.fromisoformat(req.run_at)               # wall-clock sa sarili nitong offset
    t_run = to_minutes(run.date().isoformat(), f"{run.hour:02d}:{run.minute:02d}")

    providers = [
        Provider(
            id=p.provider_id,
            role=p.role,
            eligible=frozenset(p.eligible_services),
            availability=tuple(sorted((to_minutes(a.date, a.start), to_minutes(a.date, a.end))
                                      for a in p.availability)),
        )
        for p in req.providers
    ]
    active = [
        Booking(b.booking_id, b.service, to_minutes(b.date, b.start), to_minutes(b.date, b.end),
                pinned=b.pinned_provider_id, kappa=b.assigned_admin_id)
        for b in req.active_bookings
    ]
    c = req.candidate
    candidate = Booking(c.booking_id, c.service, to_minutes(c.date, c.start),
                        to_minutes(c.date, c.end), pinned=c.pinned_provider_id)

    k = req.config
    cfg = Config(horizon_days=k.horizon_days, slot_minutes=k.slot_minutes,
                 k_rec=k.max_recommendations, max_checks=k.max_candidate_checks,
                 time_limit_s=k.time_limit_ms / 1000.0, mip_gap=k.mip_gap, n_max=k.max_bookings)

    history = None
    if req.history is not None:
        history = HistoryPref(
            weekday=req.history.weekday,
            start_min=time_to_minute_of_day(req.history.start),
        )

    return candidate, active, providers, t_run, cfg, history


def to_response(job_id: str, r: CheckResult) -> SolveResponse:
    s1 = r.stage1
    metrics = MetricsOut(
        runtime_ms=round(r.runtime_ms, 2),
        num_variables=s1.num_variables if s1 else None,
        num_constraints=s1.num_constraints if s1 else None,
        candidate_checks=r.candidate_checks,
    )

    stage1_out = None
    if r.decision == FEASIBLE and s1 is not None:
        stage1_out = Stage1Out(
            z_star=s1.z_star,
            solver_status=s1.solver_status,
            assignment=[AssignmentItem(booking_id=b, admin_id=a) for b, a in s1.assignment.items()],
            reassignments=[ReassignmentItem(booking_id=b, from_admin_id=f, to_admin_id=t)
                           for b, f, t in s1.reassignments],
        )

    recs = []
    if r.recommendations == NO_ALTERNATIVE:
        recs = [RecommendationOut(status=NO_ALTERNATIVE)]
    elif r.recommendations == NO_HISTORY:
        recs = []
    else:
        for x in r.recommendations:
            d, st = to_iso(x.start)
            _, en = to_iso(x.end)
            recs.append(RecommendationOut(rank=x.rank, tier=x.tier,
                                          provider_id=x.provider_id, date=d, start=st, end=en,
                                          status="RECOMMENDATION_FOUND"))

    reason = None
    if r.job_status == SOLVER_LIMIT:
        reason = "TIME_LIMIT_NO_INCUMBENT"
    elif r.job_status == SOLVER_ERROR:
        reason = "SOLVER_FAILURE"
    elif r.recommendations == NO_HISTORY:
        reason = "NO_HISTORY"

    invalid = [InvalidItem(code=r.invalid_reason)] if r.job_status == INVALID and r.invalid_reason else []

    return SolveResponse(job_id=job_id, job_status=r.job_status, decision=r.decision,
                         stage1=stage1_out, recommendations=recs, invalid=invalid,
                         reason=reason, metrics=metrics)
