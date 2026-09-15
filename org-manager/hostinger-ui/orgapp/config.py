"""Environment-driven settings for the Org & Security console (Hostinger BFF).

Faithful to the recordings console's config so the shared auth/signing code
behaves identically; only the default secret/AWS-base paths point at this app.
No secret is ever returned by a page or written to a log.
"""

from __future__ import annotations

import os
from dataclasses import dataclass

DEFAULT_AWS_BASE = "http://127.0.0.1:8789"          # org API on the Bitrix host
DEFAULT_PORTAL_DOMAIN = "crm.a7grouprealestatedxb.com"
DEFAULT_FRAME_ANCESTORS = "https://crm.a7grouprealestatedxb.com"

SECRET_DIRS = ("/opt/a7-org-manager/secrets", "/etc/a7-org-manager")


def _flag(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _secret_from(env_name: str, file_env_name: str, basename: str) -> str:
    path = os.environ.get(file_env_name, "").strip()
    if path:
        try:
            with open(path, "r", encoding="utf-8") as handle:
                return handle.read().strip()
        except OSError:
            return ""
    inline = os.environ.get(env_name, "").strip()
    if inline:
        return inline
    for d in SECRET_DIRS:
        candidate = os.path.join(d, basename)
        if os.path.isfile(candidate):
            try:
                with open(candidate, "r", encoding="utf-8") as handle:
                    return handle.read().strip()
            except OSError:
                pass
    return ""


@dataclass(frozen=True)
class Settings:
    aws_base: str
    hmac_secret: str
    request_timeout: float
    local_fake_admin: bool
    use_mock_aws: bool
    frame_ancestors: str
    portal_domain: str
    session_secret: str

    @property
    def has_secret(self) -> bool:
        return bool(self.hmac_secret)


def load_settings() -> Settings:
    raw_portal = os.environ.get("A7_OPS_PORTAL_DOMAIN", DEFAULT_PORTAL_DOMAIN).strip()
    portal = raw_portal.replace("https://", "").replace("http://", "").rstrip("/")
    return Settings(
        aws_base=os.environ.get("A7_OPS_AWS_BASE", DEFAULT_AWS_BASE).rstrip("/"),
        hmac_secret=_secret_from("A7_OPS_HMAC_SECRET", "A7_OPS_HMAC_SECRET_FILE", "hmac.secret"),
        request_timeout=float(os.environ.get("A7_OPS_HTTP_TIMEOUT", "120")),
        local_fake_admin=_flag("A7_OPS_LOCAL_FAKE_ADMIN"),
        use_mock_aws=_flag("A7_OPS_USE_MOCK_AWS"),
        frame_ancestors=os.environ.get("A7_OPS_FRAME_ANCESTORS", DEFAULT_FRAME_ANCESTORS).strip(),
        portal_domain=portal,
        session_secret=_secret_from("A7_OPS_SESSION_SECRET", "A7_OPS_SESSION_SECRET_FILE", "session.secret"),
    )
