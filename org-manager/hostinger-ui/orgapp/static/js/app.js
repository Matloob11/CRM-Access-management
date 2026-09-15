import * as api from "./api.js";
import {
  ENTITIES,
  SCOPES,
  WIZARD_STEPS,
  HEAD_ROLE_ID,
  AGENT_ROLE_ID,
  AGENT_GROUP_ID,
  SALES_PARENT_ID,
  getState,
  setMe,
  setTree,
  setRoles,
  setLoading,
  setError,
  deptById,
  userName,
  sortedUsers,
  salesTeams,
  escapeHtml,
  scopeLabel,
  scopeClass,
  defaultSecurity,
  initials,
  avatarClass,
  getVerify,
  setVerify,
  formatWhen,
  getActivity,
  logActivity,
  roleName,
} from "./state.js";

const root = () => document.getElementById("view");
const VERIFY_ENTITIES = ["LEAD", "DYNAMIC_1042_C13", "DYNAMIC_1042_C14"];
const LIVE_INTERVAL_MS = 45000;

/* ── Icons ───────────────────────────────────────────── */
const I = {
  plus: `<svg class="ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>`,
  reload: `<svg class="ic" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M20 9A8 8 0 005.3 6.3M4 15a8 8 0 0014.7 2.7"/></svg>`,
  shield: `<svg class="ic" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/></svg>`,
  edit: `<svg class="ic" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
  check: `<svg class="ic" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>`,
  warn: `<svg class="ic" viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>`,
  dash: `<svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14"/></svg>`,
  user: `<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></svg>`,
  right: `<svg class="ic" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>`,
  back: `<svg class="ic" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>`,
  x: `<svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>`,
  dots: `<svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>`,
  trash: `<svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>`,
  move: `<svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>`,
  crown: `<svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M4 17l-1-10 5 4 4-7 4 7 5-4-1 10"/></svg>`,
  search: `<svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>`,
  wrench: `<svg class="ic" viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 005 5L21 9.9 14.1 3l-1.4 1.6M3 21l8-8"/></svg>`,
};

