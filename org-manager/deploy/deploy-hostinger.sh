#!/usr/bin/env bash
#
# Deploy the Org & Security console UI onto the Hostinger box.
# Run ON the Hostinger box, from the org-manager/ directory, as root:
#
#   A7_ORG_HMAC='<shared-secret>' bash deploy/deploy-hostinger.sh
#
# Use the SAME A7_ORG_HMAC value as the AWS deploy.
set -euo pipefail

APP_ROOT=/opt/a7-org-manager
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../hostinger-ui" && pwd)"
ACME_DIR=/var/www/a7-org-manager-acme
DOMAIN=org.srv1802669.hstgr.cloud
NGINX_CONF=/etc/nginx/conf.d/a7-org-manager.conf

# --code-only: sync code + restart, keep the secrets that are already installed.
CODE_ONLY=0
[ "${1:-}" = "--code-only" ] && CODE_ONLY=1
if [ "$CODE_ONLY" = 0 ]; then
  : "${A7_ORG_HMAC:?Set A7_ORG_HMAC to the shared secret (same on both hosts), or pass --code-only}"
  case "${A7_ORG_HMAC}" in
    *'<'*|*'>'*|*same-secret*|*shared-secret*)
      echo "A7_ORG_HMAC looks like a placeholder, not the real secret. Aborting before anything is overwritten." >&2
      exit 1 ;;
  esac
  if [ "${#A7_ORG_HMAC}" -lt 32 ]; then
    echo "A7_ORG_HMAC is shorter than 32 characters; refusing (use the value from openssl rand -hex 32)." >&2
    exit 1
  fi
elif [ ! -s "${APP_ROOT}/secrets/hmac.secret" ]; then
  echo "--code-only given but ${APP_ROOT}/secrets/hmac.secret does not exist yet; run a full deploy first." >&2
  exit 1
fi

echo "== 1. Sync code to ${APP_ROOT} =="
mkdir -p "${APP_ROOT}"
# --no-owner/--no-perms: files often arrive from a Windows laptop with a foreign
# uid and 0700 directories, which nginx cannot traverse (403 on /app/).
rsync -rlt --delete --no-owner --no-group --no-perms --exclude venv --exclude secrets --exclude __pycache__ "${SRC}/" "${APP_ROOT}/"
chown -R root:root "${APP_ROOT}/orgapp" "${APP_ROOT}/panel"
find "${APP_ROOT}/orgapp" "${APP_ROOT}/panel" -type d -exec chmod 755 {} +
find "${APP_ROOT}/orgapp" "${APP_ROOT}/panel" -type f -exec chmod 644 {} +
chmod 755 "${APP_ROOT}"

echo "== 2. Python venv =="
if [ ! -x "${APP_ROOT}/venv/bin/python" ]; then
  python3 -m venv "${APP_ROOT}/venv"
fi
"${APP_ROOT}/venv/bin/pip" install --quiet --upgrade pip
"${APP_ROOT}/venv/bin/pip" install --quiet -r "${APP_ROOT}/requirements.txt"

echo "== 3. Secrets =="
mkdir -p "${APP_ROOT}/secrets"
if [ "$CODE_ONLY" = 0 ]; then
  if [ -s "${APP_ROOT}/secrets/hmac.secret" ] && [ "$(cat "${APP_ROOT}/secrets/hmac.secret")" != "${A7_ORG_HMAC}" ]; then
    echo "An hmac.secret already exists and differs from A7_ORG_HMAC. Refusing to overwrite; delete it first if that is intended." >&2
    exit 1
  fi
  printf '%s' "${A7_ORG_HMAC}" > "${APP_ROOT}/secrets/hmac.secret"
else
  echo "(kept existing secrets)"
fi
if [ ! -s "${APP_ROOT}/secrets/session.secret" ]; then
  openssl rand -hex 32 > "${APP_ROOT}/secrets/session.secret"
fi
chmod 600 "${APP_ROOT}/secrets/"*.secret

echo "== 4. ACME webroot + nginx =="
mkdir -p "${ACME_DIR}"
cp "${APP_ROOT}/nginx-a7-org-manager.conf" "${NGINX_CONF}"

echo "== 5. TLS certificate =="
if [ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
  # First issue against the HTTP-01 challenge; nginx :80 block serves the webroot.
  certbot certonly --webroot -w "${ACME_DIR}" -d "${DOMAIN}" --non-interactive --agree-tos -m toolsfroma7@gmail.com || {
    echo "certbot failed — check that ${DOMAIN} resolves to this host, then re-run."; exit 1; }
fi
nginx -t
systemctl reload nginx

echo "== 6. systemd unit =="
cp "${APP_ROOT}/a7-org-manager-ui.service" /etc/systemd/system/a7-org-manager-ui.service
systemctl daemon-reload
systemctl enable --now a7-org-manager-ui.service
systemctl restart a7-org-manager-ui.service

echo "== 7. Health check =="
sleep 1
curl -fsS http://127.0.0.1:8790/healthz && echo
echo "Hostinger console deployed at https://${DOMAIN}/app/ (install handler: /app/install.html)"
