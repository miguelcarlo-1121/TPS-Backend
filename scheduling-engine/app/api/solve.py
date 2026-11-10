"""POST /api/v1/algorithm/solve  — internal lang (Laravel + service token)."""
import logging

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

from ..engine.orchestrator import check_and_recommend
from ..engine.types import SOLVER_ERROR, INVALID, PAYLOAD_TOO_LARGE
from ..mapper import to_engine, to_response
from ..schemas import SolveRequest, SolveResponse, InvalidItem, MetricsOut
from ..security import require_service_token

log = logging.getLogger("engine")

router = APIRouter(prefix="/api/v1/algorithm", dependencies=[Depends(require_service_token)])


@router.post("/solve", response_model=SolveResponse)
def solve(req: SolveRequest):
    # `def` (hindi async) -> tumatakbo sa threadpool, hindi nababara ang event loop ng solver

    # HTTP 413: lampas sa max_bookings (bago pa tumakbo ang engine)
    if len(req.active_bookings) + 1 > req.config.max_bookings:
        body = SolveResponse(job_id=req.job_id, job_status=INVALID,
                             invalid=[InvalidItem(code=PAYLOAD_TOO_LARGE)],
                             metrics=MetricsOut(runtime_ms=0.0))
        return JSONResponse(status_code=413, content=body.model_dump())

    try:
        candidate, active, providers, t_run, cfg, history = to_engine(req)
        result = check_and_recommend(candidate, active, providers, t_run, cfg, history)
        return to_response(req.job_id, result)
    except Exception:
        # Hindi dapat mag-crash ang service: structured result, HTTP 200
        log.exception("solve failed job_id=%s", req.job_id)
        return SolveResponse(job_id=req.job_id, job_status=SOLVER_ERROR, reason="INTERNAL_ERROR",
                             metrics=MetricsOut(runtime_ms=0.0))
