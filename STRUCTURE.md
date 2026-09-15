# Structure — file index

## `phone-mask/`

| File | Purpose |
|------|---------|
| `a7_tl_phone_mask.js` | Client mask, hover/title leak seal, eye unlock |
| `a7_tl_phone_mask.php` | `config` / `unlock` JSON API (session + password) |
| `a7_tl_phone_mask.config.php` | Allow-list of masked user IDs (password from env only) |
| `a7_tl_phone_mask.config.example.php` | Copy template for the CRM host |
| `deploy_tl_phone_mask_update.php` | Install JS/tool/config on the host; preserves existing password |
| `deploy_add_amna_raoof_mask.php` | Copy a staged config onto the host |
| `verify_mask_password.php` | Confirm allow-list + password **configured** (does not print it) |

## `scripts/fixes/` — live writes to Bitrix

| File | Purpose |
|------|---------|
| `enforce_head_agent_scope.php` | Role 54 = department, role 36 = self, all CRM + Owner Data entities |
| `head_lead_readonly.php` | Heads: Lead read-only |
| `strip_extra_departments.php` | One department per Excel-team user |
| `strip_interfering_attachments.php` | Remove G10 on agents, G11 on heads, extra role-8 binds |
| `fix_agent_owner_data_perms.php` | Role 36 Owner Data / Availability personal perms |
| `fix_agent_lead_crm_perms.php` | Role 36 LEAD/CONTACT/COMPANY/DEAL personal perms |

Safe to re-run when they only upsert known rows.

## `scripts/probes/` — read-only diagnosis

| File | Purpose |
|------|---------|
| `probe_agent_owner_access.php` | Roles + Owner Data category read |
| `probe_agent_lead_access.php` | LEAD/CONTACT/DEAL for sample agents |
| `probe_agent_role36_lead.php` | Dump LEAD + 1042 rows for roles 6/8/36/43/54 |
| `probe_agent_role36_detail.php` | Role 36 entity summary |

## `scripts/verify/` — acceptance

| File | Purpose |
|------|---------|
| `verify_item_scope_excel_teams.php` | Item-level own vs teammate for the three teams |
| `verify_hussnain_leads.php` | Hussnain Lead samples |
| `verify_head_vs_agent_scope.php` | Older roster check — update arrays after team changes |
| `audit_*.php` | Extra department / group / role-bind audits |

## `scripts/reports/`

Lead counts and user lookups (Amna/Raoof, Hussnain team, name search). Read-only.

## `scripts/related-tl/`

Earlier team-leader department work. Prefer `scripts/fixes/` for current policy.

## `org-manager/`

Hostinger UI + AWS org API. See `org-manager/README.md`.

## `reference/`

| File | Purpose |
|------|---------|
| `teams-and-roles.json` | Teams, role IDs, entity IDs, table names |

Spreadsheets are **not** in git.
