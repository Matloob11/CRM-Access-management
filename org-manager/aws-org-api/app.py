"""A7 Org & Security API — privileged Bitrix mutation service (AWS).

Runs on the Bitrix host as the `bitrix` user. It is the only component allowed to
change departments, department heads, CRM role bindings and role permissions. The
Hostinger console never touches Bitrix directly; it calls the endpoints here over
an HMAC-signed channel, and nginx additionally restricts /a7-org/ to the Hostinger
VPS IP.

Signing contract (identical to the recordings ops API so one client works for both):

    message   = METHOD + "\n" + PATH + "\n" + DATE + "\n" + BODY
    headers   = X-A7-Ops-Date (ISO-8601 UTC), X-A7-Ops-Signature (hex HMAC-SHA256)
    freshness = |now - DATE| <= 300 seconds

Each endpoint hands its JSON body to a small PHP script (php/*.php) that boots the
Bitrix kernel and does the work, then returns the script's JSON verbatim. Keeping
the Bitrix logic in PHP means it reuses the exact, already-verified primitives
from the handoff scripts rather than re-implementing them in Python.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import subprocess
from datetime import datetime, timezone
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

PHP_BIN = os.getenv("A7_ORG_PHP_BIN", "/usr/bin/php")
PHP_DIR = Path(os.getenv("A7_ORG_PHP_DIR", str(Path(__file__).resolve().parent / "php")))
PHP_TIMEOUT = int(os.getenv("A7_ORG_PHP_TIMEOUT", "120"))
MAX_SKEW = 300

# Map a request path to the PHP script that services it. Only these are callable.
ROUTES = {
    "/v1/org/tree": "tree.php",
    "/v1/org/roles": "roles.php",
    "/v1/org/users": "users.php",
    "/v1/org/apply": "apply.php",
    "/v1/org/mutate": "mutate.php",
    "/v1/org/verify": "verify.php",
}


def shared_secret() -> str:
    path = os.getenv("A7_ORG_HMAC_SECRET_FILE", "").strip()
    if path:
        try:
            return Path(path).read_text(encoding="utf-8").strip()
        except OSError:
            return ""
    return os.getenv("A7_ORG_HMAC_SECRET", "").strip()


def run_php(script: str, body: bytes) -> JSONResponse:
    script_path = PHP_DIR / script
    if not script_path.is_file():
        return JSONResponse({"ok": False, "error": "engine script missing"}, status_code=500)
    try:
        proc = subprocess.run(
            [PHP_BIN, str(script_path)],
            input=body or b"",
            capture_output=True,
            timeout=PHP_TIMEOUT,
        )
    except subprocess.TimeoutExpired:
        return JSONResponse({"ok": False, "error": "engine timed out"}, status_code=504)

    out = proc.stdout.decode("utf-8", "replace").strip()
    if not out:
        err = proc.stderr.decode("utf-8", "replace").strip()[-500:]
        return JSONResponse({"ok": False, "error": "engine produced no output", "stderr": err}, status_code=500)
    try:
        payload = json.loads(out)
    except ValueError:
        return JSONResponse({"ok": False, "error": "engine returned malformed JSON", "raw": out[:500]}, status_code=500)

    status = 200 if payload.get("ok") else 400
    return JSONResponse(payload, status_code=status)


def create_app() -> FastAPI:
    app = FastAPI(title="A7 Org & Security API")
    app.state.secret = shared_secret()

    @app.middleware("http")
    async def authenticate(request: Request, call_next):
        if request.url.path in {"/healthz", "/v1/healthz"}:
            return await call_next(request)

        date = request.headers.get("X-A7-Ops-Date", "")
        signature = request.headers.get("X-A7-Ops-Signature", "")
        body = await request.body()
        try:
            stamp = datetime.fromisoformat(date.replace("Z", "+00:00"))
            age = abs((datetime.now(timezone.utc) - stamp.astimezone(timezone.utc)).total_seconds())
        except (ValueError, TypeError):
            age = MAX_SKEW + 1

        message = b"\n".join((request.method.encode(), request.url.path.encode(), date.encode(), body))
        expected = hmac.new(app.state.secret.encode(), message, hashlib.sha256).hexdigest()
        if not app.state.secret or age > MAX_SKEW or not hmac.compare_digest(signature, expected):
            return JSONResponse({"detail": "unauthorized"}, status_code=401)

        # Stash the verified body so the handler need not re-read the stream.
        request.state.raw_body = body
        return await call_next(request)

    @app.get("/healthz")
    @app.get("/v1/healthz")
    def healthz():
        return {"ok": True, "service": "org-security-api"}

    @app.post("/v1/org/tree")
    async def org_tree(request: Request):
        return run_php(ROUTES["/v1/org/tree"], request.state.raw_body)

    @app.post("/v1/org/roles")
    async def org_roles(request: Request):
        return run_php(ROUTES["/v1/org/roles"], request.state.raw_body)

    @app.post("/v1/org/users")
    async def org_users(request: Request):
        return run_php(ROUTES["/v1/org/users"], request.state.raw_body)

    @app.post("/v1/org/apply")
    async def org_apply(request: Request):
        return run_php(ROUTES["/v1/org/apply"], request.state.raw_body)

    @app.post("/v1/org/mutate")
    async def org_mutate(request: Request):
        return run_php(ROUTES["/v1/org/mutate"], request.state.raw_body)

    @app.post("/v1/org/verify")
    async def org_verify(request: Request):
        return run_php(ROUTES["/v1/org/verify"], request.state.raw_body)

    return app


app = create_app()