/* ── Router ──────────────────────────────────────────── */
function parseHash() {
  const raw = (location.hash || "#/").replace(/^#/, "") || "/";
  const parts = raw.split("/").filter(Boolean);
  if (!parts.length) return { name: "teams", params: {} };
  const name = parts[0];
  if (name === "team" && parts[1]) return { name: "team", params: { id: parts[1] } };
  if (name === "edit" && parts[1]) return { name: "edit", params: { id: parts[1] } };
  if (name === "create") return { name: "create", params: {} };
  if (name === "verify") return { name: "verify", params: { id: parts[1] || "" } };
  if (name === "roles") return { name: "roles", params: {} };
  if (name === "help") return { name: "help", params: {} };
  return { name: "teams", params: {} };
}

function go(path) {
  location.hash = path.startsWith("#") ? path : `#${path}`;
}

function setActiveNav(name) {
  const page = ["team", "edit", "create"].includes(name) ? "teams" : name;
  document.querySelectorAll(".nav-link").forEach((a) => {
    a.classList.toggle("active", a.getAttribute("data-page") === page);
  });
}

function setTop({ crumb = "", title = "", actions = "", search = false } = {}) {
  document.getElementById("topCrumb").innerHTML = crumb ? `${crumb} <span>/</span>` : "";
  document.getElementById("topTitle").textContent = title;
  document.getElementById("topActions").innerHTML = `${actions}<span class="live ${liveOk ? "" : "off"}" id="liveDot" title="Auto-refresh"><i></i>live</span>`;
  document.getElementById("searchBox").classList.toggle("hidden", !search);
}

/* ── Data ────────────────────────────────────────────── */
let treeSig = "";
let liveOk = true;

async function ensureTree(force = false) {
  const s = getState();
  if (s.loaded && !force) return true;
  setLoading(true);
  const res = await api.fetchTree();
  if (!res.ok) {
    setError(res.data.error || res.data.detail || "Could not load departments.");
    liveOk = false;
    return false;
  }
  liveOk = true;
  treeSig = JSON.stringify(res.data.departments || []);
  setTree(res.data.departments || []);
  return true;
}

async function ensureRoles(force = false) {
  const s = getState();
  if (s.roles && !force) return s.roles;
  const res = await api.fetchRoles([HEAD_ROLE_ID, AGENT_ROLE_ID]);
  if (!res.ok) return null;
  setRoles(res.data);
  return res.data;
}

/** Refresh everything from CRM and repaint the current page. */
async function refreshAll() {
  await Promise.all([ensureTree(true), ensureRoles(true)]);
  render();
}

/** Effective READ scope per entity for a role, from the roles snapshot. */
function roleScopes(roleId, perm = "READ") {
  const s = getState();
  const p = (s.roles && s.roles.perms && s.roles.perms[String(roleId)]) || {};
  const out = {};
  ENTITIES.forEach((e) => {
    out[e.code] = (p[e.code] && p[e.code][perm]) || "";
  });
  return out;
}

function roleMismatches(roleId) {
  const s = getState();
  return (s.roles && s.roles.mismatches && s.roles.mismatches[String(roleId)]) || 0;
}

/* Live polling: re-read the tree; repaint only if something changed. */
let liveTimer = null;
function startLive() {
  if (liveTimer) clearInterval(liveTimer);
  liveTimer = setInterval(async () => {
    if (document.hidden || document.querySelector(".overlay")) return;
    const res = await api.fetchTree();
    const dot = document.getElementById("liveDot");
    if (!res.ok) {
      liveOk = false;
      dot?.classList.add("off");
      return;
    }
    liveOk = true;
    dot?.classList.remove("off");
    const sig = JSON.stringify(res.data.departments || []);
    if (sig !== treeSig) {
      treeSig = sig;
      setTree(res.data.departments || []);
      const r = parseHash().name;
      if (r === "teams" || r === "team") {
        render();
        toast("Company structure changed in CRM. View refreshed.", "ok");
      }
    }
  }, LIVE_INTERVAL_MS);
}

/* ── UI primitives ───────────────────────────────────── */
function toast(text, kind = "ok", ms = 4200) {
  let host = document.querySelector(".toasts");
  if (!host) {
    host = document.createElement("div");
    host.className = "toasts";
    document.body.appendChild(host);
  }
  const el = document.createElement("div");
  el.className = `toast ${kind}`;
  el.innerHTML = `${kind === "bad" ? I.warn : I.check}<span>${escapeHtml(text)}</span>`;
  host.appendChild(el);
  setTimeout(() => el.remove(), ms);
}

/**
 * Glass modal. actions: [{label, cls, value, keep}] — resolves with the value of
 * the pressed action (null when dismissed). `onOpen(el)` wires the body.
 */
function modal({ title, body = "", actions = [], wide = false, onOpen = null }) {
  return new Promise((resolve) => {
    const ov = document.createElement("div");
    ov.className = "overlay";
    ov.innerHTML = `<div class="modal glass${wide ? " wide" : ""}" role="dialog" aria-modal="true">
      <div class="modal-head"><h3>${escapeHtml(title)}</h3><div class="sp"></div><button type="button" class="btn icon sm" data-close>${I.x}</button></div>
      <div class="modal-body">${body}</div>
      <div class="modal-foot"><div class="sp"></div>${actions.map((a, i) => `<button type="button" class="btn ${a.cls || ""}" data-act="${i}">${a.label}</button>`).join("")}</div>
    </div>`;
    const close = (v) => {
      ov.remove();
      document.removeEventListener("keydown", esc);
      resolve(v);
    };
    const esc = (e) => e.key === "Escape" && close(null);
    document.addEventListener("keydown", esc);
    ov.addEventListener("click", (e) => e.target === ov && close(null));
    ov.querySelector("[data-close]").addEventListener("click", () => close(null));
    ov.querySelectorAll("[data-act]").forEach((b) =>
      b.addEventListener("click", () => {
        const a = actions[Number(b.getAttribute("data-act"))];
        if (a.onClick) {
          const r = a.onClick(ov, close);
          if (r === false) return;
        }
        if (!a.keep) close(a.value !== undefined ? a.value : a.label);
      })
    );
    document.body.appendChild(ov);
    if (onOpen) onOpen(ov, close);
    const first = ov.querySelector("input,select,button.primary");
    if (first) first.focus();
  });
}

/** Simple yes/no confirmation. */
function confirmDialog(title, bodyHtml, { okText = "Apply", danger = false } = {}) {
  return modal({
    title,
    body: bodyHtml,
    actions: [
      { label: "Cancel", value: false },
      { label: okText, cls: danger ? "danger" : "primary", value: true },
    ],
  }).then((v) => v === true);
}

/** Ask for one text value. */
function promptDialog(title, label, value = "", okText = "Save") {
  return modal({
    title,
    body: `<div class="field"><label>${escapeHtml(label)}</label><input type="text" id="pv" value="${escapeHtml(value)}" /></div>`,
    actions: [
      { label: "Cancel", value: null },
      { label: okText, cls: "primary", keep: true, onClick: (ov, close) => close(ov.querySelector("#pv").value.trim()) },
    ],
    onOpen: (ov, close) => {
      const inp = ov.querySelector("#pv");
      inp.select();
      inp.addEventListener("keydown", (e) => e.key === "Enter" && close(inp.value.trim()));
    },
  });
}

/** Pick a department (select). Returns id or null. */
function pickDepartment(title, { exclude = [], allowNone = false, noneLabel = "No department", preselect = null } = {}) {
  const s = getState();
  const opts = s.departments
    .filter((d) => !exclude.includes(d.id))
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .map((d) => `<option value="${d.id}"${preselect === d.id ? " selected" : ""}>${escapeHtml(d.name)} (${d.id})</option>`)
    .join("");
  return modal({
    title,
    body: `<div class="field"><label>Department</label><select id="pd">${allowNone ? `<option value="">${escapeHtml(noneLabel)}</option>` : ""}${opts}</select></div>`,
    actions: [
      { label: "Cancel", value: undefined },
      { label: "Continue", cls: "primary", keep: true, onClick: (ov, close) => close(ov.querySelector("#pd").value === "" ? null : Number(ov.querySelector("#pd").value)) },
    ],
  }).then((v) => (v === undefined ? undefined : v));
}

/** Search the whole portal for a person. Resolves {id,name,departments,groups} or null. */
function pickUser(title, { exclude = [], hint = "" } = {}) {
  return modal({
    title,
    wide: true,
    body: `<div class="picker">
      <div class="search pill" style="width:100%;margin:0">${I.search}<input type="text" id="pq" placeholder="Type a name, login or user ID" autocomplete="off" /></div>
      ${hint ? `<div class="dim" style="font-size:12.5px">${hint}</div>` : ""}
      <div class="results" id="pr"><div class="empty">Start typing to search the portal.</div></div>
    </div>`,
    actions: [{ label: "Cancel", value: null }],
    onOpen: (ov, close) => {
      const inp = ov.querySelector("#pq");
      const res = ov.querySelector("#pr");
      let t = null;
      let seq = 0;
      const paint = (users) => {
        if (!users.length) {
          res.innerHTML = `<div class="empty">Nobody matches.</div>`;
          return;
        }
        res.innerHTML = users
          .map((u) => {
            const dn = (u.departments || []).map((id) => (deptById(id) ? deptById(id).name : `dept ${id}`)).join(", ");
            const ex = exclude.includes(u.id);
            return `<button type="button" class="u" data-id="${u.id}" ${ex ? "disabled" : ""}>${av(u.id, u.name)}<div><b>${escapeHtml(u.name)}</b><small>${u.id}${dn ? ` · ${escapeHtml(dn)}` : " · no department"}${u.active === false ? " · inactive" : ""}</small></div>${ex ? `<span class="tag sm badge">Already here</span>` : (u.groups || []).includes(AGENT_GROUP_ID) ? `<span class="tag sm self badge"><i></i>Agent</span>` : ""}</button>`;
          })
          .join("");
        res.querySelectorAll(".u:not([disabled])").forEach((b) =>
          b.addEventListener("click", () => {
            const u = users.find((x) => x.id === Number(b.getAttribute("data-id")));
            getState().users[u.id] = u.name;
            close(u);
          })
        );
      };
      const run = async () => {
        const q = inp.value.trim();
        if (q.length < 2) {
          res.innerHTML = `<div class="empty">Type at least 2 characters.</div>`;
          return;
        }
        const my = ++seq;
        res.innerHTML = `<div class="loading"><span class="spinner"></span> Searching…</div>`;
        const r = await api.searchUsers(q, 40);
        if (my !== seq) return;
        if (!r.ok) {
          res.innerHTML = `<div class="note bad">${I.warn}<span>${escapeHtml(r.data.error || "Search failed.")}</span></div>`;
          return;
        }
        paint(r.data.users || []);
      };
      inp.addEventListener("input", () => {
        clearTimeout(t);
        t = setTimeout(run, 260);
      });
      inp.focus();
    },
  });
}

/** Small context menu anchored to a button. items: [{label, icon, danger, onClick}] */
function menu(anchor, items) {
  document.querySelectorAll(".menu").forEach((m) => m.remove());
  const m = document.createElement("div");
  m.className = "menu";
  m.innerHTML = items.map((it, i) => (it === "-" ? `<hr />` : `<button type="button" class="${it.danger ? "danger" : ""}" data-i="${i}">${it.icon || ""}${escapeHtml(it.label)}</button>`)).join("");
  document.body.appendChild(m);
  const r = anchor.getBoundingClientRect();
  const w = m.offsetWidth;
  m.style.top = `${r.bottom + 6}px`;
  m.style.left = `${Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w))}px`;
  const off = () => {
    m.remove();
    document.removeEventListener("click", off, true);
  };
  setTimeout(() => document.addEventListener("click", off, true), 0);
  m.querySelectorAll("[data-i]").forEach((b) =>
    b.addEventListener("click", () => {
      off();
      items[Number(b.getAttribute("data-i"))].onClick();
    })
  );
}

/**
 * Run one targeted change: confirm (with optional preview), execute, log,
 * refresh. Returns the API result or null when cancelled/failed.
 */
async function doMutation(action, params, { title, body, danger = false, okText = "Apply", deptId = null, silent = false } = {}) {
  if (!silent) {
    const ok = await modal({
      title,
      body: `${body || ""}<div id="plan" class="log"></div>`,
      actions: [
        { label: "Cancel", value: false },
        {
          label: "Preview",
          keep: true,
          onClick: async (ov) => {
            const plan = ov.querySelector("#plan");
            plan.textContent = "Building preview…";
            const r = await api.mutate(action, { ...params, dry_run: true });
            plan.textContent = r.ok ? (r.data.plan || []).join("\n") || "No changes." : `Preview failed: ${r.data.error || "unknown error"}`;
            return false;
          },
        },
        { label: okText, cls: danger ? "danger" : "primary", value: true },
      ],
    });
    if (ok !== true) return null;
  }
  const r = await api.mutate(action, { ...params, dry_run: false });
  const label = title || action;
  if (!r.ok) {
    toast(`${label}: ${r.data.error || "failed"}`, "bad", 7000);
    logActivity(`${label} failed: ${r.data.error || "error"}`, false, deptId);
    return null;
  }
  toast(`${label}: done`, "ok");
  logActivity(label, true, deptId);
  await refreshAll();
  return r.data;
}

/* ── Small builders ──────────────────────────────────── */
function av(id, name, extra = "") {
  return `<div class="av ${avatarClass(id)} ${extra}">${escapeHtml(initials(name))}</div>`;
}

function tag(scope, short = false, extraClass = "") {
  if (!scope) return `<span class="tag ${extraClass}">Not set</span>`;
  const t = short && scope === "D" ? "Dept" : scopeLabel(scope);
  return `<span class="tag ${scopeClass(scope)} ${extraClass}"><i></i>${t}</span>`;
}

function verifyChip(deptId) {
  const v = getVerify(deptId);
  if (!v) return `<span class="chk">${I.dash}Not yet</span>`;
  if (v.allPassed) return `<span class="chk ok">${I.check}Passed${v.deep ? " (deep)" : ""} · ${formatWhen(v.ts)}</span>`;
  return `<span class="chk bad">${I.warn}Failed · ${v.failed} check${v.failed === 1 ? "" : "s"}</span>`;
}

function memberStack(d) {
  const members = (d.members || []).filter((m) => m.id !== d.headId);
  if (!members.length) return `<span class="dim">No agents</span>`;
  const shown = members.slice(0, 4);
  const more = members.length - shown.length;
  return `<div class="mem">${shown.map((m) => av(m.id, m.name)).join("")}${more > 0 ? `<span class="more">+${more}</span>` : ""}</div>`;
}

function note(el, kind, text) {
  if (!el) return;
  el.className = `note ${kind}`;
  el.innerHTML = text ? `${kind === "ok" ? I.check : kind === "bad" || kind === "warn" ? I.warn : ""}<span>${text}</span>` : "";
}

function userOptions(selected, exclude = []) {
  let html = `<option value="">Choose a person</option>`;
  sortedUsers().forEach((u) => {
    if (exclude.includes(u.id)) return;
    html += `<option value="${u.id}"${String(u.id) === String(selected) ? " selected" : ""}>${escapeHtml(u.name)} (${u.id})</option>`;
  });
  return html;
}

function rulesHtml(headScopes, agentScopes) {
  return `<div class="rules">
    <div class="r h"><span></span><span>Leader</span><span>Agent</span></div>
    ${ENTITIES.filter((e) => e.code !== "DYNAMIC_1042").map((e) => `<div class="r"><span>${escapeHtml(e.label)}</span>${tag(headScopes[e.code], true)}${tag(agentScopes[e.code], true)}</div>`).join("")}
  </div>`;
}

/* ── Teams overview ──────────────────────────────────── */
let teamsFilter = { tab: "sales", q: "" };

function attentionItems() {
  const s = getState();
  const items = [];
  const agentScopes = roleScopes(AGENT_ROLE_ID);
  const headScopes = roleScopes(HEAD_ROLE_ID);
  const allEntities = ENTITIES.filter((e) => agentScopes[e.code] === "X").map((e) => e.label);
  if (allEntities.length) {
    items.push({ kind: "bad", icon: I.warn, title: "Agents can see the whole company", sub: `Agent role is All for ${[...new Set(allEntities)].join(", ")}`, href: "#/roles" });
  }
  const mm = roleMismatches(HEAD_ROLE_ID) + roleMismatches(AGENT_ROLE_ID);
  if (mm) {
    items.push({ kind: "bad", icon: I.wrench, title: "Role rows need repair", sub: `${mm} permission rows have no legacy ATTR. Leaders may lose access.`, href: "#/roles" });
  } else {
    const missing = ENTITIES.filter((e) => e.code !== "DYNAMIC_1042" && !headScopes[e.code]).map((e) => e.label);
    if (missing.length && s.roles) {
      items.push({ kind: "w", icon: I.warn, title: "Leader role has gaps", sub: `No READ scope for ${[...new Set(missing)].join(", ")}`, href: "#/roles" });
    }
  }
  salesTeams().forEach((d) => {
    const v = getVerify(d.id);
    if (v && !v.allPassed) {
      items.push({ kind: "bad", icon: I.warn, title: d.name, sub: "Last verify failed", href: `#/verify/${d.id}` });
    } else if (!d.headId && (d.members || []).length) {
      items.push({ kind: "i", icon: I.user, title: d.name, sub: "No leader", href: `#/team/${d.id}` });
    }
  });
  return items;
}

function teamRow(d) {
  const headScopes = roleScopes(HEAD_ROLE_ID);
  const agentScopes = roleScopes(AGENT_ROLE_ID);
  const leaderScope = d.headId ? headScopes.LEAD || "" : "";
  const agentScope = agentScopes.LEAD || "";
  const hot = agentScope === "X";
  const leaderLine = d.headId ? escapeHtml(d.headName || userName(d.headId)) : "No leader";
  return `<div class="row${hot ? " hot" : ""}" role="button" tabindex="0" data-id="${d.id}">
    <div class="tm">${d.headId ? av(d.headId, d.headName || userName(d.headId)) : `<div class="av none">?</div>`}
      <div><b>${escapeHtml(d.name)}</b><small>${leaderLine} · ${d.id}</small></div></div>
    <div>${memberStack(d)}</div>
    <div>${d.headId ? tag(leaderScope) : `<span class="tag">Not set</span>`}</div>
    <div>${tag(agentScope)}</div>
    <div>${verifyChip(d.id)}</div>
    <div class="act">
      <button type="button" class="btn icon sm" data-verify="${d.id}" title="Verify">${I.shield}</button>
      <button type="button" class="btn icon sm" data-edit="${d.id}" title="Edit wizard">${I.edit}</button>
      <button type="button" class="btn icon sm" data-menu="${d.id}" title="More">${I.dots}</button>
    </div>
  </div>`;
}

function teamMenu(anchor, d) {
  menu(anchor, [
    { label: "Open", icon: I.right, onClick: () => go(`#/team/${d.id}`) },
    { label: "Rename", icon: I.edit, onClick: () => renameTeam(d) },
    { label: "Move under…", icon: I.move, onClick: () => moveTeam(d) },
    { label: d.headId ? "Change leader" : "Choose leader", icon: I.crown, onClick: () => changeLeader(d) },
    { label: "Add agent", icon: I.plus, onClick: () => addAgent(d) },
    "-",
    { label: "Delete team", icon: I.trash, danger: true, onClick: () => deleteTeam(d) },
  ]);
}

function renderTeams() {
  const s = getState();
  setTop({
    title: "Teams",
    search: true,
    actions: `<button type="button" class="btn icon" id="btnReload" title="Reload">${I.reload}</button>
              <button type="button" class="btn primary" id="btnCreate">${I.plus}New team</button>`,
  });

  const withHead = s.departments.filter((d) => d.headId).length;
  const withMembers = s.departments.filter((d) => (d.members || []).length).length;
  const sales = salesTeams();
  const passed = sales.filter((d) => {
    const v = getVerify(d.id);
    return v && v.allPassed;
  }).length;
  const attn = attentionItems();

  root().innerHTML = `
    <div class="stats">
      <div class="stat glass"><div class="v">${s.departments.length}</div><div class="l">Departments</div></div>
      <div class="stat glass"><div class="v">${withHead}</div><div class="l">With leader</div></div>
      <div class="stat glass"><div class="v">${withMembers}</div><div class="l">With members</div></div>
      <div class="stat glass"><div class="v ${passed === sales.length ? "ok" : "warn"}">${passed} / ${sales.length}</div><div class="l">Sales teams verified</div></div>
    </div>
    <div class="cols">
      <section class="panel glass">
        <div class="panel-head">
          <h2 id="listTitle">Sales teams</h2>
          <div class="sp"></div>
          <div class="seg" id="tabs">
            <button type="button" data-tab="sales">Sales</button>
            <button type="button" data-tab="all">All</button>
            <button type="button" data-tab="attn">Attention${attn.length ? ` <b>${attn.length}</b>` : ""}</button>
          </div>
        </div>
        <div class="panel-body tight"><div class="rows" id="rows"></div></div>
      </section>
      <aside class="side">
        <section class="panel glass">
          <div class="panel-head"><h2>Access rules</h2><div class="sp"></div><a href="#/roles" class="sub">edit</a></div>
          <div class="panel-body tight" style="padding-left:14px;padding-right:14px">${rulesHtml(roleScopes(HEAD_ROLE_ID), roleScopes(AGENT_ROLE_ID))}</div>
        </section>
        <section class="panel glass">
          <div class="panel-head"><h2>Attention</h2><div class="sp"></div>${attn.length ? `<span class="tag sm warn">${attn.length}</span>` : `<span class="tag sm self"><i></i>Clear</span>`}</div>
          <div class="panel-body tight" style="padding-left:14px;padding-right:14px">
            <div class="attn">
              ${attn.length ? attn.map((a) => `<a class="it" href="${a.href}"><div class="ico ${a.kind}">${a.icon}</div><div><b>${escapeHtml(a.title)}</b><small>${escapeHtml(a.sub)}</small></div><span class="go">${I.right}</span></a>`).join("") : `<div class="empty">Nothing needs attention.</div>`}
            </div>
          </div>
        </section>
      </aside>
    </div>`;

  const rowsEl = document.getElementById("rows");
  const paint = () => {
    const q = teamsFilter.q.trim().toLowerCase();
    let list;
    if (teamsFilter.tab === "sales") list = salesTeams();
    else if (teamsFilter.tab === "attn") {
      const ids = new Set(attn.map((a) => (a.href.match(/\/(\d+)$/) || [])[1]).filter(Boolean).map(Number));
      list = s.departments.filter((d) => ids.has(d.id));
    } else list = s.departments.slice();
    if (q) list = s.departments.filter((d) => `${d.name} ${d.headName || ""} ${d.id}`.toLowerCase().includes(q));
    list = list.slice().sort((a, b) => a.name.localeCompare(b.name));
    document.getElementById("listTitle").textContent = q ? "Search results" : teamsFilter.tab === "sales" ? "Sales teams" : teamsFilter.tab === "attn" ? "Needs attention" : "All departments";
    document.querySelectorAll("#tabs button").forEach((b) => b.classList.toggle("on", b.getAttribute("data-tab") === teamsFilter.tab && !q));
    if (!list.length) {
      rowsEl.innerHTML = `<div class="empty">${q ? "No team matches that search." : "No teams here."}</div>`;
      return;
    }
    rowsEl.innerHTML = `<div class="hdr"><span>Team</span><span>Agents</span><span>Leader sees</span><span>Agents see</span><span>Verify</span><span></span></div>` + list.map(teamRow).join("");
    rowsEl.querySelectorAll(".row").forEach((r) => {
      const id = r.getAttribute("data-id");
      r.addEventListener("click", () => go(`#/team/${id}`));
      r.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go(`#/team/${id}`);
        }
      });
    });
    const stop = (fn) => (e) => {
      e.stopPropagation();
      fn(e);
    };
    rowsEl.querySelectorAll("[data-verify]").forEach((b) => b.addEventListener("click", stop(() => go(`#/verify/${b.getAttribute("data-verify")}`))));
    rowsEl.querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", stop(() => go(`#/edit/${b.getAttribute("data-edit")}`))));
    rowsEl.querySelectorAll("[data-menu]").forEach((b) => b.addEventListener("click", stop(() => teamMenu(b, deptById(b.getAttribute("data-menu"))))));
  };
  paint();

  document.querySelectorAll("#tabs button").forEach((b) =>
    b.addEventListener("click", () => {
      teamsFilter.tab = b.getAttribute("data-tab");
      teamsFilter.q = "";
      document.getElementById("q").value = "";
      paint();
    })
  );
  const q = document.getElementById("q");
  q.value = teamsFilter.q;
  q.oninput = () => {
    teamsFilter.q = q.value;
    paint();
  };
  document.getElementById("btnCreate").addEventListener("click", () => go("#/create"));
  document.getElementById("btnReload").addEventListener("click", async () => {
    root().innerHTML = `<div class="loading"><span class="spinner"></span> Reloading…</div>`;
    await refreshAll();
  });
}

