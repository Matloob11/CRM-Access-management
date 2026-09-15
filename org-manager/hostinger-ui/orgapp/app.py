"""Starlette application for the Org & Security console.

Routes
  GET  /                 boot page (BX24 SSO handshake, then -> /org)
  POST /api/auth/bitrix  exchange a placement auth payload for a session cookie
  GET  /api/auth/me      200 when a valid session cookie is present, else 401
  GET  /org              the console (admin only)
  GET  /api/org/tree     company structure (admin only)
  GET  /api/org/roles    roles + bindings + scope summary (admin only)
  GET  /api/org/users    search portal users (admin only)
  POST /api/org/apply    apply a team + security change (admin only)
  POST /api/org/mutate   one targeted change (rename, head, member, role, perm) (admin only)
  POST /api/org/verify   verify a team's effective scope, quick or deep (admin only)
  GET  /healthz
  /static               static assets

Security: every page and API response carries a strict CSP that only lets the
Bitrix portal frame this origin; state-changing API calls must be same-origin.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from starlette.applications import Starlette
from starlette.middleware import Middleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import HTMLResponse, JSONResponse, RedirectResponse, Response
from starlette.routing import Mount, Route
from starlette.staticfiles import StaticFiles

from .auth import (
    SESSION_COOKIE_NAME,
    AdminUser,
    NotAdmin,
    authenticate_bitrix_token,
    create_session_cookie,
    require_admin,
)
from .config import Settings, load_settings
from .ops_client import OpsClient, OpsUnavailable

BASE_DIR = Path(__file__).resolve().parent
STATIC_DIR = BASE_DIR / "static"
PANEL_DIR = BASE_DIR.parent / "panel"


# --------------------------------------------------------------------------- #
# helpers
# --------------------------------------------------------------------------- #
def _apply_headers(response: Response, settings: Settings) -> None:
    response.headers["Content-Security-Policy"] = f"frame-ancestors {settings.frame_ancestors}"
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    response.headers["Cache-Control"] = "no-store"


def _same_origin(request: Request) -> bool:
    origin = request.headers.get("origin")
    if origin is None:
        # Non-CORS same-origin fetches may omit Origin; allow when Referer matches host.
        referer = request.headers.get("referer", "")
        host = request.headers.get("host", "")
        return host != "" and host in referer
    host = request.headers.get("host", "")
    return origin.split("//")[-1] == host


def _set_session_cookie(response: Response, user: AdminUser, settings: Settings) -> None:
    cookie = create_session_cookie(user)
    response.set_cookie(
        SESSION_COOKIE_NAME,
        cookie,
        max_age=86400 * 7,
        httponly=True,
        secure=True,
        samesite="none",  # third-party iframe context
        path="/",
    )


def _forbidden(settings: Settings) -> Response:
    resp = JSONResponse({"detail": "Admins only"}, status_code=403)
    _apply_headers(resp, settings)
    return resp


def _client() -> OpsClient:
    return OpsClient(load_settings())


# --------------------------------------------------------------------------- #
# pages
# --------------------------------------------------------------------------- #
async def home(request: Request) -> Response:
    settings = load_settings()
    # A live session skips straight to the console.
    try:
        require_admin(request)
        resp: Response = RedirectResponse("/org", status_code=302)
    except NotAdmin:
        resp = HTMLResponse((PANEL_DIR / "index.html").read_text(encoding="utf-8"))
    _apply_headers(resp, settings)
    return resp


async def console(request: Request) -> Response:
    settings = load_settings()
    try:
        require_admin(request)
    except NotAdmin:
        return _forbidden(settings)
    resp = HTMLResponse((STATIC_DIR / "console.html").read_text(encoding="utf-8"))
    _apply_headers(resp, settings)
    return resp


# --------------------------------------------------------------------------- #
# auth API
# --------------------------------------------------------------------------- #
async def api_auth_bitrix(request: Request) -> Response:
    settings = load_settings()
    try:
        payload = await request.json()
    except Exception:
        payload = {}
    auth_id = str(payload.get("AUTH_ID", "")).strip()
    domain = str(payload.get("DOMAIN", "")).strip()
    if not auth_id or not domain:
        resp = JSONResponse({"detail": "missing auth"}, status_code=400)
        _apply_headers(resp, settings)
        return resp

    user = authenticate_bitrix_token(domain, auth_id)
    if user is None:
        return _forbidden(settings)

    resp = JSONResponse({"ok": True, "user": user.label})
    _set_session_cookie(resp, user, settings)
    _apply_headers(resp, settings)
    return resp


async def api_auth_me(request: Request) -> Response:
    settings = load_settings()
    try:
        user = require_admin(request)
    except NotAdmin:
        return _forbidden(settings)
    resp = JSONResponse({"ok": True, "user": user.label})
    _apply_headers(resp, settings)
    return resp


# --------------------------------------------------------------------------- #
# org API
# --------------------------------------------------------------------------- #
def _guard(request: Request, settings: Settings, *, write: bool = False) -> Response | None:
    try:
        require_admin(request)
    except NotAdmin:
        return _forbidden(settings)
    if write and not _same_origin(request):
        resp = JSONResponse({"detail": "cross-origin refused"}, status_code=403)
        _apply_headers(resp, settings)
        return resp
    return None


def _ok(data: Any, settings: Settings) -> Response:
    resp = JSONResponse(data)
    _apply_headers(resp, settings)
    return resp


def _ops_error(exc: OpsUnavailable, settings: Settings) -> Response:
    resp = JSONResponse({"ok": False, "error": exc.message}, status_code=exc.status or 502)
    _apply_headers(resp, settings)
    return resp


async def api_org_tree(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings)) is not None:
        return blocked
    root = request.query_params.get("root")
    try:
        data = _client().tree(int(root) if root else None)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def api_org_roles(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings)) is not None:
        return blocked
    perms_for = request.query_params.get("perms_for", "")
    roles = [int(x) for x in perms_for.split(",") if x.strip().isdigit()]
    try:
        data = _client().roles(perms_for=roles)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def api_org_users(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings)) is not None:
        return blocked
    q = request.query_params.get("q", "")[:80]
    ids = [int(x) for x in request.query_params.get("ids", "").split(",") if x.strip().isdigit()]
    with_roles = request.query_params.get("roles", "") in {"1", "true"}
    try:
        limit = max(1, min(100, int(request.query_params.get("limit", "30"))))
    except ValueError:
        limit = 30
    try:
        data = _client().users(q=q, ids=ids, limit=limit, with_roles=with_roles)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def api_org_mutate(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings, write=True)) is not None:
        return blocked
    try:
        payload = await request.json()
    except Exception:
        payload = {}
    if not isinstance(payload, dict) or not str(payload.get("action", "")).strip():
        resp = JSONResponse({"ok": False, "error": "action required"}, status_code=400)
        _apply_headers(resp, settings)
        return resp
    try:
        data = _client().mutate(payload)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def api_org_apply(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings, write=True)) is not None:
        return blocked
    try:
        payload = await request.json()
    except Exception:
        payload = {}
    try:
        data = _client().apply(payload)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def api_org_verify(request: Request) -> Response:
    settings = load_settings()
    if (blocked := _guard(request, settings, write=True)) is not None:
        return blocked
    try:
        payload = await request.json()
    except Exception:
        payload = {}
    try:
        data = _client().verify(payload)
    except OpsUnavailable as exc:
        return _ops_error(exc, settings)
    return _ok(data, settings)


async def healthz(request: Request) -> Response:
    return JSONResponse({"ok": True, "service": "org-manager-ui"})


routes = [
    Route("/", home, methods=["GET"]),
    Route("/org", console, methods=["GET"]),
    Route("/api/auth/bitrix", api_auth_bitrix, methods=["POST"]),
    Route("/api/auth/me", api_auth_me, methods=["GET"]),
    Route("/api/org/tree", api_org_tree, methods=["GET"]),
    Route("/api/org/roles", api_org_roles, methods=["GET"]),
    Route("/api/org/users", api_org_users, methods=["GET"]),
    Route("/api/org/apply", api_org_apply, methods=["POST"]),
    Route("/api/org/mutate", api_org_mutate, methods=["POST"]),
    Route("/api/org/verify", api_org_verify, methods=["POST"]),
    Route("/healthz", healthz, methods=["GET"]),
    Mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static"),
]


class _StaticNoCache(BaseHTTPMiddleware):
    """Static assets must revalidate on every load: the console is a set of ES
    modules imported by relative path, so a cached api.js after a deploy would
    silently break the page. ETag/Last-Modified still make revalidation cheap."""

    async def dispatch(self, request: Request, call_next):
        response = await call_next(request)
        if request.url.path.startswith("/static/"):
            response.headers["Cache-Control"] = "no-cache, must-revalidate"
        return response


def create_app() -> Starlette:
    return Starlette(routes=routes, middleware=[Middleware(_StaticNoCache)])


app = create_app()
