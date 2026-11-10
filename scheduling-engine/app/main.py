from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from .api import solve

app = FastAPI(title="Threefold Scheduling Engine", version="1.0.0")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.exception_handler(RequestValidationError)
async def invalid_schema(request: Request, exc: RequestValidationError):
    # HTTP 400 INVALID_SCHEMA. Walang echo ng input value (para walang tagas ng data).
    details = [{"loc": [str(x) for x in e.get("loc", [])], "msg": e.get("msg", "")}
               for e in exc.errors()]
    return JSONResponse(status_code=400, content={"error": "INVALID_SCHEMA", "details": details})


app.include_router(solve.router)