/* ── Team actions (shared by list menu + detail page) ── */
async function renameTeam(d) {
  const name = await promptDialog("Rename team", "Team name", d.name);
  if (!name || name === d.name) return;
  await doMutation("rename_dept", { dept_id: d.id, name }, { title: `Rename to "${name}"`, body: `<p><b>${escapeHtml(d.name)}</b> becomes <b>${escapeHtml(name)}</b>. People and access do not change.</p>`, deptId: d.id, silent: true });
}

async function moveTeam(d) {
  const parent = await pickDepartment(`Move "${d.name}" under…`, { exclude: [d.id], preselect: d.parentId });
  if (parent === undefined || parent === null || parent === d.parentId) return;
  const p = deptById(parent);
  await doMutation("move_dept", { dept_id: d.id, parent_id: parent }, { title: `Move under ${p ? p.name : parent}`, body: `<p>The team keeps its leader, agents and access. Access codes are rebuilt for its members.</p>`, deptId: d.id });
}

async function deleteTeam(d) {
  const members = d.members || [];
  const kids = getState().departments.filter((x) => x.parentId === d.id);
  if (kids.length) {
    await modal({ title: "Cannot delete yet", body: `<p><b>${escapeHtml(d.name)}</b> has ${kids.length} sub-department${kids.length === 1 ? "" : "s"}: ${kids.map((k) => escapeHtml(k.name)).join(", ")}. Move or delete them first.</p>`, actions: [{ label: "OK", cls: "primary" }] });
    return;
  }
  let moveTo = d.parentId;
  if (members.length) {
    moveTo = await pickDepartment(`Where do the ${members.length} people go?`, { exclude: [d.id], preselect: d.parentId, allowNone: true });
    if (moveTo === undefined) return;
  }
  await doMutation(
    "delete_dept",
    { dept_id: d.id, move_to: moveTo },
    {
      title: `Delete "${d.name}"`,
      danger: true,
      okText: "Delete team",
      body: `<p>This removes the department from the company structure.${members.length ? ` ${members.length} people move to <b>${moveTo ? escapeHtml(deptById(moveTo)?.name || moveTo) : "no department"}</b>.` : ""}${d.headId ? ` The leader loses the Team Leader role binding.` : ""}</p>`,
      deptId: d.id,
    }
  );
  if (parseHash().name === "team") go("#/");
}

async function changeLeader(d) {
  const u = await pickUser(d.headId ? `Change leader of ${d.name}` : `Choose a leader for ${d.name}`, { exclude: d.headId ? [d.headId] : [], hint: "The leader joins this team, gets the Team Leader role and sees the whole team." });
  if (!u) return;
  const wasAgent = (u.groups || []).includes(AGENT_GROUP_ID);
  await doMutation(
    "set_head",
    { dept_id: d.id, head_id: u.id, head_role_id: HEAD_ROLE_ID, agent_group_id: AGENT_GROUP_ID, drop_agent_group: false },
    {
      title: `Make ${u.name} the leader`,
      body: `<p><b>${escapeHtml(u.name)}</b> becomes leader of <b>${escapeHtml(d.name)}</b>.${d.headId ? ` <b>${escapeHtml(userName(d.headId))}</b> loses the Team Leader binding but stays in the team.` : ""}${wasAgent ? ` They keep the Agent group; the Team Leader role gives the wider scope.` : ""}</p>`,
      deptId: d.id,
    }
  );
}

async function removeLeader(d) {
  if (!d.headId) return;
  await doMutation(
    "clear_head",
    { dept_id: d.id, head_role_id: HEAD_ROLE_ID },
    { title: `Remove ${userName(d.headId)} as leader`, danger: true, okText: "Remove leader", body: `<p>The Team Leader role binding is removed. <b>${escapeHtml(userName(d.headId))}</b> stays in the team as a member and will only see their own records.</p>`, deptId: d.id }
  );
}

async function addAgent(d) {
  const u = await pickUser(`Add an agent to ${d.name}`, { exclude: (d.members || []).map((m) => m.id), hint: "The person moves into this team and joins the Agent group (sees only their own records)." });
  if (!u) return;
  const from = (u.departments || []).filter((x) => x !== d.id).map((x) => deptById(x)?.name || x);
  await doMutation("add_member", { dept_id: d.id, user_id: u.id, agent_group_id: AGENT_GROUP_ID }, { title: `Add ${u.name}`, body: `<p><b>${escapeHtml(u.name)}</b> joins <b>${escapeHtml(d.name)}</b>${from.length ? ` and leaves ${escapeHtml(from.join(", "))}` : ""}.</p>`, deptId: d.id });
}

async function removeAgent(d, m) {
  const to = await pickDepartment(`Where does ${m.name} go?`, { exclude: [d.id], preselect: d.parentId, allowNone: true, noneLabel: "No department (leave unassigned)" });
  if (to === undefined) return;
  await doMutation(
    "remove_member",
    { dept_id: d.id, user_id: m.id, move_to: to, agent_group_id: AGENT_GROUP_ID, drop_agent_group: false },
    { title: `Remove ${m.name} from ${d.name}`, danger: true, okText: "Remove", body: `<p><b>${escapeHtml(m.name)}</b> leaves the team and moves to <b>${to ? escapeHtml(deptById(to)?.name || to) : "no department"}</b>. They keep the Agent group.</p>`, deptId: d.id }
  );
}

