#!/usr/bin/env bash
#
# Deploy the Org & Security API onto the Bitrix (AWS) host.
# Run ON the AWS box, from the org-manager/ directory, as a sudo-capable user:
#
#   A7_ORG_HMAC='<shared-secret>' sudo -E bash deploy/deploy-aws.sh
#
# The SAME A7_ORG_HMAC value must be used for the Hostinger deploy so the two
# services can verify each other's signatures.
set -euo pipefail

APP_ROOT=/opt/a7-org-api
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../aws-org-api" && pwd)"
SECRET_DIR=/etc/a7-org-api
NGINX_SNIPPET=/etc/nginx/bx/site_settings/default/a7-org.conf

# --code-only: sync code + restart, keep the secret that is already installed.
CODE_ONLY=0
[ "${1:-}" = "--code-only" ] && CODE_ONLY=1
if [ "$CODE_ONLY" = 0 ]; then
  : "${A7_ORG_HMAC:?Set A7_ORG_HMAC to the shared secret (same on both hosts), or pass --code-only}"
  case "${A7_ORG_HMAC}" in
    *'<'*|*'>'*|*same-secret*|*shared-secret*)
      echo "A7_ORG_HMAC looks like a placeholder, not the real secret. Aborting." >&2; exit 1 ;;
  esac
elif [ ! -s "${SECRET_DIR}/hmac.secret" ]; then
  echo "--code-only given but ${SECRET_DIR}/hmac.secret does not exist yet; run a full deploy first." >&2
  exit 1
fi

echo "== 1. Sync code to ${APP_ROOT}/current =="
sudo mkdir -p "${APP_ROOT}/current"
sudo rsync -rlt --delete --no-owner --no-group --no-perms --exclude venv --exclude __pycache__ "${SRC}/" "${APP_ROOT}/current/"

echo "== 2. Python venv =="
if [ ! -x "${APP_ROOT}/venv/bin/python" ]; then
  sudo python3 -m venv "${APP_ROOT}/venv"
fi
sudo "${APP_ROOT}/venv/bin/pip" install --quiet --upgrade pip
sudo "${APP_ROOT}/venv/bin/pip" install --quiet -r "${APP_ROOT}/current/requirements.txt"

echo "== 3. Shared HMAC secret =="
if [ "$CODE_ONLY" = 0 ]; then
  sudo mkdir -p "${SECRET_DIR}"
  printf '%s' "${A7_ORG_HMAC}" | sudo tee "${SECRET_DIR}/hmac.secret" >/dev/null
  sudo chmod 640 "${SECRET_DIR}/hmac.secret"
  sudo chown root:bitrix "${SECRET_DIR}/hmac.secret"
else
  echo "(kept existing secret)"
fi

echo "== 4. Ownership (service runs as bitrix) =="
sudo chown -R bitrix:bitrix "${APP_ROOT}/current"

echo "== 5. systemd unit =="
sudo cp "${APP_ROOT}/current/a7-org-api.service" /etc/systemd/system/a7-org-api.service
sudo systemctl daemon-reload
sudo systemctl enable --now a7-org-api.service
sudo systemctl restart a7-org-api.service

echo "== 6. nginx /a7-org/ location (Hostinger-only) =="
sudo cp "${APP_ROOT}/current/nginx-a7-org.conf" "${NGINX_SNIPPET}"
sudo nginx -t
sudo systemctl reload nginx

echo "== 7. Health check =="
sleep 1
curl -fsS http://127.0.0.1:8789/healthz && echo
echo "AWS org API deployed."
