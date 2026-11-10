"""Settings mula sa environment.

Loads scheduling-engine/.env automatically so SERVICE_TOKEN works even when
uvicorn was started without `--env-file .env`.
"""
from __future__ import annotations

import os
from pathlib import Path


def _load_dotenv() -> None:
    env_path = Path(__file__).resolve().parent.parent / ".env"
    if not env_path.exists():
        return

    for raw in env_path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        # Do not override a token already provided by the process environment.
        os.environ.setdefault(key, value)


_load_dotenv()

SERVICE_TOKEN = os.getenv("SERVICE_TOKEN", "")