async function moveAgent(d, m) {
  const to = await pickDepartment(`Move ${m.name} to…`, { exclude: [d.id] });
  if (to === undefined || to === null) return;
  const target = deptById(to);
  await doMutation("add_member", { dept_id: to, user_id: m.id, agent_group_id: AGENT_GROUP_ID }, { title: `Move ${m.name} to ${target ? target.name : to}`, body: `<p><b>${escapeHtml(m.name)}</b> leaves <b>${escapeHtml(d.name)}</b> and joins <b>${escapeHtml(target ? target.name : String(to))}</b> as an agent.</p>`, deptId: d.id });
}

/* ── Team detail (management hub) ───────────────────── */
function renderTeam(id) {
  const d = deptById(id);
  if (!d) {
    setTop({ crumb: `<a href="#/">Teams</a>`, title: "Team not found" });
    root().innerHTML = `<div class="note bad">${I.warn}<span>That department is not in the tree. <a href="#/">Back to teams</a></span></div>`;
    return;
  }
  setTop({
    crumb: `<a href="#/">Teams</a>`,
    title: d.name,
    actions: `<button type="button" class="btn" id="btnVerifyQuick">${I.shield}Verify</button>
              <button type="button" class="btn" id="btnVerifyDeep">${I.shield}Deep verify</button>
              <button type="button" class="btn primary" id="btnEdit">${I.edit}Wizard</button>
              <button type="button" class="btn icon" id="btnMore" title="More">${I.dots}</button>`,
  });
  const members = (d.members || []).filter((m) => m.id !== d.headId);
  const parent = d.parentId ? deptById(d.parentId) : null;
  const kids = getState().departments.filter((x) => x.parentId === d.id);
  const activity = getActivity().filter((a) => a.deptId === d.id || a.deptId === null).slice(0, 8);

  root().innerHTML = `
    <section class="hero glass">
      ${d.headId ? av(d.headId, d.headName || userName(d.headId), "lg") : `<div class="av none lg">?</div>`}
      <div>
        <div class="title"><h2>${escapeHtml(d.name)}</h2><button type="button" class="btn icon sm" id="btnRename" title="Rename">${I.edit}</button></div>
        <div class="meta">${d.headId ? `Leader ${escapeHtml(d.headName || userName(d.headId))} · ${d.headId}` : "No leader"} · department ${d.id}${parent ? ` · under <a href="#/team/${parent.id}">${escapeHtml(parent.name)}</a>` : ""}${kids.length ? ` · ${kids.length} sub-team${kids.length === 1 ? "" : "s"}` : ""}</div>
      </div>
      <div class="sp"></div>
      <div>${verifyChip(d.id)}</div>
    </section>
    <div class="cols">
      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <section class="panel glass">
          <div class="panel-head"><h2>Leader</h2><div class="sp"></div>
            ${d.headId ? `<button type="button" class="btn sm" id="btnChangeLeader">${I.crown}Change</button><button type="button" class="btn sm danger" id="btnRemoveLeader">${I.x}Remove</button>` : `<button type="button" class="btn sm primary" id="btnChangeLeader">${I.crown}Choose leader</button>`}
          </div>
          <div class="panel-body">
            ${d.headId
              ? `<div class="leader-card">${av(d.headId, d.headName || userName(d.headId))}<div><b>${escapeHtml(d.headName || userName(d.headId))}</b><small>${d.headId} · sees the whole team (Department scope)</small></div><div class="sp"></div>${tag(roleScopes(HEAD_ROLE_ID).LEAD)}</div>`
              : `<div class="leader-card empty">${I.user}<span>No leader. Nobody sees this team's records as a whole.</span></div>`}
          </div>
        </section>
        <section class="panel glass">
          <div class="panel-head"><h2>Agents</h2><span class="sub">${members.length}</span><div class="sp"></div><button type="button" class="btn sm" id="btnAddAgent">${I.plus}Add agent</button></div>
          <div class="panel-body">
            ${members.length
              ? `<div class="people">${members.map((m) => `<div class="person${m.active === false ? " off" : ""}">${av(m.id, m.name)}<div class="info"><b>${escapeHtml(m.name)}</b><small>${m.id}${m.active === false ? " · inactive" : ""}</small></div><button type="button" class="more" data-member="${m.id}" title="Actions">${I.dots}</button></div>`).join("")}</div>`
              : `<div class="empty">No agents in this team yet.</div>`}
          </div>
        </section>
        <section class="panel glass" id="verifyPanel" style="display:none">
          <div class="panel-head"><h2>Verification</h2><div class="sp"></div><span class="sub" id="verifySub"></span></div>
          <div class="panel-body"><div id="banner" class="note"></div><div id="checks"></div></div>
        </section>
      </div>
      <aside class="side">
        <section class="panel glass">
          <div class="panel-head"><h2>Who sees what</h2><div class="sp"></div><a href="#/roles" class="sub">edit</a></div>
          <div class="panel-body tight" style="padding-left:14px;padding-right:14px">${rulesHtml(roleScopes(HEAD_ROLE_ID), roleScopes(AGENT_ROLE_ID))}</div>
        </section>
        ${kids.length ? `<section class="panel glass"><div class="panel-head"><h2>Sub-teams</h2></div><div class="panel-body tight" style="padding-left:14px;padding-right:14px"><div class="attn">${kids.map((k) => `<a class="it" href="#/team/${k.id}"><div class="ico i">${I.user}</div><div><b>${escapeHtml(k.name)}</b><small>${k.headName ? escapeHtml(k.headName) : "No leader"} · ${(k.members || []).length} people</small></div><span class="go">${I.right}</span></a>`).join("")}</div></div></section>` : ""}
        <section class="panel glass">
          <div class="panel-head"><h2>Recent changes</h2></div>
          <div class="panel-body tight" style="padding-left:14px;padding-right:14px">
            <div class="activity">${activity.length ? activity.map((a) => `<div class="it ${a.ok ? "" : "bad"}"><span class="t">${formatWhen(a.ts)}</span><span>${escapeHtml(a.text)}</span></div>`).join("") : `<div class="empty">No changes made from this console yet.</div>`}</div>
          </div>
        </section>
      </aside>
    </div>`;

  document.getElementById("btnEdit").addEventListener("click", () => go(`#/edit/${d.id}`));
  document.getElementById("btnRename").addEventListener("click", () => renameTeam(d));
  document.getElementById("btnChangeLeader").addEventListener("click", () => changeLeader(d));
  document.getElementById("btnRemoveLeader")?.addEventListener("click", () => removeLeader(d));
  document.getElementById("btnAddAgent").addEventListener("click", () => addAgent(d));
  document.getElementById("btnMore").addEventListener("click", (e) =>
    menu(e.currentTarget, [
      { label: "Rename", icon: I.edit, onClick: () => renameTeam(d) },
      { label: "Move under…", icon: I.move, onClick: () => moveTeam(d) },
      { label: "Open in wizard", icon: I.right, onClick: () => go(`#/edit/${d.id}`) },
      "-",
      { label: "Delete team", icon: I.trash, danger: true, onClick: () => deleteTeam(d) },
    ])
  );
  document.querySelectorAll("[data-member]").forEach((b) =>
    b.addEventListener("click", (e) => {
      const m = members.find((x) => x.id === Number(b.getAttribute("data-member")));
      menu(e.currentTarget, [
        { label: "Make leader", icon: I.crown, onClick: () => doMutation("set_head", { dept_id: d.id, head_id: m.id, head_role_id: HEAD_ROLE_ID, agent_group_id: AGENT_GROUP_ID }, { title: `Make ${m.name} the leader`, body: `<p><b>${escapeHtml(m.name)}</b> becomes leader of <b>${escapeHtml(d.name)}</b>${d.headId ? `; <b>${escapeHtml(userName(d.headId))}</b> loses the Team Leader binding` : ""}.</p>`, deptId: d.id }) },
        { label: "Move to another team…", icon: I.move, onClick: () => moveAgent(d, m) },
        { label: "Remove from Agent group", icon: I.x, onClick: () => doMutation("set_group", { user_id: m.id, group_id: AGENT_GROUP_ID, member: false }, { title: `Remove ${m.name} from Agent group`, danger: true, body: `<p>They stay in the team but lose the Agent CRM role. Use this only when the person should not work in CRM.</p>`, deptId: d.id }) },
        "-",
        { label: "Remove from team", icon: I.trash, danger: true, onClick: () => removeAgent(d, m) },
      ]);
    })
  );

  const runTeamVerify = async (deep) => {
    const panel = document.getElementById("verifyPanel");
    panel.style.display = "";
    document.getElementById("verifySub").textContent = deep ? "deep" : "quick";
    const btns = ["btnVerifyQuick", "btnVerifyDeep"].map((x) => document.getElementById(x));
    btns.forEach((b) => (b.disabled = true));
    const draft = draftFromDept(d);
    draft.checks = undefined;
    await runVerify(draft, document.getElementById("banner"), document.getElementById("checks"), deep);
    btns.forEach((b) => (b.disabled = false));
    panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
  };
  document.getElementById("btnVerifyQuick").addEventListener("click", () => runTeamVerify(false));
  document.getElementById("btnVerifyDeep").addEventListener("click", () => runTeamVerify(true));
}

/* ── Wizard ──────────────────────────────────────────── */
function blankDraft(create) {
  return {
    create: !!create,
    step: 0,
    deptId: null,
    parentId: null,
    name: "",
    headId: null,
    members: [],
    original: null,
    headRole: HEAD_ROLE_ID,
    agentRole: AGENT_ROLE_ID,
    agentGroup: AGENT_GROUP_ID,
    security: defaultSecurity(),
    log: "",
    checks: null,
    banner: null,
  };
}

function draftFromDept(d) {
  const draft = blankDraft(false);
  draft.deptId = d.id;
  draft.parentId = d.parentId;
  draft.name = d.name;
  draft.headId = d.headId || null;
  draft.members = (d.members || []).map((m) => m.id).filter((id) => id !== draft.headId);
  draft.original = { headId: draft.headId, members: draft.members.slice() };
  const hs = roleScopes(HEAD_ROLE_ID);
  const as = roleScopes(AGENT_ROLE_ID);
  ENTITIES.forEach((e) => {
    if (hs[e.code]) draft.security.head[e.code] = hs[e.code];
    if (as[e.code]) draft.security.agent[e.code] = as[e.code];
  });
  return draft;
}

function removedMembers(draft) {
  if (!draft.original) return [];
  return draft.original.members.filter((m) => !draft.members.includes(m) && m !== draft.headId);
}

