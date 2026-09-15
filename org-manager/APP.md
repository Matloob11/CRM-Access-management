# Org Manager — Application Plan

## Problem
One dense form confused beginners. Live CRM still showed the old UI because
Hostinger was never redeployed after local CSS tweaks.

## Goal
A full admin **application** (not one form) so a beginner can:
1. Understand what the tool does
2. Find a team
3. Change head / members / visibility safely
4. Preview → Apply → Verify without breaking company-wide access

## Pages (hash routes)

| Route | Page | Purpose |
|-------|------|---------|
| `#/` | Teams | Stats strip, sales-team list (Sales / All / Attention tabs, search), row menu (rename, move, leader, add agent, delete), live role scopes, attention list |
| `#/team/:id` | Team hub | Change / remove leader, add / move / remove agents (per-person menu), rename, move, delete, quick + deep verify with Fix buttons, recent changes |
| `#/edit/:id` | Edit wizard | 5 steps: Name → Leader → Agents → Access → Review, with a live draft summary |
| `#/create` | Create wizard | Same steps for a new team |
| `#/verify/:id?` | Verify | Pick a team, run checks, recent results |
| `#/roles` | Roles | CRM roles 54 / 36: click-to-change read / write / add scopes, bind / unbind, repair ATTR rows, add kanban stage rows |
| `#/help` | Help | Three scopes, safe defaults, what Apply does |

## Design (2026-09)

Liquid-glass look: mesh-gradient background, translucent blurred panels
(`.glass`), icon-only rail, pill controls. Scope colours carry meaning everywhere:
Self = green, Department = blue, All = red. Single typeface (Inter Tight).

The "last verify" status shown per team is stored in the browser
(`localStorage`, key `a7org.verify.<deptId>`); the backend does not persist it.
The "recent changes" list is also browser-local (`a7org.activity`).

## Change flow

Every targeted change goes through `doMutation()`: a glass confirmation with a
**Preview** button (dry-run plan from the engine) → Apply → toast → tree and
roles re-read → page repainted. The teams view also polls the tree every 45 s
and repaints (with a toast) when the structure changed elsewhere in CRM.

## Beginner safety
- Preview (dry run) before Apply
- Confirm dialog with plain summary
- Default scopes: Head = Department, Agent = Self
- Wizard language: “Who leads?”, “Who works here?”, “What can they see?”
- Sticky progress steps; cannot Apply until Review step

## Stack (unchanged backend)
Browser → Hostinger Starlette UI → HMAC → AWS Bitrix PHP API  
APIs: `tree`, `roles`, `apply`, `verify`

## Deploy
After code lands on the laptop, sync `hostinger-ui/` to Hostinger and restart
`a7-org-manager-ui`. Without that step, Bitrix iframe keeps serving the old page.
