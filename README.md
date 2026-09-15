# CRM Access Management

Operational toolkit for **A7 Group Bitrix24 CRM**: who can see which records, how team departments stay exclusive, and how selected users see phone numbers with the last four digits hidden.

This repository is the source of truth for **roles, visibility rules, org-manager (Company Structure console), and the phone-mask layer**. It is meant for operators who already have SSH access to the CRM host. It is not a public SaaS app and it does **not** contain live passwords, API keys, HMAC secrets, or SSH keys.

---

## What this system enforces

| Role | Bitrix role | Visibility | Lead edit |
|------|-------------|------------|-----------|
| **Agent** | Role **36** via intranet group **G11** | Self only (`ATTR=A`) — assigned Leads, Contacts, Companies, Deals, Owner Data, Availability | Yes, own records |
| **Head / Team Leader** | Role **54** bound to `U{headId}` | Own + department (`ATTR=D`) — the head’s team only | **Leads are read-only** (no write / add / delete / export / stage move) |
| **Mask-only staff** (HR, Admin, selected leads) | Unchanged CRM roles | Unchanged lists | Unchanged; **phone last-4 hidden** until unlock |

Entities covered by the Agent/Head scopes:

- `LEAD`, `CONTACT`, `COMPANY`, `DEAL`
- Owner Data SPA `DYNAMIC_1042` (category **13**)
- Availability `DYNAMIC_1042` (category **14**) and its stage READ rows

Do **not** grant agents `ATTR=X` / `ALL`. That exposes company-wide CRM data.

---

## Architecture

```
┌─────────────────────────────┐
│  Bitrix24 CRM (AWS host)    │
│  roles 36 / 54, UF_DEPARTMENT│
│  phone-mask JS + PHP tool   │
└──────────────▲──────────────┘
               │ Bitrix kernel (php/*.php as user `bitrix`)
┌──────────────┴──────────────┐
│  Org API  (127.0.0.1:8789)  │
│  FastAPI + HMAC             │
└──────────────▲──────────────┘
               │ HMAC-signed POST only
┌──────────────┴──────────────┐
│  Org Manager UI (Hostinger) │
│  iframe from Bitrix menu    │
└─────────────────────────────┘
```

The browser never talks to the Bitrix database. The Hostinger console signs requests; the AWS org API verifies HMAC and then runs PHP that boots the Bitrix kernel.

Phone masking is a separate CRM-side layer: `init.php` injects JS **only** for user IDs listed in the mask config (heads and mask-only staff). Agents never receive the script, so they keep full numbers.

---

## Repository layout

```
CRM-Access-management/
├── README.md                 This document
├── HOWTO.md                  How to copy a PHP script to the CRM host and run it
├── STRUCTURE.md              File-by-file index of scripts
├── CHANGELOG.md              Dated operational notes
├── SECURITY.md               What must never be committed
├── reference/
│   └── teams-and-roles.json  Excel-final teams, role IDs, CRM tables
├── phone-mask/               Last-4 hide + eye unlock (CRM JS/PHP)
├── org-manager/              Company Structure console (AWS API + Hostinger UI)
└── scripts/
    ├── fixes/                Live permission / membership writes
    ├── probes/               Read-only diagnosis
    ├── verify/               Acceptance checks
    ├── reports/              Lead counts / user lookups
    └── related-tl/           Earlier team-leader visibility work
```

---

## Current sales teams (Excel-final)

Source: `Team_Community_Allocation` (kept **out** of git). Snapshot in `reference/teams-and-roles.json`.

| Team | Department | Head | Agents (summary) |
|------|------------|------|------------------|
| Hassan | 69 | 2560 | Aila, NABIHA, Eman, Khazeema, Areeba, Sapna, Uzma |
| Hussnain | 70 | 2551 | Nimra, Mehnaz, Tehreem, Mehreen, Esha, Warda, Irum |
| Jaweria | 71 | 2297 | Fozia, Asma, Farhat, Shareen, Minahil |

Each of these users belongs to **exactly one** department. Extra intranet attachments that widen CRM scope (for example Management Board **G10** on agents, or Agent group **G11** on heads) are stripped by `scripts/fixes/strip_interfering_attachments.php`.

Users removed from the Excel final (Harm, Ruqia, Momina) sit on Sales department **60**, not on 69/70/71.

---

## Phone mask

**Who sees stars:** listed heads plus mask-only users (Amna Khan, Raoof Ahmad, Hina Sultan, Sreya Gopi, HR Usman, Furqan Khalid).

**Who sees full numbers:** agents and anyone **not** in `phone-mask/a7_tl_phone_mask.config.php` → `leaders`.

**Behaviour**