function collectPayload(draft, dryRun) {
  const team = {
    create: draft.create,
    name: draft.name.trim(),
    head_id: draft.headId ? Number(draft.headId) : null,
    members: draft.members.slice(),
    remove: removedMembers(draft),
    remove_to: draft.parentId ? Number(draft.parentId) : null,
    head_role_id: draft.headRole,
    agent_role_id: draft.agentRole,
    agent_group_id: draft.agentGroup,
  };
  if (draft.create) team.parent_id = Number(draft.parentId);
  else team.dept_id = Number(draft.deptId);

  const security = {};
  security[draft.headRole] = {};
  security[draft.agentRole] = {};
  ENTITIES.forEach((e) => {
    const h = draft.security.head[e.code];
    const a = draft.security.agent[e.code];
    if (h) security[draft.headRole][e.code] = h;
    if (a) security[draft.agentRole][e.code] = a;
  });
  return { dry_run: !!dryRun, team, security };
}

function draftSummary(draft) {
  const added = draft.original ? draft.members.filter((m) => !draft.original.members.includes(m)).length : draft.members.length;
  const removed = removedMembers(draft).length;
  const leaderChanged = draft.original && draft.original.headId !== draft.headId;
  const agentAll = ENTITIES.some((e) => draft.security.agent[e.code] === "X");
  const uniform = (map) => {
    const vals = [...new Set(ENTITIES.map((e) => map[e.code] || ""))];
    return vals.length === 1 ? vals[0] : "mixed";
  };
  const headMain = uniform(draft.security.head);
  const agentMain = uniform(draft.security.agent);
  let agentsLine = String(draft.members.length);
  const delta = [];
  if (added) delta.push(`+${added}`);
  if (removed) delta.push(`−${removed}`);
  if (delta.length) agentsLine += ` · ${delta.join(" ")}`;
  return `
    <h3>Draft</h3>
    <div class="kv"><span>Team</span><b>${escapeHtml(draft.name || "—")}</b></div>
    <div class="kv"><span>Leader</span><b>${draft.headId ? escapeHtml(userName(draft.headId)) : draft.original && draft.original.headId ? "removed" : "—"}${leaderChanged && draft.headId ? ` <span class="dim">changed</span>` : ""}</b></div>
    <div class="kv"><span>Agents</span><b class="${added || removed ? "self" : ""}">${agentsLine}</b></div>
    <div class="kv"><span>Leader scope</span><b class="${scopeClass(headMain)}">${headMain === "mixed" ? "Mixed" : scopeLabel(headMain)}</b></div>
    <div class="kv"><span>Agent scope</span><b class="${agentAll ? "all" : scopeClass(agentMain)}">${agentMain === "mixed" ? "Mixed" : scopeLabel(agentMain)}</b></div>
    ${removed ? `<div class="note info">${I.move}<span>${removed} removed agent${removed === 1 ? "" : "s"} will move to ${draft.parentId ? escapeHtml(deptById(draft.parentId)?.name || "the parent department") : "no department"}.</span></div>` : ""}
    <div class="note ${agentAll ? "bad" : "warn"}" style="margin-top:auto">${I.warn}<span>${agentAll ? "Agents are set to All. They will see every record in the company." : "Never give agents All."}</span></div>`;
}

function stepsHtml(draft) {
  return WIZARD_STEPS.map((st, i) => {
    const cls = i === draft.step ? "on" : i < draft.step ? "done" : "";
    return `${i ? `<span class="ln"></span>` : ""}<button type="button" class="s ${cls}" data-step="${i}"><span class="n">${i < draft.step ? "✓" : i + 1}</span>${escapeHtml(st.title)}</button>`;
  }).join("");
}

function switchHtml(role, code, value) {
  return `<span class="sw" data-role="${role}" data-entity="${code}">${SCOPES.map((s) => `<button type="button" class="${s.v === value ? `on ${s.v}` : ""}" data-scope="${s.v}" title="${escapeHtml(s.plain)}">${s.t}</button>`).join("")}</span>`;
}

function panelHtml(draft) {
  const s = getState();
  switch (draft.step) {
    case 0: {
      let parentOpts = "";
      s.departments.forEach((d) => {
        if (d.id === draft.deptId) return;
        parentOpts += `<option value="${d.id}"${String(d.id) === String(draft.parentId) ? " selected" : ""}>${escapeHtml(d.name)} (${d.id})</option>`;
      });
      return `<h2>${draft.create ? "Name the team" : "Team name"}</h2>
        <p class="lead">Use the name people already say out loud. Example: Hassan Team.</p>
        <div class="grid-2">
          <div class="field"><label for="teamName">Team name</label><input type="text" id="teamName" value="${escapeHtml(draft.name)}" placeholder="Hassan Team" /></div>
          <div class="field"><label for="parent">Parent department</label><select id="parent">${parentOpts}</select><div class="hint">${draft.create ? `Sales teams sit under Sales Department (${SALES_PARENT_ID}).` : "Removed agents move here."}</div></div>
        </div>`;
    }
    case 1:
      return `<h2>Who leads this team?</h2>
        <p class="lead">The leader sees every record owned by the team.</p>
        <div class="grid-3">
          <div class="field"><label for="headSelect">Pick from the tree</label><select id="headSelect">${userOptions(draft.headId)}</select></div>
          <div class="field"><label for="headById">Or Bitrix user ID</label><input type="number" id="headById" placeholder="2560" value="${draft.headId || ""}" /></div>
          <button type="button" class="btn" id="btnSearchHead" style="height:40px">${I.search}Search portal</button>
        </div>
        <div class="chips" id="headChip"></div>`;
    case 2:
      return `<h2>Who works in this team?</h2>
        <p class="lead">Agents see only records assigned to them. Remove someone with the ✕ on their chip.</p>
        <div class="grid-3">
          <div class="field"><label for="memberSelect">Pick from the tree</label><select id="memberSelect">${userOptions("", draft.members.concat(draft.headId ? [draft.headId] : []))}</select></div>
          <div class="field"><label for="memberById">Or Bitrix user ID</label><input type="number" id="memberById" placeholder="2380" /></div>
          <div style="display:flex;gap:8px"><button type="button" class="btn" id="btnAddMember" style="height:40px">${I.plus}Add</button><button type="button" class="btn" id="btnSearchMember" style="height:40px">${I.search}Search</button></div>
        </div>
        <div class="chips" id="memberChips"></div>`;
    case 3:
      return `<h2>Who sees what</h2>
        <p class="lead">Leader sees the team. Agents see only their own records. These scopes belong to the role, so they apply to every team using it.</p>
        <div class="mx" id="matrix">
          <div class="r h"><span></span><span>${draft.headId ? escapeHtml(userName(draft.headId)) + " · leader" : "Leader"}</span><span>${draft.members.length} agent${draft.members.length === 1 ? "" : "s"}</span></div>
          ${ENTITIES.map((e) => `<div class="r"><div class="ent"><b>${escapeHtml(e.label)}</b><small>${escapeHtml(e.help)}</small></div><div>${switchHtml("head", e.code, draft.security.head[e.code])}</div><div>${switchHtml("agent", e.code, draft.security.agent[e.code])}</div></div>`).join("")}
        </div>
        <details class="adv"><summary>Advanced · role IDs</summary>
          <div class="grid-3" style="margin-top:10px;grid-template-columns:1fr 1fr 1fr">
            <div class="field"><label for="headRole">Leader role</label><input type="number" id="headRole" value="${draft.headRole}" /></div>
            <div class="field"><label for="agentRole">Agent role</label><input type="number" id="agentRole" value="${draft.agentRole}" /></div>
            <div class="field"><label for="agentGroup">Agent group</label><input type="number" id="agentGroup" value="${draft.agentGroup}" /></div>
          </div>
        </details>`;
    default:
      return `<h2>Review, then apply</h2>
        <p class="lead">Preview shows the plan without touching CRM. Apply writes it and rebuilds access.</p>
        <div class="rules" style="max-width:640px">
          <div class="r h"><span></span><span>Leader</span><span>Agent</span></div>
          ${ENTITIES.map((e) => `<div class="r"><span>${escapeHtml(e.label)} <span class="dim">${escapeHtml(e.help)}</span></span>${tag(draft.security.head[e.code], true)}${tag(draft.security.agent[e.code], true)}</div>`).join("")}
        </div>
        <div id="banner" class="note" style="margin-top:14px"></div>
        <div id="checks" class="checks"></div>
        <div id="log" class="log"></div>`;
  }
}

function renderWizard(draft) {
  setTop({
    crumb: `<a href="#/">Teams</a>`,
    title: draft.create ? "New team" : draft.name || "Edit team",
    actions: `<span class="tag">Draft</span>
              <a class="btn" href="${draft.create ? "#/" : `#/team/${draft.deptId}`}">Cancel</a>`,
  });

  const last = draft.step === WIZARD_STEPS.length - 1;
  root().innerHTML = `
    <div class="steps glass pill" id="steps">${stepsHtml(draft)}</div>
    <div class="wz">
      <section class="wz-main glass" id="wzMain">
        ${panelHtml(draft)}
        <div class="wz-foot">
          <button type="button" class="btn" id="btnBack" ${draft.step === 0 ? "disabled" : ""}>${I.back}Back</button>
          <div class="sp"></div>
          ${last
            ? `<button type="button" class="btn" id="btnPreview">Preview</button>
               <button type="button" class="btn" id="btnVerifyNow" ${draft.deptId ? "" : "disabled"}>${I.shield}Verify</button>
               <button type="button" class="btn primary" id="btnApply">Apply to CRM</button>`
            : `<button type="button" class="btn primary" id="btnNext">Continue${I.right}</button>`}
        </div>
      </section>
      <aside class="sum glass" id="sum">${draftSummary(draft)}</aside>
    </div>`;

  wireWizard(draft);
  if (last) {
    if (draft.banner) note(document.getElementById("banner"), draft.banner.kind, draft.banner.text);
    if (draft.log) document.getElementById("log").textContent = draft.log;
    if (draft.checks) paintChecks(document.getElementById("checks"), draft.checks);
  }
}

function refreshSummary(draft) {
  const el = document.getElementById("sum");
  if (el) el.innerHTML = draftSummary(draft);
}

function syncDraftFromDom(draft) {
  if (draft.step === 0) {
    const nameEl = document.getElementById("teamName");
    if (nameEl) draft.name = nameEl.value;
    const parent = document.getElementById("parent");
    if (parent && parent.value) draft.parentId = Number(parent.value);
  }
  if (draft.step === 3) {
    const hr = document.getElementById("headRole");
    const ar = document.getElementById("agentRole");
    const ag = document.getElementById("agentGroup");
    if (hr) draft.headRole = Number(hr.value) || HEAD_ROLE_ID;
    if (ar) draft.agentRole = Number(ar.value) || AGENT_ROLE_ID;
    if (ag) draft.agentGroup = Number(ag.value) || AGENT_GROUP_ID;
  }
}

