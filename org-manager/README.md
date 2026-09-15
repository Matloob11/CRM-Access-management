# A7 Org & Security Manager

An admin-only Bitrix24 left-menu app to manage the **team tree** (departments,
supervisors/heads, members) and the **CRM security** on those teams (per role,
per entity: Self / Department / All) from one screen — and, on every change,
rebuild access codes and clear the CRM permission cache so the change is visible
immediately instead of intermittently.

It replaces the manual "edit Company Structure, then edit CRM permissions, then
hope the cache clears" flow that caused heads/agents to sometimes see nothing.

## Architecture (mirrors the existing "Recordings" left-menu app)

```
Bitrix sidebar "Company Structure" (LEFT_MENU placement)
   │  iframe → https://org.srv1802669.hstgr.cloud/app/  (static boot, BX24 SSO, admin-only)
   ▼
Hostinger console  (systemd a7-org-manager-ui, 127.0.0.1:8790, Starlette)
   │  HMAC-signed POST  (X-A7-Ops-Date / X-A7-Ops-Signature)
   ▼
AWS org API        (systemd a7-org-api, 127.0.0.1:8789, FastAPI, runs as bitrix)
   │  nginx /a7-org/ allows the Hostinger IP only
   ▼
php/*.php          boot Bitrix kernel → departments, UF_HEAD, role bind,
                   b_crm_role_perms, CAccess::UpdateCodes, cache clear, verify
```

The browser never touches Bitrix or the DB. The console only talks to the AWS API;
only the AWS API touches Bitrix. Both check the shared HMAC secret; nginx also
pins the source IP.

## Layout

```
org-manager/
├── aws-org-api/                 # runs on the Bitrix host as `bitrix`
│   ├── app.py                   # FastAPI + HMAC gate; shells to php/
│   ├── php/
│   │   ├── bootstrap.php         # Bitrix kernel + primitives (perm upsert, UF_HEAD,
│   │   │                         #   access-code rebuild, cache clear, attr rebuild)
│   │   ├── tree.php              # read departments + heads + members
│   │   ├── roles.php             # read roles + bindings + scope summary
│   │   ├── apply.php             # apply one team + security change (dry_run aware)
│   │   └── verify.php            # assert head=Dept, agents=Self
│   ├── requirements.txt
│   ├── a7-org-api.service
│   └── nginx-a7-org.conf         # /a7-org/ location, Hostinger-IP-only
├── hostinger-ui/                # runs on Hostinger
│   ├── orgapp/                   # Starlette app (auth, config, ops_client, app, static)
│   ├── panel/                    # index.html (boot) + install.html (placement.bind)
│   ├── requirements.txt
│   ├── a7-org-manager-ui.service
│   └── nginx-a7-org-manager.conf # org.srv1802669.hstgr.cloud vhost
├── deploy/
│   ├── deploy-aws.sh
│   └── deploy-hostinger.sh
└── README.md
```

## Deploy (one-time)

1. **Pick a shared secret** (used by both hosts):
   ```bash
   openssl rand -hex 32     # copy the value
   ```

2. **DNS**: point `org.srv1802669.hstgr.cloud` at the Hostinger VPS (the other
   `*.srv1802669.hstgr.cloud` names already do).

3. **AWS** (from this folder on the Bitrix box):
   ```bash
   scp -r org-manager a7-aws:~/           # from the laptop
   ssh a7-aws
   cd ~/org-manager
   A7_ORG_HMAC='<shared-secret>' sudo -E bash deploy/deploy-aws.sh
   ```

4. **Hostinger** (from this folder on the Hostinger box):
   ```bash
   scp -r org-manager a7-hostinger:~/     # from the laptop
   ssh a7-hostinger
   cd ~/org-manager
   A7_ORG_HMAC='<shared-secret>' bash deploy/deploy-hostinger.sh
   ```

