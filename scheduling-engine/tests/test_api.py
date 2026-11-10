import copy
import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app import config
from app.main import app

EXAMPLES = Path(__file__).resolve().parent.parent / "examples"
URL = "/api/v1/algorithm/solve"
TOKEN = "test-token"


def load(name):
    return json.loads((EXAMPLES / name).read_text(encoding="utf-8"))


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setattr(config, "SERVICE_TOKEN", TOKEN)
    return TestClient(app)


@pytest.fixture
def H():
    return {"X-Service-Token": TOKEN}


def strip_runtime(body):
    body = copy.deepcopy(body)
    body["metrics"].pop("runtime_ms", None)
    return body


# ------------------------------------------------------------------ health + security
def test_health(client):
    assert client.get("/health").json() == {"status": "ok"}


def test_missing_or_wrong_token_is_401(client):
    payload = load("example1_feasible.json")
    assert client.post(URL, json=payload).status_code == 401
    assert client.post(URL, json=payload, headers={"X-Service-Token": "wrong"}).status_code == 401


def test_auth_is_checked_before_body(client):
    assert client.post(URL, json={"junk": 1}).status_code == 401


def test_unconfigured_token_refuses(monkeypatch):
    monkeypatch.setattr(config, "SERVICE_TOKEN", "")
    r = TestClient(app).post(URL, json=load("example1_feasible.json"), headers={"X-Service-Token": ""})
    assert r.status_code == 503


# ------------------------------------------------------------------ A9: Contract A example
def test_example1_feasible_with_one_reassignment(client, H):
    r = client.post(URL, json=load("example1_feasible.json"), headers=H)
    assert r.status_code == 200
    b = r.json()
    assert (b["schema_version"], b["job_id"], b["job_status"], b["decision"]) == \
           ("2.0", "job_20261014_0001", "COMPLETED", "FEASIBLE")
    assert b["stage1"]["z_star"] == 1
    assert b["stage1"]["solver_status"] == "OPTIMAL"
    assert b["stage1"]["assignment"] == [{"booking_id": "bk_0002", "admin_id": "A2"}]
    assert b["stage1"]["reassignments"] == [
        {"booking_id": "bk_0002", "from_admin_id": "A1", "to_admin_id": "A2"}]
    assert b["recommendations"] == [] and b["invalid"] == []
    assert (b["metrics"]["num_variables"], b["metrics"]["num_constraints"],
            b["metrics"]["candidate_checks"]) == (2, 3, 0)


def test_example2_not_feasible_with_recommendations(client, H):
    p = load("example2_not_feasible.json")
    p["history"] = {"weekday": 3, "start": "10:00"}
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 200
    b = r.json()
    assert (b["job_status"], b["decision"], b["stage1"]) == ("COMPLETED", "NOT_FEASIBLE", None)
    got = [(x["rank"], x["tier"], x["provider_id"], x["date"], x["start"], x["end"], x["status"])
           for x in b["recommendations"]]
    assert got == [
        (1, "HISTORY_MATCH", None, "2026-10-14", "10:00", "12:00", "RECOMMENDATION_FOUND"),
        (2, "HISTORY_NEAR", None, "2026-10-14", "10:30", "12:30", "RECOMMENDATION_FOUND"),
        (3, "HISTORY_NEAR", None, "2026-10-14", "11:00", "13:00", "RECOMMENDATION_FOUND"),
    ]
    assert b["metrics"]["candidate_checks"] > 0


def test_example2_not_feasible_without_history(client, H):
    p = load("example2_not_feasible.json")  # walang history field
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 200
    b = r.json()
    assert (b["job_status"], b["decision"]) == ("COMPLETED", "NOT_FEASIBLE")
    assert b["recommendations"] == []
    assert b["metrics"]["candidate_checks"] == 0
    assert b["reason"] == "NO_HISTORY"


def test_no_alternative_response_shape(client, H):
    p = load("example2_not_feasible.json")
    p["history"] = {"weekday": 3, "start": "08:00"}
    p["config"]["max_candidate_checks"] = 1               # 08:00 lang ang masusubukan (labas sa 09:00 availability)
    b = client.post(URL, json=p, headers=H).json()
    assert b["decision"] == "NOT_FEASIBLE"
    assert b["recommendations"] == [{"rank": None, "tier": None, "provider_id": None, "date": None,
                                     "start": None, "end": None, "status": "NO_ALTERNATIVE"}]