function validateStep(draft) {
  if (draft.step === 0) {
    if (!draft.name.trim()) return "Enter a team name.";
    if (draft.create && !draft.parentId) return "Choose a parent department.";
    if (!draft.create && !draft.deptId) return "Missing department ID.";
  }
  return null;
}

function stepError(text) {
  let el = document.getElementById("stepErr");
  if (!el) {
    el = document.createElement("div");
    el.id = "stepErr";
    const foot = document.querySelector(".wz-foot");
    foot.parentNode.insertBefore(el, foot);
  }
  note(el, "bad", text);
}

function wireWizard(draft) {
  const gotoStep = (n) => {
    syncDraftFromDom(draft);
    if (n > draft.step) {
      const err = validateStep(draft);
      if (err) {
        stepError(err);
        return;
      }
    }
    draft.step = Math.max(0, Math.min(WIZARD_STEPS.length - 1, n));
    renderWizard(draft);
  };
  document.getElementById("btnBack")?.addEventListener("click", () => gotoStep(draft.step - 1));
  document.getElementById("btnNext")?.addEventListener("click", () => gotoStep(draft.step + 1));
  document.querySelectorAll("#steps .s").forEach((b) => {
    b.addEventListener("click", () => {
      const n = Number(b.getAttribute("data-step"));
      if (n <= draft.step) gotoStep(n);
    });
  });

  document.getElementById("teamName")?.addEventListener("input", (e) => {
    draft.name = e.target.value;
    refreshSummary(draft);
  });
  document.getElementById("parent")?.addEventListener("change", (e) => {
    draft.parentId = Number(e.target.value) || null;
    refreshSummary(draft);
  });

  /* step 1: leader */
  const headSelect = document.getElementById("headSelect");
  const headById = document.getElementById("headById");
  const headChip = document.getElementById("headChip");
  const setHead = (id) => {
    draft.headId = id ? Number(id) : null;
    draft.members = draft.members.filter((m) => m !== draft.headId);
    if (headSelect) headSelect.value = draft.headId ? String(draft.headId) : "";
    if (headById) headById.value = draft.headId ? String(draft.headId) : "";
    refreshHead();
  };
  const refreshHead = () => {
    if (!headChip) return;
    headChip.innerHTML = draft.headId
      ? `<span class="chip">${av(draft.headId, userName(draft.headId))}<span>${escapeHtml(userName(draft.headId))} · ${draft.headId}</span><button type="button" class="x" id="clearHead" title="Remove leader">${I.x}</button></span>`
      : `<span class="chip-empty">${draft.original && draft.original.headId ? "Leader will be removed on Apply." : "No leader chosen. A team can exist without one, but nobody will see the whole team."}</span>`;
    document.getElementById("clearHead")?.addEventListener("click", () => setHead(null));
    refreshSummary(draft);
  };
  headSelect?.addEventListener("change", () => {
    if (!headSelect.value) return;
    getState().users[Number(headSelect.value)] = headSelect.options[headSelect.selectedIndex].text.replace(/\s\(\d+\)$/, "");
    setHead(headSelect.value);
  });
  headById?.addEventListener("change", () => headById.value && setHead(headById.value));
  document.getElementById("btnSearchHead")?.addEventListener("click", async () => {
    const u = await pickUser("Search the portal for a leader");
    if (u) setHead(u.id);
  });
  if (headChip) refreshHead();

  /* step 2: members */
  const memberChips = document.getElementById("memberChips");
  const refreshMembers = () => {
    if (!memberChips) return;
    const removed = removedMembers(draft);
    memberChips.innerHTML =
      (draft.members.length
        ? draft.members.map((id) => `<span class="chip">${av(id, userName(id))}<span>${escapeHtml(userName(id))} · ${id}</span><button type="button" class="x" data-remove="${id}" title="Remove from team">${I.x}</button></span>`).join("")
        : `<span class="chip-empty">No agents yet. Add people from the list, by ID, or search the portal.</span>`) +
      (removed.length ? `<div class="note info" style="width:100%">${I.move}<span>Leaving the team on Apply: ${removed.map((id) => escapeHtml(userName(id))).join(", ")}. <a href="#" id="undoRemove">Undo</a></span></div>` : "");
    memberChips.querySelectorAll("[data-remove]").forEach((x) =>
      x.addEventListener("click", () => {
        const id = Number(x.getAttribute("data-remove"));
        draft.members = draft.members.filter((m) => m !== id);
        refreshMembers();
      })
    );
    document.getElementById("undoRemove")?.addEventListener("click", (e) => {
      e.preventDefault();
      removed.forEach((id) => !draft.members.includes(id) && draft.members.push(id));
      refreshMembers();
    });
    refreshSummary(draft);
  };
  const addMember = (id) => {
    id = Number(id);
    if (!id || id === draft.headId) return;
    if (!draft.members.includes(id)) draft.members.push(id);
    refreshMembers();
  };
  document.getElementById("btnAddMember")?.addEventListener("click", () => {
    const sel = document.getElementById("memberSelect");
    const byId = document.getElementById("memberById");
    if (sel && sel.value) {
      getState().users[sel.value] = sel.options[sel.selectedIndex].text.replace(/\s\(\d+\)$/, "");
      addMember(sel.value);
      sel.value = "";
    }
    if (byId && byId.value) {
      addMember(byId.value);
      byId.value = "";
    }
  });
  document.getElementById("btnSearchMember")?.addEventListener("click", async () => {
    const u = await pickUser("Search the portal for an agent", { exclude: draft.members.concat(draft.headId ? [draft.headId] : []) });
    if (u) addMember(u.id);
  });
  document.getElementById("memberById")?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      document.getElementById("btnAddMember").click();
    }
  });
  if (memberChips) refreshMembers();

  /* step 3: scope switches */
  document.querySelectorAll("#matrix .sw").forEach((sw) => {
    const role = sw.getAttribute("data-role");
    const code = sw.getAttribute("data-entity");
    sw.querySelectorAll("button").forEach((b) =>
      b.addEventListener("click", () => {
        const v = b.getAttribute("data-scope");
        draft.security[role][code] = v;
        sw.querySelectorAll("button").forEach((o) => (o.className = o === b ? `on ${v}` : ""));
        refreshSummary(draft);
      })
    );
  });

  /* step 4: preview / apply / verify */
  const bannerEl = document.getElementById("banner");
  const checksEl = document.getElementById("checks");
  const logEl = document.getElementById("log");
  const remember = (kind, text) => {
    draft.banner = { kind, text };
    note(bannerEl, kind, text);
  };
  const setLog = (text) => {
    draft.log = text || "";
    if (logEl) logEl.textContent = draft.log;
  };
  const busy = (on) => {
    document.querySelectorAll("#btnPreview,#btnApply").forEach((b) => (b.disabled = on));
    const vb = document.getElementById("btnVerifyNow");
    if (vb) vb.disabled = on || !draft.deptId;
  };

  document.getElementById("btnPreview")?.addEventListener("click", async () => {
    syncDraftFromDom(draft);
    busy(true);
    remember("info", "Building preview…");
    const res = await api.applyChange(collectPayload(draft, true));
    busy(false);
    if (!res.ok) {
      remember("bad", res.data.error || "Preview failed.");
      setLog(JSON.stringify(res.data, null, 2));
      return;
    }
    remember("ok", "Preview only. Nothing changed in CRM.");
    setLog((res.data.plan || []).join("\n") || "No changes.");
  });

  document.getElementById("btnApply")?.addEventListener("click", async () => {
    syncDraftFromDom(draft);
    const removed = removedMembers(draft);
    const ok = await confirmDialog(
      `${draft.create ? "Create" : "Update"} "${draft.name}"`,
      `<p>Leader: <b>${draft.headId ? escapeHtml(userName(draft.headId)) : "none"}</b> · Agents: <b>${draft.members.length}</b>${removed.length ? ` · Leaving: <b>${removed.length}</b>` : ""}</p><p>This writes to the live CRM, rebuilds access codes and clears the permission cache.</p>`,
      { okText: "Apply to CRM" }
    );
    if (!ok) return;
    busy(true);
    remember("info", "Applying…");
    const res = await api.applyChange(collectPayload(draft, false));
    if (!res.ok) {
      busy(false);
      remember("bad", res.data.error || "Apply failed.");
      setLog(JSON.stringify(res.data, null, 2));
      logActivity(`Wizard apply failed for ${draft.name}`, false, draft.deptId);
      return;
    }
    remember("ok", "Applied. " + (res.data.hint || "Ask affected people to log out and back in once."));
    setLog((res.data.plan || []).join("\n") + "\n\n" + JSON.stringify(res.data.results || {}, null, 2));
    if (res.data.deptId) {
      draft.create = false;
      draft.deptId = res.data.deptId;
    }
    logActivity(`Wizard applied: ${draft.name}`, true, draft.deptId);
    draft.original = { headId: draft.headId, members: draft.members.slice() };
    setTop({ crumb: `<a href="#/">Teams</a>`, title: draft.name, actions: `<span class="tag self"><i></i>Applied</span><a class="btn" href="#/team/${draft.deptId}">Open team</a>` });
    await Promise.all([ensureTree(true), ensureRoles(true)]);
    await runVerify(draft, bannerEl, checksEl, true);
    draft.banner = null;
    busy(false);
    refreshSummary(draft);
  });

  document.getElementById("btnVerifyNow")?.addEventListener("click", async () => {
    syncDraftFromDom(draft);
    busy(true);
    await runVerify(draft, bannerEl, checksEl, true);
    busy(false);
  });
}

/* ── Verify ──────────────────────────────────────────── */
function paintChecks(el, checks) {
  if (!el) return;
  const groups = {};
  checks.forEach((c) => {
    const g = c.group || "checks";
    (groups[g] = groups[g] || []).push(c);
  });
  const labels = { team: "Team", leader: "Leader", agents: "Agents", roles: "Roles", records: "Records", checks: "Checks" };
  el.innerHTML = Object.keys(groups)
    .map((g) => {
      const list = groups[g];
      const bad = list.filter((c) => !c.pass && c.level !== "warn").length;
      const warn = list.filter((c) => !c.pass && c.level === "warn").length;
      return `<div class="vgroup"><h4>${labels[g] || g}${bad ? `<span class="tag sm all">${bad} failed</span>` : ""}${warn ? `<span class="tag sm warn">${warn} warning${warn === 1 ? "" : "s"}</span>` : ""}${!bad && !warn ? `<span class="tag sm self"><i></i>ok</span>` : ""}</h4>
        <div class="checks" style="margin-top:0">${list
          .map(
            (c, i) =>
              `<div class="check ${c.pass ? "pass" : c.level === "warn" ? "warn" : "fail"}"><span class="m">${c.pass ? "✓" : c.level === "warn" ? "!" : "✕"}</span>
               <span>${escapeHtml(c.name)}${c.detail ? ` <span class="d">${escapeHtml(c.detail)}</span>` : ""}</span>
               ${c.fix && !c.pass ? `<button type="button" class="btn sm fixbtn" data-fix="${g}:${i}">${I.wrench}Fix</button>` : ""}</div>`
          )
          .join("")}</div></div>`;
    })
    .join("");
  el.querySelectorAll("[data-fix]").forEach((b) =>
    b.addEventListener("click", async () => {
      const [g, i] = b.getAttribute("data-fix").split(":");
      const c = groups[g][Number(i)];
      const { action, ...params } = c.fix;
      const r = await doMutation(action, params, { title: `Fix: ${c.name}`, body: `<p>Runs <b>${escapeHtml(action)}</b> with ${escapeHtml(JSON.stringify(params))}.</p>`, deptId: params.dept_id || null });
      if (r) toast("Re-run verify to confirm the fix.", "ok");
    })
  );
}

