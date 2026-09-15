# Security

This repository is operational source code for a private CRM. Treat every clone as **code only**.

## Never commit

| Kind | Examples |
|------|----------|
| Passwords | Phone-mask unlock, Bitrix admin, DB |
| Keys | `~/.ssh/*`, `*_codex`, `*.pem` |
| API / HMAC | `A7_ORG_HMAC`, webhook tokens, REST app tokens |
| Host secrets | `/opt/*/secrets/*.secret` |
| Personal dumps | `.xlsx` rosters, Playwright session YAML |

`.gitignore` blocks the common cases. Before `git add -A`, run a search for `password`, `token`, `BEGIN OPENSSH`, and host IPv4s you do not intend to publish.

## How secrets live in production

- **Phone mask:** `A7_TL_PHONE_MASK_PASSWORD` on the CRM host, or the live config file under `/home/bitrix/www/bitrix/php_interface/` (not git).
- **Org Manager:** HMAC file on AWS and Hostinger, written by `deploy/*.sh` from an environment variable. Scripts refuse placeholder values.
- **Bitrix DB:** PHP reads Bitrix’s own `.settings.php` on the server. This repo never vendors that file.

## If a secret was committed

1. Rotate it on the live hosts immediately.  
2. Remove it from git history (`git filter-repo` / GitHub secret scanning).  
3. Do not rely on a later commit that only “deletes” the line.

## Least privilege

- Agents stay `ATTR=A`.  
- Heads stay `ATTR=D` on their department only.  
- Do not add mask-only staff to role 54 unless they are actual team heads.  
- Phone mask is an overlay; it does not replace CRM permissions.
