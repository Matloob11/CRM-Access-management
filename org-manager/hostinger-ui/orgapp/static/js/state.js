/** Shared constants + store for the Org Manager console */

export const ENTITIES = [
  { code: "LEAD", label: "Leads", help: "Sales leads" },
  { code: "CONTACT", label: "Contacts", help: "People records" },
  { code: "COMPANY", label: "Companies", help: "Company records" },
  { code: "DEAL", label: "Deals", help: "Deal pipeline" },
  { code: "DYNAMIC_1042", label: "Owner Data", help: "All categories" },
  { code: "DYNAMIC_1042_C13", label: "Owner Data", help: "Category 13" },
  { code: "DYNAMIC_1042_C14", label: "Availability", help: "Category 14" },
];

export const SCOPES = [
  { v: "A", t: "Self", plain: "Only their own records" },
  { v: "D", t: "Department", plain: "Own records + whole team" },
  { v: "X", t: "All", plain: "Everyone in the company" },
];

export const WIZARD_STEPS = [
  { id: "identity", title: "Name" },
  { id: "head", title: "Leader" },
  { id: "members", title: "Agents" },
  { id: "security", title: "Access" },
  { id: "review", title: "Review" },
];

/** Department that holds the sales teams (see reference/teams-and-roles.json). */
export const SALES_PARENT_ID = 60;
export const HEAD_ROLE_ID = 54;
export const AGENT_ROLE_ID = 36;
export const AGENT_GROUP_ID = 11;

const store = {
  departments: [],
  users: {},
  me: null,
  loaded: false,
  loading: false,
  error: null,
  roles: null, // { roles, relations, perms } from /api/org/roles
  listeners: new Set(),
};

export function getState() {
  return store;
}

export function subscribe(fn) {
  store.listeners.add(fn);
  return () => store.listeners.delete(fn);
}

function emit() {
  store.listeners.forEach((fn) => fn(store));
}

export function setMe(label) {
  store.me = label;
  emit();
}

export function setTree(departments) {
  store.departments = departments || [];
  store.users = {};
  store.departments.forEach((d) => {
    if (d.headId) store.users[d.headId] = d.headName || `User ${d.headId}`;
    (d.members || []).forEach((m) => {
      store.users[m.id] = m.name;
    });
  });
  store.loaded = true;
  store.loading = false;
  store.error = null;
  emit();
}

export function setRoles(data) {
  store.roles = data || null;
  emit();
}

export function setLoading(v) {
  store.loading = v;
  emit();
}

export function setError(msg) {
  store.error = msg;
  store.loading = false;
  emit();
}

export function deptById(id) {
  const n = Number(id);
  return store.departments.find((d) => d.id === n) || null;
}

export function userName(id) {
  if (!id) return "—";
  return store.users[id] || `User ${id}`;
}

export function sortedUsers() {
  return Object.keys(store.users)
    .map((id) => ({ id: Number(id), name: store.users[id] }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

/** Sales teams: departments directly under the sales parent (falls back to all). */
export function salesTeams() {
  const list = store.departments.filter((d) => d.parentId === SALES_PARENT_ID);
  return list.length ? list : store.departments.slice();
}

export function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])
  );
}

export function scopeLabel(v) {
  const hit = SCOPES.find((s) => s.v === v);
  return hit ? hit.t : v ? String(v) : "—";
}

/** CSS class for a scope letter (tags, summary values). */
export function scopeClass(v) {
  return v === "A" ? "self" : v === "D" ? "dept" : v === "X" ? "all" : "";
}

export function defaultSecurity() {
  const out = { head: {}, agent: {} };
  ENTITIES.forEach((e) => {
    out.head[e.code] = "D";
    out.agent[e.code] = "A";
  });
  return out;
}

/** Two-letter initials for an avatar. */
export function initials(name) {
  const parts = String(name || "")
    .replace(/[()]/g, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** Deterministic avatar colour class from an id. */
export function avatarClass(id) {
  const n = Number(id) || 0;
  return `c${n % 6}`;
}

/* ── Last verify result per team (browser-local) ──────── */
const VERIFY_KEY = "a7org.verify.";

export function getVerify(deptId) {
  try {
    const raw = localStorage.getItem(VERIFY_KEY + deptId);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function setVerify(deptId, allPassed, checks, deep = false) {
  try {
    const failed = (checks || []).filter((c) => !c.pass).length;
    localStorage.setItem(VERIFY_KEY + deptId, JSON.stringify({ ts: Date.now(), allPassed: !!allPassed, failed, deep: !!deep }));
  } catch {
    /* storage unavailable: verify status simply is not remembered */
  }
}

/* ── Activity log (browser-local, last 60 entries) ────── */
const ACT_KEY = "a7org.activity";

export function getActivity() {
  try {
    return JSON.parse(localStorage.getItem(ACT_KEY) || "[]");
  } catch {
    return [];
  }
}

export function logActivity(text, ok = true, deptId = null) {
  try {
    const list = getActivity();
    list.unshift({ ts: Date.now(), text: String(text).slice(0, 200), ok: !!ok, deptId });
    localStorage.setItem(ACT_KEY, JSON.stringify(list.slice(0, 60)));
  } catch {
    /* storage unavailable */
  }
}

export function roleName(id) {
  const r = store.roles && (store.roles.roles || []).find((x) => x.id === Number(id));
  return r ? r.name : `Role ${id}`;
}

export function formatWhen(ts) {
  if (!ts) return "";
  const d = new Date(ts);
  const today = new Date();
  const sameDay = d.toDateString() === today.toDateString();
  const hm = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  if (sameDay) return `today ${hm}`;
  return `${d.toLocaleDateString([], { day: "numeric", month: "short" })} ${hm}`;
}