async function runVerify(draft, bannerEl, checksEl, deep = false) {
  const deptId = draft.deptId;
  if (!deptId) {
    note(bannerEl, "bad", "Apply or choose a team first, then verify.");
    return null;
  }
  note(bannerEl, "info", deep ? "Running deep checks (roles, bindings, access codes, sample records)…" : "Running checks…");
  if (checksEl) checksEl.innerHTML = "";
  const res = await api.verifyTeam({
    team: {
      dept_id: Number(deptId),
      head_id: draft.headId ? Number(draft.headId) : null,
      members: draft.members.slice(),
      head_role_id: draft.headRole,
      agent_role_id: draft.agentRole,
      agent_group_id: draft.agentGroup,
    },
    entities: VERIFY_ENTITIES,
    deep,
  });
  if (!res.ok) {
    note(bannerEl, "bad", res.data.error || "Verify failed.");
    return null;
  }
  const checks = res.data.checks || [];
  const failed = checks.filter((c) => !c.pass && c.level !== "warn").length;
  const warned = checks.filter((c) => !c.pass && c.level === "warn").length;
  setVerify(deptId, res.data.allPassed, checks.filter((c) => c.level !== "warn"), deep);
  if (draft.checks !== undefined) draft.checks = checks;
  paintChecks(checksEl, checks);
  note(bannerEl, res.data.allPassed ? (warned ? "warn" : "ok") : "bad", res.data.allPassed ? (warned ? `All required checks passed. ${warned} warning${warned === 1 ? "" : "s"} below.` : "All checks passed.") : `${failed} check${failed === 1 ? "" : "s"} failed${warned ? `, ${warned} warning${warned === 1 ? "" : "s"}` : ""}. See below.`);
  logActivity(`${deep ? "Deep verify" : "Verify"}: ${res.data.allPassed ? "passed" : `${failed} failed`}`, res.data.allPassed, Number(deptId));
  return res.data;
}

function renderCreate() {
  const s = getState();
  const draft = blankDraft(true);
  draft.parentId = deptById(SALES_PARENT_ID) ? SALES_PARENT_ID : s.departments[0] ? s.departments[0].id : null;
  renderWizard(draft);
}

function renderEdit(id) {
  const d = deptById(id);
  if (!d) {
    setTop({ crumb: `<a href="#/">Teams</a>`, title: "Team not found" });
    root().innerHTML = `<div class="note bad">${I.warn}<span>That department is not in the tree. <a href="#/">Back to teams</a></span></div>`;
    return;
  }
  renderWizard(draftFromDept(d));
}

function renderVerify(id) {
  const s = getState();
  let opts = `<option value="">Choose a team</option>`;
  s.departments
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .forEach((d) => {
      opts += `<option value="${d.id}"${String(d.id) === String(id) ? " selected" : ""}>${escapeHtml(d.name)} (${d.id})</option>`;
    });
  setTop({ title: "Verify", actions: `<button type="button" class="btn" id="btnRun">${I.shield}Quick</button><button type="button" class="btn primary" id="btnRunDeep">${I.shield}Deep verify</button>` });
  root().innerHTML = `
    <div class="cols">
      <section class="panel glass">
        <div class="panel-head"><h2>Team</h2><span class="sub">quick = scopes and membership · deep = also roles, bindings, access codes, sample records</span></div>
        <div class="panel-body">
          <div class="field" style="max-width:420px"><label for="vTeam">Team</label><select id="vTeam">${opts}</select></div>
          <div id="banner" class="note" style="margin-top:14px"></div>
          <div id="checks"></div>
        </div>
      </section>
      <aside class="side">
        <section class="panel glass">
          <div class="panel-head"><h2>Recent results</h2></div>
          <div class="panel-body tight" style="padding-left:14px;padding-right:14px"><div class="attn" id="recent"></div></div>
        </section>
        <section class="panel glass">
          <div class="panel-head"><h2>Verify all sales teams</h2></div>
          <div class="panel-body"><button type="button" class="btn" id="btnAll">${I.shield}Run quick on all (${salesTeams().length})</button><div id="allOut" class="log"></div></div>
        </section>
      </aside>
    </div>`;

  const paintRecent = () => {
    const items = s.departments
      .map((d) => ({ d, v: getVerify(d.id) }))
      .filter((x) => x.v)
      .sort((a, b) => b.v.ts - a.v.ts)
      .slice(0, 8);
    document.getElementById("recent").innerHTML = items.length
      ? items.map((x) => `<a class="it" href="#/verify/${x.d.id}"><div class="ico ${x.v.allPassed ? "i" : "bad"}">${x.v.allPassed ? I.check : I.warn}</div><div><b>${escapeHtml(x.d.name)}</b><small>${x.v.allPassed ? "Passed" : `${x.v.failed} failed`}${x.v.deep ? " · deep" : ""} · ${formatWhen(x.v.ts)}</small></div><span class="go">${I.right}</span></a>`).join("")
      : `<div class="empty">No checks run yet.</div>`;
  };
  paintRecent();

  const run = async (deep) => {
    const deptId = document.getElementById("vTeam").value;
    const d = deptById(deptId);
    const bannerEl = document.getElementById("banner");
    if (!d) {
      note(bannerEl, "bad", "Choose a team first.");
      return;
    }
    const btns = ["btnRun", "btnRunDeep"].map((x) => document.getElementById(x));
    btns.forEach((b) => (b.disabled = true));
    const draft = draftFromDept(d);
    draft.checks = undefined;
    await runVerify(draft, bannerEl, document.getElementById("checks"), deep);
    btns.forEach((b) => (b.disabled = false));
    paintRecent();
  };
  document.getElementById("btnRun").addEventListener("click", () => run(false));
  document.getElementById("btnRunDeep").addEventListener("click", () => run(true));
  document.getElementById("vTeam").addEventListener("change", (e) => {
    if (e.target.value) history.replaceState(null, "", `#/verify/${e.target.value}`);
  });
  document.getElementById("btnAll").addEventListener("click", async () => {
    const out = document.getElementById("allOut");
    const btn = document.getElementById("btnAll");
    btn.disabled = true;
    out.textContent = "";
    for (const d of salesTeams()) {
      const draft = draftFromDept(d);
      draft.checks = undefined;
      const r = await api.verifyTeam({ team: { dept_id: d.id, head_id: draft.headId, members: draft.members, head_role_id: HEAD_ROLE_ID, agent_role_id: AGENT_ROLE_ID, agent_group_id: AGENT_GROUP_ID }, entities: VERIFY_ENTITIES, deep: false });
      if (r.ok) {
        const checks = r.data.checks || [];
        setVerify(d.id, r.data.allPassed, checks.filter((c) => c.level !== "warn"), false);
        out.textContent += `${r.data.allPassed ? "✓" : "✕"} ${d.name}${r.data.allPassed ? "" : ` — ${checks.filter((c) => !c.pass && c.level !== "warn").length} failed`}\n`;
      } else {
        out.textContent += `? ${d.name} — ${r.data.error || "error"}\n`;
      }
    }
    btn.disabled = false;
    paintRecent();
  });
  if (id && deptById(id)) run(false);
}

/* ── Roles (editable) ────────────────────────────────── */
const PERM_TYPES = ["READ", "WRITE", "ADD"];

