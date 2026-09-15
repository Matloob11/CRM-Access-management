"""Bitrix admin gate for the Org console (Hostinger).

Verifies a Bitrix placement auth payload (DOMAIN + AUTH_ID) against the portal's
REST API and only admits portal administrators, then mints a signed session
cookie. Mirrors the recordings console's proven flow; the cookie name differs so
the two consoles keep independent sessions.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import logging
import time
from dataclasses import dataclass
from typing import Callable, Optional

import httpx
from starlette.requests import Request

from .config import load_settings

logger = logging.getLogger(__name__)

SESSION_COOKIE_NAME = "a7_org_session"


@dataclass(frozen=True)
class AdminUser:
    user_id: str
    label: str
    source: str


class NotAdmin(Exception):
    def __init__(self, reason: str = "Admins only") -> None:
        super().__init__(reason)
        self.reason = reason


AdminResolver = Callable[[Request], Optional[AdminUser]]
_resolver: Optional[AdminResolver] = None


def set_admin_resolver(resolver: Optional[AdminResolver]) -> None:
    global _resolver
    _resolver = resolver


def _fetch_bitrix_user(domain: str, auth_id: str, timeout: float = 15.0) -> dict:
    url = f"https://{domain}/rest/user.current.json"
    with httpx.Client(timeout=timeout) as client:
        response = client.get(url, params={"auth": auth_id})
    if response.status_code != 200:
        raise ValueError(f"Bitrix rejected token (HTTP {response.status_code})")
    body = response.json()
    if "result" not in body:
        raise ValueError(body.get("error_description") or "Bitrix returned no user")
    return body["result"]


def _is_portal_admin(domain: str, auth_id: str, timeout: float = 15.0) -> bool:
    try:
        url = f"https://{domain}/rest/user.admin.json"
        with httpx.Client(timeout=timeout) as client:
            response = client.get(url, params={"auth": auth_id})
        if response.status_code != 200:
            return False
        result = response.json().get("result")
        if result is True or result == 1:
            return True
        return str(result).strip().upper() in {"Y", "TRUE", "1"}
    except Exception as exc:
        logger.info("Could not check portal admin status: %s", exc)
        return False


def _user_looks_like_admin(profile: dict) -> bool:
    if not isinstance(profile, dict):
        return False
    for key in ("ADMIN", "IS_ADMIN", "admin", "is_admin"):
        val = profile.get(key)
        if val is True or val == 1:
            return True
        if str(val).strip().upper() in {"Y", "1", "TRUE", "YES"}:
            return True
    return False


def authenticate_bitrix_token(
    domain: str,
    auth_id: str,
    expected_domain: Optional[str] = None,
    timeout: float = 15.0,
) -> Optional[AdminUser]:
    settings = load_settings()
    expected = (expected_domain or settings.portal_domain).split("//")[-1].rstrip("/")
    clean_domain = domain.split("//")[-1].rstrip("/")

    if clean_domain.lower() != expected.lower():
        logger.warning("Auth attempt from unexpected domain: %s (expected %s)", clean_domain, expected)
        return None

    try:
        user_profile = _fetch_bitrix_user(clean_domain, auth_id, timeout=timeout)
        is_admin = _user_looks_like_admin(user_profile) or _is_portal_admin(clean_domain, auth_id, timeout=timeout)
    except Exception as exc:
        logger.warning("Bitrix authentication failed: %s", exc)
        return None

    if not is_admin:
        logger.info("User %s authenticated but is not a Bitrix admin", user_profile.get("ID"))
        return None

    user_id = str(user_profile.get("ID", "")).strip()
    first_name = user_profile.get("NAME", "").strip()
    last_name = user_profile.get("LAST_NAME", "").strip()
    full_name = " ".join(filter(None, [first_name, last_name])).strip() or f"User {user_id}"
    return AdminUser(user_id=user_id, label=full_name, source="bitrix")


def create_session_cookie(user: AdminUser, secret: Optional[str] = None) -> str:
    settings = load_settings()
    sec = secret or settings.session_secret
    if not sec:
        raise ValueError("session secret is not configured")
    payload = {"user_id": user.user_id, "label": user.label, "source": user.source, "ts": int(time.time())}
    raw = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    b64 = base64.urlsafe_b64encode(raw).decode("ascii")
    sig = hmac.new(sec.encode("utf-8"), b64.encode("ascii"), hashlib.sha256).hexdigest()
    return f"{b64}.{sig}"


def verify_session_cookie(cookie_value: str, secret: Optional[str] = None, max_age_seconds: int = 86400 * 7) -> Optional[AdminUser]:
    if not cookie_value or "." not in cookie_value:
        return None
    settings = load_settings()
    sec = secret or settings.session_secret
    if not sec:
        return None
    b64, sig = cookie_value.split(".", 1)
    expected_sig = hmac.new(sec.encode("utf-8"), b64.encode("ascii"), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(sig, expected_sig):
        return None
    try:
        raw = base64.urlsafe_b64decode(b64.encode("ascii"))
        payload = json.loads(raw.decode("utf-8"))
        ts = int(payload.get("ts", 0))
        if time.time() - ts > max_age_seconds:
            return None
        return AdminUser(
            user_id=str(payload.get("user_id", "")),
            label=str(payload.get("label", "Admin")),
            source=str(payload.get("source", "bitrix")),
        )
    except Exception:
        return None


def require_admin(request: Request) -> AdminUser:
    resolver = _resolver
    if resolver is not None:
        user = resolver(request)
        if user is None:
            raise NotAdmin("Admins only")
        return user

    cookie = request.cookies.get(SESSION_COOKIE_NAME)
    if cookie:
        user = verify_session_cookie(cookie)
        if user is not None:
            return user

    if load_settings().local_fake_admin:
        return AdminUser(user_id="local", label="local admin", source="fake-admin")

    raise NotAdmin("Admins only")