5. **Register the menu item**: in the portal (as an admin) open
   `https://org.srv1802669.hstgr.cloud/app/install.html` once. It binds the
   `LEFT_MENU` item **"Company Structure"**. Re-running repairs the binding.

## Replacing the "Recordings" menu slot (optional, last step)

The new item is independent. If you want it to take the Recordings slot, remove
the old placement after the new one is verified working. On the AWS box:

```sql
-- inspect first
SELECT ID,TITLE,PLACEMENT_HANDLER FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU';
-- the Recordings row is ID 392 (handler recordings.srv1802669.hstgr.cloud)
DELETE FROM b_crm... -- NOT this; placement lives in b_rest_placement
DELETE FROM b_rest_placement WHERE ID=392;
```

Prefer the safe path: unbind via BX24 (`placement.unbind` for the Recordings
handler) rather than raw SQL. Keep the Recordings **app** on Hostinger unless you
are sure nothing else uses it.

## Using it

The console is a multi-page app (see `APP.md`):

- **Teams** (home) — stats, sales-team list with scope tags and last verify,
  live role scopes, attention list; search → open detail → Edit wizard
- **Create / Edit wizard** — Name → Leader → Agents → Access → Review
  (Preview → Apply → Verify), with a live draft summary
- **Verify** — assert head=Department, agents=Self; recent results
- **Roles** — live snapshot of CRM roles 54 / 36
- **Help** — plain-language guide

Safe defaults: Head = Department, Agent = Self. Never give agents **All**.

## API surface (Hostinger console → AWS engine)

| Console route | Engine script | What it does |
|---------------|---------------|--------------|
| `GET /api/org/tree` | `tree.php` | departments, heads, members |
| `GET /api/org/roles` | `roles.php` | roles, bindings, READ/WRITE/ADD scopes, ATTR/SETTINGS mismatches, C14 stage rows |
| `GET /api/org/users?q=` | `users.php` | search portal users (name / login / id) |
| `POST /api/org/apply` | `apply.php` | wizard: name, head, members (add + remove), role scopes, then rebuild + cache clear |
| `POST /api/org/mutate` | `mutate.php` | one targeted change with `dry_run`: `rename_dept`, `move_dept`, `create_dept`, `delete_dept`, `set_head`, `clear_head`, `add_member`, `remove_member`, `bind_role`, `unbind_role`, `set_perm`, `set_entity_scope`, `fix_role_attrs`, `fix_c14_stages`, `set_group` |
| `POST /api/org/verify` | `verify.php` | quick (membership + effective scope) or `deep` (bindings, group, access codes, row consistency, kanban stage rows, sample-record read test); failed checks carry a `fix` action |

Every write ends with `a7_finalize()`: access codes rebuilt for touched users,
HR structure sync, CRM caches cleared, entity access attributes refreshed.

Permission rows are always written with **both** `ATTR` and `SETTINGS`.
`CCrmPerms` (the layer that gates CRM lists and cards) reads `ATTR`; rows that
carry only `SETTINGS` grant nothing. `fix_role_attrs` repairs such rows.

## Deploying updates

Code-only redeploy (keeps the installed secrets):

```bash
scp -r org-manager a7-aws:~/ && ssh a7-aws "cd ~/org-manager && sudo bash deploy/deploy-aws.sh --code-only"
scp -r org-manager a7-hostinger:~/ && ssh a7-hostinger "cd ~/org-manager && bash deploy/deploy-hostinger.sh --code-only"
```

A full deploy (with `A7_ORG_HMAC=`) refuses placeholder values and, on
Hostinger, refuses to overwrite a different existing secret.

## Notes / limits

- One department per user (matches the current setup). Multi-department users are
  not modelled.
- Agents get the Agent role via group **G11**; the head gets role **54** via a
  `U<id>` binding. Both are configurable per apply (Advanced).
- `apply` re-registers entity access attributes for Leads and Owner Data (1042)
  for the affected assignees, bounded per call for speed.