async function renderRoles() {
  setTop({ title: "Roles & access", actions: `<button type="button" class="btn icon" id="btnReloadRoles" title="Reload">${I.reload}</button>` });
  root().innerHTML = `<div class="loading"><span class="spinner"></span> Loading roles…</div>`;
  const data = await ensureRoles(true);
  if (!data) {
    root().innerHTML = `<div class="note bad">${I.warn}<span>Could not load roles from CRM.</span></div>`;
    return;
  }
  const roles = data.roles || [];
  const perms = data.perms || {};
  const relations = data.relations || [];
  const mismatches = data.mismatches || {};
  const c14 = data.c14Stages || {};

  const cards = [HEAD_ROLE_ID, AGENT_ROLE_ID]
    .map((rid) => {
      const r = roles.find((x) => x.id === rid) || { id: rid, name: `Role ${rid}` };
      const p = perms[String(rid)] || {};
      const bound = relations.filter((x) => x.roleId === rid).map((x) => x.relation);
      const mm = mismatches[String(rid)] || 0;
      const stages = c14[String(rid)] || 0;
      const rows = ENTITIES.map((e) => {
        const ent = p[e.code] || {};
        return `<div class="perm edit"><span>${escapeHtml(e.label)} <span class="dim">${escapeHtml(e.help)}</span></span>${PERM_TYPES.map((pt) => {
          const v = ent[pt] || "";
          const sv = ent[pt + "_S"] || "";
          return `<span class="sw" data-role="${rid}" data-entity="${e.code}" data-perm="${pt}" title="${pt}${!v && sv ? ` · SETTINGS says ${scopeLabel(sv)} but ATTR is empty` : ""}">${SCOPES.map((s) => `<button type="button" class="${s.v === v ? `on ${s.v}` : ""}" data-scope="${s.v}">${s.v === "D" ? "Dept" : s.t}</button>`).join("")}</span>`;
        }).join("")}</div>`;
      }).join("");
      return `<section class="panel glass">
        <div class="panel-head"><h2>${escapeHtml(r.name)}</h2><span class="sub">role ${r.id}</span><div class="sp"></div>
          ${mm ? `<button type="button" class="btn sm danger" data-repair="${rid}">${I.wrench}Repair ${mm} row${mm === 1 ? "" : "s"}</button>` : `<span class="tag sm self"><i></i>rows consistent</span>`}
        </div>
        <div class="panel-body tight" style="display:flex;flex-direction:column;gap:6px">
          <div class="perm h edit"><span></span><span style="width:214px;text-align:center">Read</span><span style="width:214px;text-align:center">Write</span><span style="width:214px;text-align:center">Add</span></div>
          ${rows}
          <div class="perm" style="grid-template-columns:1fr auto"><span>Availability kanban stages <span class="dim">per-stage READ rows</span></span>${stages ? `<span class="tag sm self"><i></i>${stages} rows</span>` : `<button type="button" class="btn sm" data-stages="${rid}">${I.wrench}Add stage rows</button>`}</div>
        </div>
        <div class="panel-head" style="padding-top:6px"><h2 style="font-size:14px">Bindings</h2><span class="sub">who gets this role</span><div class="sp"></div><button type="button" class="btn sm" data-bind="${rid}">${I.plus}Add</button></div>
        <div class="panel-body tight"><div class="bindings">${bound.length ? bound.map((b) => `<span class="chip">${escapeHtml(b)}${/^U\d+$/.test(b) ? ` <span class="dim">${escapeHtml(userName(Number(b.slice(1))))}</span>` : b === `G${AGENT_GROUP_ID}` ? ` <span class="dim">Agent group</span>` : ""}<button type="button" class="x" data-unbind="${rid}:${escapeHtml(b)}" title="Unbind">${I.x}</button></span>`).join("") : `<span class="dim">Not bound to anyone.</span>`}</div></div>
      </section>`;
    })
    .join("");
  root().innerHTML = `<div class="cols" style="grid-template-columns:1fr 1fr">${cards}</div>
    <div class="note info">${I.shield}<span>Scopes are global to the role: a change here applies to every team using it. Click a scope to change it (you will confirm first). Self = own records, Dept = own + department, All = whole company.</span></div>`;

  document.getElementById("btnReloadRoles")?.addEventListener("click", renderRoles);
  document.querySelectorAll(".perm .sw").forEach((sw) => {
    const rid = Number(sw.getAttribute("data-role"));
    const ent = sw.getAttribute("data-entity");
    const pt = sw.getAttribute("data-perm");
    sw.querySelectorAll("button").forEach((b) =>
      b.addEventListener("click", async () => {
        const v = b.getAttribute("data-scope");
        if (b.classList.contains("on")) return;
        const label = ENTITIES.find((e) => e.code === ent)?.label || ent;
        const danger = v === "X" && rid === AGENT_ROLE_ID;
        await doMutation("set_perm", { role_id: rid, entity: ent, perm_type: pt, scope: v }, { title: `${roleName(rid)}: ${label} ${pt.toLowerCase()} = ${scopeLabel(v)}`, danger, okText: danger ? "Yes, give All" : "Apply", body: danger ? `<p class="note bad">${I.warn}<span>Every agent will see every ${escapeHtml(label.toLowerCase())} record in the company.</span></p>` : `<p>Applies to everyone holding role ${rid}. Caches are cleared right away.</p>` });
      })
    );
  });
  document.querySelectorAll("[data-repair]").forEach((b) =>
    b.addEventListener("click", () => {
      const rid = Number(b.getAttribute("data-repair"));
      doMutation("fix_role_attrs", { role_id: rid }, { title: `Repair role ${rid} permission rows`, body: `<p>Rows that only carry SETTINGS get the matching legacy ATTR (Self / Dept / All). Bitrix's CRM permission layer reads ATTR, so without it the role silently grants nothing for those entities.</p>` });
    })
  );
  document.querySelectorAll("[data-stages]").forEach((b) =>
    b.addEventListener("click", () => {
      const rid = Number(b.getAttribute("data-stages"));
      const scope = rid === HEAD_ROLE_ID ? "D" : "A";
      doMutation("fix_c14_stages", { role_id: rid, scope }, { title: `Add Availability stage rows to role ${rid}`, body: `<p>Copies the per-stage READ rows (scope ${scopeLabel(scope)}) so the Availability kanban shows cards for this role.</p>` });
    })
  );
  document.querySelectorAll("[data-unbind]").forEach((b) =>
    b.addEventListener("click", () => {
      const [rid, rel] = b.getAttribute("data-unbind").split(":");
      doMutation("unbind_role", { role_id: Number(rid), relation: rel }, { title: `Unbind ${rel} from role ${rid}`, danger: true, okText: "Unbind", body: `<p><b>${escapeHtml(rel)}</b> loses role <b>${escapeHtml(roleName(rid))}</b>.${rel === `G${AGENT_GROUP_ID}` ? " <b>This removes the Agent role from every agent.</b>" : ""}</p>` });
    })
  );
  document.querySelectorAll("[data-bind]").forEach((b) =>
    b.addEventListener("click", async () => {
      const rid = Number(b.getAttribute("data-bind"));
      const choice = await modal({
        title: `Bind role ${rid} to…`,
        body: `<div class="grid-2"><button type="button" class="btn" id="bindUser">${I.user}A person</button><div class="field"><label>Or an access code (U123, G11, D69)</label><input type="text" id="bindCode" placeholder="U2560" /></div></div>`,
        actions: [
          { label: "Cancel", value: null },
          { label: "Bind code", cls: "primary", keep: true, onClick: (ov, close) => close(ov.querySelector("#bindCode").value.trim().toUpperCase() || null) },
        ],
        onOpen: (ov, close) => ov.querySelector("#bindUser").addEventListener("click", async () => {
          close(null);
          const u = await pickUser(`Bind role ${rid} to a person`);
          if (u) doMutation("bind_role", { role_id: rid, relation: `U${u.id}` }, { title: `Bind ${u.name} to role ${rid}`, body: `<p><b>${escapeHtml(u.name)}</b> gets role <b>${escapeHtml(roleName(rid))}</b> directly.</p>` });
        }),
      });
      if (choice) doMutation("bind_role", { role_id: rid, relation: choice }, { title: `Bind ${choice} to role ${rid}`, body: `<p>Access code <b>${escapeHtml(choice)}</b> gets role <b>${escapeHtml(roleName(rid))}</b>.</p>` });
    })
  );
}

/* ── Help ────────────────────────────────────────────── */
function renderHelp() {
  setTop({ title: "How access works" });
  root().innerHTML = `
    <div class="help">
      <section class="panel glass"><div class="panel-head"><h2>Three scopes</h2></div><div class="panel-body">
        <div class="rules">
          <div class="r">${tag("A")}<span>Only records assigned to that person.</span></div>
          <div class="r">${tag("D")}<span>Own records plus everyone in the same department.</span></div>
          <div class="r">${tag("X")}<span>Every record in the company. Never for agents.</span></div>
        </div>
      </div></section>
      <section class="panel glass"><div class="panel-head"><h2>Safe defaults</h2></div><div class="panel-body">
        <ul><li><b>Leader</b> gets Department.</li><li><b>Agents</b> get Self.</li><li>Preview before Apply.</li><li>After a change, people log out and back in once.</li></ul>
      </div></section>
      <section class="panel glass"><div class="panel-head"><h2>Managing a team</h2></div><div class="panel-body">
        <ul><li>Open a team: change or remove the <b>leader</b>, add / move / remove <b>agents</b>, rename, move, delete.</li><li>Every change shows a confirmation with a <b>Preview</b> of exactly what will be written.</li><li>The <b>wizard</b> does all of it in one pass, including agents you remove.</li><li>The view refreshes on its own when the structure changes in CRM.</li></ul>
      </div></section>
      <section class="panel glass"><div class="panel-head"><h2>Verify</h2></div><div class="panel-body">
        <ul><li><b>Quick</b>: membership and effective read scope per person.</li><li><b>Deep</b>: also role bindings, Agent group, access codes, permission-row consistency, kanban stage rows and a real record test (own lead readable, teammate's not).</li><li>Failed checks offer a <b>Fix</b> button that runs the matching repair.</li></ul>
      </div></section>
      <section class="panel glass"><div class="panel-head"><h2>What a change does in Bitrix</h2></div><div class="panel-body">
        <ul><li>Department membership and UF_HEAD in the company structure.</li><li>Role bindings: leader gets role ${HEAD_ROLE_ID} via U&lt;id&gt;; agents get role ${AGENT_ROLE_ID} via group ${AGENT_GROUP_ID}.</li><li>Permission rows with both ATTR and SETTINGS so old and new Bitrix layers agree.</li><li>Access codes rebuilt, HR structure synced, CRM caches cleared, entity attributes refreshed.</li></ul>
      </div></section>
      <section class="panel glass"><div class="panel-head"><h2>Something looks wrong</h2></div><div class="panel-body">
        <ul><li>Run <b>Deep verify</b> on that team and use the Fix buttons.</li><li>Check <b>Roles</b>: "Not set" means the role grants nothing for that record type.</li><li>Ask the person to log out and in again.</li></ul>
      </div></section>
    </div>`;
}

/* ── Boot ────────────────────────────────────────────── */
async function render() {
  const route = parseHash();
  setActiveNav(route.name);
  document.querySelectorAll(".menu").forEach((m) => m.remove());

  if (!getState().loaded && route.name !== "help") {
    setTop({ title: "Loading" });
    root().innerHTML = `<div class="loading"><span class="spinner"></span> Loading company structure…</div>`;
    const [ok] = await Promise.all([ensureTree(), ensureRoles()]);
    if (!ok) {
      root().innerHTML = `<div class="note bad">${I.warn}<span>${escapeHtml(getState().error || "Load failed.")}</span></div>
        <div><button type="button" class="btn" id="retry">${I.reload}Try again</button></div>`;
      document.getElementById("retry")?.addEventListener("click", () => render());
      return;
    }
  }
  if (!getState().roles && route.name !== "help") {
    ensureRoles().then((r) => {
      if (r && ["teams", "team"].includes(parseHash().name)) render();
    });
  }

  switch (route.name) {
    case "team":
      renderTeam(route.params.id);
      break;
    case "edit":
      renderEdit(route.params.id);
      break;
    case "create":
      renderCreate();
      break;
    case "verify":
      renderVerify(route.params.id);
      break;
    case "roles":
      renderRoles();
      break;
    case "help":
      renderHelp();
      break;
    default:
      renderTeams();
  }
}

export async function start() {
  const me = await api.authMe();
  const avatar = document.getElementById("meAvatar");
  if (me.ok && me.data.user) {
    setMe(me.data.user);
    avatar.textContent = initials(me.data.user);
    avatar.title = `Signed in as ${me.data.user}`;
  } else {
    avatar.textContent = "A";
    avatar.title = "Admin session";
  }

  document.getElementById("q").addEventListener("input", (e) => {
    if (parseHash().name !== "teams") {
      teamsFilter.q = e.target.value;
      go("#/");
    }
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "/" && !["INPUT", "SELECT", "TEXTAREA"].includes(document.activeElement?.tagName) && !document.querySelector(".overlay")) {
      e.preventDefault();
      if (parseHash().name !== "teams") go("#/");
      setTimeout(() => document.getElementById("q")?.focus(), 30);
    }
  });

  window.addEventListener("hashchange", () => render());
  if (!location.hash) location.hash = "#/";
  await render();
  startLive();
}
