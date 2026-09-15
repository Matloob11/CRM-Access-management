/** API helpers for Org Manager console */
export async function getJson(url) {
  const r = await fetch(url, { credentials: "same-origin" });
  const j = await r.json().catch(() => ({}));
  return { ok: r.ok && j.ok !== false, status: r.status, data: j };
}

export async function postJson(url, body) {
  const r = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });
  const j = await r.json().catch(() => ({}));
  return { ok: r.ok && j.ok !== false, status: r.status, data: j };
}

export function authMe() {
  return getJson("/api/auth/me");
}

export function fetchTree() {
  return getJson("/api/org/tree");
}

export function fetchRoles(permsFor = [54, 36]) {
  const q = permsFor.join(",");
  return getJson(`/api/org/roles?perms_for=${encodeURIComponent(q)}`);
}

export function searchUsers(q, limit = 30) {
  return getJson(`/api/org/users?q=${encodeURIComponent(q || "")}&limit=${limit}`);
}

export function fetchUsers(ids, withRoles = false) {
  return getJson(`/api/org/users?ids=${encodeURIComponent(ids.join(","))}&limit=100${withRoles ? "&roles=1" : ""}`);
}

export function applyChange(payload) {
  return postJson("/api/org/apply", payload);
}

/** One targeted change. `action` plus its params; pass dry_run:true to preview. */
export function mutate(action, params = {}) {
  return postJson("/api/org/mutate", { action, ...params });
}

export function verifyTeam(payload) {
  return postJson("/api/org/verify", payload);
}
