# Changelog

## 2026-09-03 — Package created

- Moved agent Access Denied fix/probe/verify scripts into this handoff folder.
- Documented heads (dept) vs agents (self) scope.
- Included copies of related TL visibility scripts for context.
- Live state at pack time: role 36 has Owner Data + LEAD/CONTACT/COMPANY/DEAL personal perms; role 54 TL department scope; verify script **ALL CHECKS PASSED**.

## 2026-09-05 — Org Manager: glass UI + full management + deep verify

- Console redesigned (liquid-glass), deployed to Hostinger.
- New engine scripts on AWS: `mutate.php` (rename/move/create/delete team, set/clear
  head, add/remove/move member, bind/unbind role, set permission, repair ATTR rows,
  C14 stage rows, group membership), `users.php` (portal user search).
- `verify.php` gained `deep` mode: bindings, Agent group, access codes, ATTR/SETTINGS
  consistency, kanban stage rows, sample-record read test; failed checks carry a
  `fix` action the console can run.
- `apply.php` now removes agents dropped from the wizard (`team.remove`,
  `team.remove_to`), clears a removed head, renames on the fly.
- Permission rows are written with both ATTR and SETTINGS (`a7_set_perm`).
- Deploy scripts: `--code-only` mode, placeholder-secret guard, rsync without
  Windows ownership/perms (fixes nginx 403 on `/app/`).
- Console static assets served with `Cache-Control: no-cache` (module cache bug).
- Live finding (not yet applied): role 54 has 79 permission rows with SETTINGS but
  empty ATTR (Contacts / Companies / Deals and more), so `CCrmPerms` grants leaders
  nothing there; role 36 has 4 such rows; role 54 has no Availability stage rows.
  Repair via Roles page → "Repair rows" / Deep verify → Fix.
