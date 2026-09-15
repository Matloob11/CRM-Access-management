"""Signed client for the AWS Org & Security API.

The only outbound path from the console. Same signature scheme as the recordings
ops client:

    signing string = METHOD + "\n" + PATH + "\n" + DATE + "\n" + BODY
    X-A7-Ops-Date      = ISO-8601 UTC
    X-A7-Ops-Signature = lowercase hex HMAC-SHA256(secret, signing string)

All org endpoints are POST; the body carries the parameters.
"""

from __future__ import annotations

import hashlib
import hmac
import json
from datetime import datetime, timezone
from typing import Any, Dict, Optional

import httpx

from .config import Settings, load_settings

DATE_HEADER = "X-A7-Ops-Date"
SIGNATURE_HEADER = "X-A7-Ops-Signature"


class OpsUnavailable(Exception):
    def __init__(self, message: str, status: Optional[int] = None) -> None:
        super().__init__(message)
        self.message = message
        self.status = status


def _utc_now_header() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _signing_string(method: str, path: str, date: str, body: str) -> str:
    return "\n".join([method.upper(), path.split("?", 1)[0], date, body])


def _sign(secret: str, method: str, path: str, date: str, body: str) -> str:
    payload = _signing_string(method, path, date, body).encode("utf-8")
    return hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).hexdigest()


class OpsClient:
    def __init__(self, settings: Optional[Settings] = None) -> None:
        self.settings = settings or load_settings()

    def _post(self, path: str, payload: Optional[Dict[str, Any]] = None) -> Any:
        settings = self.settings
        body = json.dumps(payload or {}, separators=(",", ":"))
        if not settings.has_secret:
            raise OpsUnavailable("Shared ops secret is not configured on this host.")
        date = _utc_now_header()
        headers = {
            DATE_HEADER: date,
            SIGNATURE_HEADER: _sign(settings.hmac_secret, "POST", path, date, body),
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        url = f"{settings.aws_base}{path}"
        try:
            response = httpx.post(url, content=body.encode("utf-8"), headers=headers, timeout=settings.request_timeout)
        except httpx.HTTPError:
            raise OpsUnavailable("Org API is not reachable from this host.") from None

        try:
            data = response.json()
        except ValueError:
            raise OpsUnavailable(_status_message(response.status_code), status=response.status_code) from None

        if response.status_code >= 400 or not data.get("ok", False):
            message = data.get("error") or _status_message(response.status_code)
            raise OpsUnavailable(message, status=response.status_code)
        return data

    # -- reads -------------------------------------------------------------

    def tree(self, root: Optional[int] = None) -> Dict[str, Any]:
        return self._post("/v1/org/tree", {"root": root} if root else {})

    def roles(self, perms_for=None, entities=None) -> Dict[str, Any]:
        return self._post("/v1/org/roles", {"perms_for": perms_for or [], "entities": entities or []})

    def users(self, q: str = "", ids=None, limit: int = 30, with_roles: bool = False) -> Dict[str, Any]:
        return self._post("/v1/org/users", {"q": q, "ids": ids or [], "limit": limit, "with_roles": with_roles})

    # -- writes ------------------------------------------------------------

    def apply(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        return self._post("/v1/org/apply", payload)

    def mutate(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        return self._post("/v1/org/mutate", payload)

    def verify(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        return self._post("/v1/org/verify", payload)


def _status_message(status: int) -> str:
    if status == 401:
        return "Org API rejected this host's signature."
    if status == 403:
        return "Org API refused this action."
    if status == 404:
        return "Org API does not expose that endpoint."
    return f"Org API returned HTTP {status}."