# ------------------------------------------------------------------ errors
def test_unknown_field_is_400(client, H):
    p = load("example1_feasible.json")
    p["candidate"]["client_name"] = "Juan"                # bawal ang dagdag na field (at PII!)
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 400 and r.json()["error"] == "INVALID_SCHEMA"
    assert "Juan" not in r.text                           # hindi ini-echo ang input


@pytest.mark.parametrize("bad", ["25:00", "9:00", "09:00:00", "12:00 PM"])
def test_bad_time_format_is_400(client, H, bad):
    p = load("example1_feasible.json")
    p["candidate"]["start"] = bad
    assert client.post(URL, json=p, headers=H).status_code == 400


def test_bad_date_and_service_and_version_are_400(client, H):
    for field, value in [("date", "2026-13-40"), ("service", "XYZ")]:
        p = load("example1_feasible.json")
        p["candidate"][field] = value
        assert client.post(URL, json=p, headers=H).status_code == 400
    p = load("example1_feasible.json")
    p["schema_version"] = "1.0"
    assert client.post(URL, json=p, headers=H).status_code == 400


def test_payload_too_large_is_413(client, H):
    p = load("example1_feasible.json")
    p["config"]["max_bookings"] = 2                       # 2 active + 1 candidate > 2
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 413
    assert r.json()["invalid"] == [{"code": "PAYLOAD_TOO_LARGE"}]


def test_wrong_end_time_is_invalid_candidate_not_http_error(client, H):
    p = load("example1_feasible.json")
    p["candidate"]["end"] = "12:00"                       # PC dapat 60 minuto
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 200
    b = r.json()
    assert (b["job_status"], b["decision"]) == ("INVALID", None)
    assert b["invalid"] == [{"code": "INVALID_CANDIDATE"}]


def test_aba_pinned_to_affiliate_is_invalid_candidate(client, H):
    p = load("example2_not_feasible.json")
    p["providers"].append({"provider_id": "F1", "role": "AFFILIATE", "eligible_services": ["PC"],
                           "availability": [{"date": "2026-10-14", "start": "09:00", "end": "17:00"}]})
    p["candidate"]["pinned_provider_id"] = "F1"
    b = client.post(URL, json=p, headers=H).json()
    assert b["invalid"] == [{"code": "INVALID_CANDIDATE"}]


def test_inconsistent_state_is_invalid_state(client, H):
    p = load("example1_feasible.json")
    p["active_bookings"][1]["assigned_admin_id"] = None   # ABA/AC na walang admin
    b = client.post(URL, json=p, headers=H).json()
    assert (b["job_status"], b["invalid"]) == ("INVALID", [{"code": "INVALID_STATE"}])


# ------------------------------------------------------------------ A6, A7 + crash safety
def test_deterministic(client, H):
    p = load("example2_not_feasible.json")
    a = client.post(URL, json=p, headers=H).json()
    b = client.post(URL, json=p, headers=H).json()
    assert strip_runtime(a) == strip_runtime(b)


def test_tiny_time_limit_returns_controlled_status(client, H):
    p = load("example1_feasible.json")
    p["config"]["time_limit_ms"] = 1
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 200
    assert r.json()["job_status"] in ("COMPLETED", "SOLVER_LIMIT", "SOLVER_ERROR")


def test_unexpected_exception_becomes_solver_error(client, H, monkeypatch):
    def boom(*a, **k):
        raise RuntimeError("boom")
    monkeypatch.setattr("app.api.solve.check_and_recommend", boom)
    r = client.post(URL, json=load("example1_feasible.json"), headers=H)
    assert r.status_code == 200
    b = r.json()
    assert (b["job_status"], b["reason"]) == ("SOLVER_ERROR", "INTERNAL_ERROR")


@pytest.mark.parametrize("bad_wd", [0, 8, -1, 10])
def test_bad_history_weekday_is_400(client, H, bad_wd):
    p = load("example1_feasible.json")
    p["history"] = {"weekday": bad_wd, "start": "10:00"}
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 400 and r.json()["error"] == "INVALID_SCHEMA"


@pytest.mark.parametrize("bad_time", ["25:00", "9:00", "invalid", "12:65"])
def test_bad_history_time_is_400(client, H, bad_time):
    p = load("example1_feasible.json")
    p["history"] = {"weekday": 3, "start": bad_time}
    r = client.post(URL, json=p, headers=H)
    assert r.status_code == 400 and r.json()["error"] == "INVALID_SCHEMA"
