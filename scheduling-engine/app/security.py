"""Service token check: Laravel lang ang pwedeng tumawag."""
import hmac
from typing import Optional

from fastapi import Header, HTTPException

from . import config


def require_service_token(x_service_token: Optional[str] = Header(default=None)) -> None:
    expected = config.SERVICE_TOKEN          # binabasa sa bawat request
    if not expected:
        # Mas ligtas na tumanggi kaysa magbukas nang walang token
        raise HTTPException(status_code=503, detail="SERVICE_TOKEN is not configured")
    if not x_service_token or not hmac.compare_digest(x_service_token, expected):
        raise HTTPException(status_code=401, detail="Invalid service token")