- Last four digits become `****` on Leads, Owner Data, and Telephony call details.
- An eye icon asks for the **server-side unlock password** (30 seconds).
- Hover `title` / `data-hint` / `tel:` href / copy payloads are sealed so the full number does not leak on mouse-over.

**Password is not in this repository.** On the CRM host set:

```bash
# example — use your real secret, never commit it
export A7_TL_PHONE_MASK_PASSWORD='…'
```

or keep the password only inside the live file `/home/bitrix/www/bitrix/php_interface/a7_tl_phone_mask.config.php` on the server.

Deploy pieces:

| File | Live path |
|------|-----------|
| `phone-mask/a7_tl_phone_mask.js` | `/bitrix/js/a7_tl_phone_mask.js` |
| `phone-mask/a7_tl_phone_mask.php` | `/bitrix/tools/a7_tl_phone_mask.php` |
| `phone-mask/a7_tl_phone_mask.config.php` | `/bitrix/php_interface/a7_tl_phone_mask.config.php` |
| inject | `php_interface/init.php` (`A7_TL_PHONE_MASK` OnProlog) |

---

## Org Manager (Company Structure & Access)

Admin-only Bitrix left-menu iframe. Operators can:

- Inspect the department tree, heads, and members
- Apply one team (name, head, members, role scopes)
- Deep-verify Agent = Self / Head = Department
- Repair empty `ATTR` rows (Bitrix UI writes `SETTINGS` JSON; `CCrmPerms` still reads `ATTR`)

See `org-manager/README.md` for deploy. Shared HMAC is created on the hosts (`openssl rand -hex 32`) and stored in `secrets/` on those machines — **never in git**.

---

## Running scripts on the CRM host

Always as user `bitrix` so Bitrix can read its own files:

```bash
scp scripts/verify/verify_item_scope_excel_teams.php crm-host:/tmp/
ssh crm-host "sudo -u bitrix php /tmp/verify_item_scope_excel_teams.php"
```

Safe order when something looks wrong:

1. **Probe** (`scripts/probes/`) — read-only  
2. **Fix** (`scripts/fixes/`) — writes `b_crm_role_perms` / `UF_DEPARTMENT`  
3. **Verify** — expect `ALL … PASSED`  
4. Ask affected users to **log out and log in** (Bitrix caches permission bags on the session)

Canonical live enforcers:

| Script | Effect |
|--------|--------|
| `scripts/fixes/enforce_head_agent_scope.php` | Role 54 = `D`, role 36 = `A` on CRM + Owner Data + Availability |
| `scripts/fixes/head_lead_readonly.php` | Heads: Lead READ=`D`, deny WRITE/ADD/DELETE/EXPORT/IMPORT/TRANSITION |
| `scripts/fixes/strip_extra_departments.php` | One department per Excel-team user |
| `scripts/fixes/strip_interfering_attachments.php` | Drop widening groups / extra role binds |

---

## Bitrix permission model (short)

| ATTR | Meaning |
|------|---------|
| `A` | Self / assigned to me |
| `D` | Self + department + subdepartments |
| `X` | All |

Rows must carry **both** `ATTR` and `SETTINGS` JSON. Empty `ATTR` with only `SETTINGS` looks correct in the new UI and still fails in `CCrmPerms` (the “head cannot open Contacts” class of bugs).

Tables that scripts touch:

- `b_crm_role`, `b_crm_role_relation`, `b_crm_role_perms`
- `b_uts_user.UF_DEPARTMENT`, `b_user_access`
- `b_crm_lead`, `b_crm_dynamic_items_1042`

---

## Security rules for this repo

**Never commit**

- Unlock / admin passwords  
- HMAC or session secrets  
- SSH private keys (`*_codex`, `id_rsa`, `*.pem`)  
- `.env` files  
- Webhook tokens or REST application secrets  
- Spreadsheets with personal contact lists  
- Playwright / browser dumps (`.playwright-mcp/`)

Use `.gitignore` (already configured). Copy `phone-mask/a7_tl_phone_mask.config.example.php` on the server; do not paste production passwords back into git.

Nginx samples use placeholders (`allow 127.0.0.1` only). Put the real console IP on the host, not in a pull request.

---

## After you clone

```bash
git clone https://github.com/Matloob11/CRM-Access-management.git
cd CRM-Access-management
```

You still need:

1. SSH access to the Bitrix host (key stored **outside** this repo)  
2. Permission to `sudo -u bitrix php`  
3. Production secrets already present on the servers  

This clone alone cannot log into CRM or change anything until you run a script on the host.

---

## Related documents

- [HOWTO.md](HOWTO.md) — copy/run runbook  
- [STRUCTURE.md](STRUCTURE.md) — script index  
- [SECURITY.md](SECURITY.md) — secret handling  
- [org-manager/README.md](org-manager/README.md) — console deploy  
- [CHANGELOG.md](CHANGELOG.md) — dated history  
