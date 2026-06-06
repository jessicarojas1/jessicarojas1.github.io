/* AeroMarkup — application views (offline-first, IndexedDB-authoritative). */
import { all, get, put, del, byIndex, uid, getMeta, setMeta } from "./store.js";
import { currentUser, can, getClassification } from "./session.js";
import { logAudit, recentAudit } from "./audit.js";
import { syncDrawing, pushNcr, pushApproval, netState } from "./api.js";
import { icon } from "./icons.js";
import { $, $$, el, esc, modal, toast, pill, fmtDate, fmtDay, timeAgo, formValues } from "./ui.js";
import { Editor } from "./canvas.js";
import { navigate } from "./router.js";
import { flattenPNG, renderInto, renderDiff, diffSnapshots } from "./snapshot.js";

/* =================================================================
   Seeding — give a brand-new (offline) device realistic demo content.
   ================================================================= */
export async function ensureSeed() {
  if (await getMeta("seeded", false)) return;
  const program = { id: uid(), client_uid: uid(), name: "F-XX Wing Assembly", code: "PRG-001",
    description: "Composite wing structural assembly program.", classification: "CUI", created_at: new Date().toISOString() };
  await put("programs", program);

  const project = { id: uid(), client_uid: uid(), program_id: program.id,
    name: "Left Wing – Ship 014", description: "Inspection & redline markup for ship 014 left wing.",
    category: "aerospace", tail_number: "N014XX", part_number: "WNG-LH-22041",
    serial_number: "SN-014", work_order: "WO-88123", status: "active", classification: "CUI",
    created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
  await put("projects", project);

  const drawing = { id: uid(), client_uid: uid(), project_id: project.id,
    title: "Upper Skin – Fastener Inspection", drawing_number: "DWG-22041-001", revision: "A",
    background_kind: "blank", background_data: null, width: 1600, height: 1200,
    units: "in", scale_ratio: null, status: "draft", classification: "CUI", version: 1,
    created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
  await put("drawings", drawing);

  const ncr = { id: uid(), client_uid: uid(), project_id: project.id, drawing_id: drawing.id,
    ncr_number: "NCR-2026-0001", title: "Fastener edge distance below minimum",
    description: "Three fasteners at STA 142 measure below minimum edge distance per drawing note 4.",
    severity: "major", defect_type: "Dimensional", status: "open", disposition: null,
    raised_by: "Engineering User", assigned_to: "Quality", due_date: null, classification: "CUI",
    created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
  await put("ncrs", ncr);

  await setMeta("seeded", true);
  await logAudit("seed.init", "system", null, { program: program.code });
}

/* small helpers */
const card = (title, bodyHtml, actions = "") => `
  <div class="card">
    <div class="card-head"><div class="card-title">${esc(title)}</div><div>${actions}</div></div>
    <div class="card-body">${bodyHtml}</div>
  </div>`;
const empty = (msg) => `<div class="empty-state">${icon("search", 22)}<p>${esc(msg)}</p></div>`;
const header = (title, sub, actions = "") => `
  <div class="view-header">
    <div><h1 class="view-title">${esc(title)}</h1>${sub ? `<div class="view-sub">${esc(sub)}</div>` : ""}</div>
    <div class="view-actions">${actions}</div>
  </div>`;

async function projectName(id) { const p = await get("projects", id); return p ? p.name : "—"; }

/* =================================================================
   Dashboard
   ================================================================= */
export async function renderDashboard(host) {
  const [projects, drawings, ncrs, approvals, audit] = await Promise.all([
    all("projects"), all("drawings"), all("ncrs"), all("approvals"), recentAudit(12),
  ]);
  const openNcr = ncrs.filter((n) => n.status !== "closed");
  const critical = ncrs.filter((n) => n.severity === "critical" && n.status !== "closed");
  const inReview = drawings.filter((d) => d.status === "in_review");

  const kpi = (label, value, mod, ic) => `
    <div class="kpi kpi-accent ${mod}">
      <div class="kpi-label">${esc(label)}</div>
      <div class="kpi-value">${value}</div>
      <div class="kpi-trend">${icon(ic, 16)} <span class="muted">live</span></div>
    </div>`;

  host.innerHTML = `
    ${header("Mission Dashboard", "Program lifecycle status at a glance")}
    <div class="kpi-grid">
      ${kpi("Active Projects", projects.length, "kpi-info", "project")}
      ${kpi("Drawings", drawings.length, "", "drawing")}
      ${kpi("Open NCRs", openNcr.length, "kpi-warn", "ncr")}
      ${kpi("Critical NCRs", critical.length, "kpi-danger", "alert")}
      ${kpi("In Review", inReview.length, "kpi-success", "approval")}
    </div>
    <div class="grid-2" style="margin-top:var(--s5)">
      ${card("Recent Activity", renderActivity(audit))}
      ${card("Open Nonconformances", await renderNcrMini(openNcr.slice(0, 6)))}
    </div>`;
}
function renderActivity(rows) {
  if (!rows.length) return empty("No activity recorded yet.");
  return `<div class="activity-feed">${rows.map((r) => `
    <div class="activity-item">${icon("audit", 16)}
      <div><strong>${esc(r.actor)}</strong> ${esc(r.action.replace(/[._]/g, " "))}
        ${r.entity_type ? `<span class="muted">· ${esc(r.entity_type)}</span>` : ""}
      </div>
      <span class="activity-time">${timeAgo(r.ts)}</span>
    </div>`).join("")}</div>`;
}
async function renderNcrMini(ncrs) {
  if (!ncrs.length) return empty("No open nonconformances. Nice.");
  return `<div class="table-wrap"><table class="table"><thead><tr>
    <th>NCR</th><th>Title</th><th>Severity</th><th>Status</th></tr></thead><tbody>
    ${ncrs.map((n) => `<tr>
      <td class="mono">${esc(n.ncr_number)}</td>
      <td>${esc(n.title)}</td>
      <td>${pill(n.severity)}</td>
      <td>${pill(n.status)}</td></tr>`).join("")}
  </tbody></table></div>`;
}

/* =================================================================
   Projects
   ================================================================= */
export async function renderProjects(host) {
  const [projects, programs, drawings, ncrs] = await Promise.all([
    all("projects"), all("programs"), all("drawings"), all("ncrs"),
  ]);
  const actions = can("project.manage")
    ? `<button class="btn btn-primary" data-new-project>${icon("plus", 16)} New Project</button>` : "";
  host.innerHTML = `${header("Projects", "Aircraft programs, parts and work orders", actions)}
    <div class="grid-3">${
      projects.length ? projects.map((p) => {
        const prog = programs.find((g) => g.id === p.program_id);
        const dCount = drawings.filter((d) => d.project_id === p.id).length;
        const nCount = ncrs.filter((n) => n.project_id === p.id && n.status !== "closed").length;
        return `<div class="card" data-open="${p.id}" style="cursor:pointer">
          <div class="card-head"><div class="card-title">${esc(p.name)}</div>${pill(p.status)}</div>
          <div class="card-body">
            <div class="muted" style="margin-bottom:var(--s3)">${esc(p.description || "")}</div>
            <div class="form-grid">
              <div><div class="kpi-label">Program</div><div class="mono">${esc(prog ? prog.code : "—")}</div></div>
              <div><div class="kpi-label">Tail</div><div class="mono">${esc(p.tail_number || "—")}</div></div>
              <div><div class="kpi-label">Part No.</div><div class="mono">${esc(p.part_number || "—")}</div></div>
              <div><div class="kpi-label">Work Order</div><div class="mono">${esc(p.work_order || "—")}</div></div>
            </div>
            <div style="margin-top:var(--s3);display:flex;gap:var(--s4)">
              <span>${icon("drawing", 15)} ${dCount} drawings</span>
              <span>${icon("ncr", 15)} ${nCount} open NCR</span>
            </div>
          </div></div>`;
      }).join("") : empty("No projects yet. Create one to begin.")
    }</div>`;

  $$("[data-open]", host).forEach((c) => c.addEventListener("click", () => navigate("project/" + c.dataset.open)));
  const nb = $("[data-new-project]", host);
  nb && nb.addEventListener("click", () => newProjectModal(host));
}

async function newProjectModal(host) {
  const programs = await all("programs");
  const body = el(`<div class="form-grid">
    <div class="field" style="grid-column:1/-1"><label>Project Name <span class="required">*</span></label><input class="input" data-name="name"></div>
    <div class="field" style="grid-column:1/-1"><label>Description</label><textarea class="textarea" data-name="description"></textarea></div>
    <div class="field"><label>Program</label><select class="select" data-name="program_id">
      <option value="">— none —</option>${programs.map((g) => `<option value="${g.id}">${esc(g.code)} · ${esc(g.name)}</option>`).join("")}</select></div>
    <div class="field"><label>Category</label><select class="select" data-name="category">
      <option value="aerospace">Aerospace</option><option value="manufacturing">Manufacturing</option>
      <option value="maintenance">Maintenance</option><option value="inspection">Inspection</option></select></div>
    <div class="field"><label>Tail Number</label><input class="input" data-name="tail_number"></div>
    <div class="field"><label>Part Number</label><input class="input" data-name="part_number"></div>
    <div class="field"><label>Serial Number</label><input class="input" data-name="serial_number"></div>
    <div class="field"><label>Work Order</label><input class="input" data-name="work_order"></div>
    <div class="field"><label>Classification</label><select class="select" data-name="classification">
      <option>CUI</option><option>UNCLASSIFIED</option><option>CUI//SP-PROPIN</option><option>UNCLASS//FOUO</option></select></div>
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Cancel</button>
    <button class="btn btn-primary" data-save>Create Project</button></div>`);
  const m = modal({ title: "New Project", body, footer: foot, width: 640 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-save]", foot).addEventListener("click", async () => {
    const v = formValues(body);
    if (!v.name) return toast("Project name is required", "error");
    const p = { id: uid(), client_uid: uid(), ...v, status: "active",
      created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
    await put("projects", p);
    await logAudit("project.create", "project", p.id, { name: p.name });
    m.close(); toast("Project created", "success"); renderProjects(host);
  });
}

/* =================================================================
   Project detail
   ================================================================= */
export async function renderProject(host, id) {
  const p = await get("projects", id);
  if (!p) { host.innerHTML = header("Project not found", ""); return; }
  const [drawings, ncrs, program] = await Promise.all([
    byIndex("drawings", "project_id", id), byIndex("ncrs", "project_id", id),
    p.program_id ? get("programs", p.program_id) : null,
  ]);
  const dActions = can("drawing.edit")
    ? `<button class="btn btn-primary" data-new-drawing>${icon("plus", 16)} New Drawing</button>` : "";
  host.innerHTML = `
    ${header(p.name, `${program ? program.code + " · " : ""}${p.tail_number || ""} ${p.part_number ? "· " + p.part_number : ""}`,
      `<button class="btn btn-ghost" data-back>${icon("chevron", 16)} Projects</button>`)}
    ${card("Drawings", drawings.length ? `<div class="table-wrap"><table class="table"><thead><tr>
        <th>Drawing No.</th><th>Title</th><th>Rev</th><th>Status</th><th></th></tr></thead><tbody>
        ${drawings.map((d) => `<tr>
          <td class="mono">${esc(d.drawing_number || "—")}</td>
          <td>${esc(d.title)}</td><td class="mono">${esc(d.revision || "A")}</td>
          <td>${pill(d.status || "draft")}</td>
          <td style="text-align:right"><button class="btn btn-sm btn-outline" data-edit="${d.id}">${icon("edit", 15)} Open</button></td>
        </tr>`).join("")}</tbody></table></div>` : empty("No drawings yet."), dActions)}
    ${card("Nonconformances", ncrs.length ? await renderNcrMini(ncrs) : empty("No NCRs for this project."))}
  `;
  $("[data-back]", host).addEventListener("click", () => navigate("projects"));
  $$("[data-edit]", host).forEach((b) => b.addEventListener("click", () => navigate("editor/" + b.dataset.edit)));
  const nd = $("[data-new-drawing]", host);
  nd && nd.addEventListener("click", () => newDrawingModal(host, p));
}

async function newDrawingModal(host, project) {
  const body = el(`<div class="form-grid">
    <div class="field" style="grid-column:1/-1"><label>Title <span class="required">*</span></label><input class="input" data-name="title"></div>
    <div class="field"><label>Drawing Number</label><input class="input" data-name="drawing_number"></div>
    <div class="field"><label>Revision</label><input class="input" data-name="revision" value="A"></div>
    <div class="field"><label>Units</label><select class="select" data-name="units"><option>in</option><option>mm</option><option>cm</option><option>ft</option></select></div>
    <div class="field"><label>Classification</label><select class="select" data-name="classification"><option>CUI</option><option>UNCLASSIFIED</option><option>CUI//SP-PROPIN</option></select></div>
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Cancel</button><button class="btn btn-primary" data-save>Create</button></div>`);
  const m = modal({ title: "New Drawing", body, footer: foot, width: 560 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-save]", foot).addEventListener("click", async () => {
    const v = formValues(body);
    if (!v.title) return toast("Title is required", "error");
    const d = { id: uid(), client_uid: uid(), project_id: project.id, ...v,
      background_kind: "blank", width: 1600, height: 1200, scale_ratio: null,
      status: "draft", version: 1, created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
    await put("drawings", d);
    await logAudit("drawing.create", "drawing", d.id, { title: d.title });
    m.close(); navigate("editor/" + d.id);
  });
}

/* =================================================================
   Drawing editor host (status workflow + sync + canvas)
   ================================================================= */
export async function renderEditor(host, drawingId) {
  const d = await get("drawings", drawingId);
  if (!d) { host.innerHTML = header("Drawing not found", ""); return; }
  const project = await get("projects", d.project_id);

  const wf = [];
  if (d.status === "draft" && can("drawing.submit")) wf.push(`<button class="btn btn-outline" data-wf="submit">Submit for Review</button>`);
  if (d.status === "in_review" && can("drawing.approve")) wf.push(`<button class="btn btn-success" data-wf="approve">${icon("check", 15)} Approve &amp; Sign</button>`);
  if (d.status === "approved" && can("drawing.release")) wf.push(`<button class="btn btn-primary" data-wf="release">Release</button>`);

  host.innerHTML = `
    ${header(`${d.drawing_number || d.title}`, `${project ? project.name + " · " : ""}Rev ${d.revision || "A"}`,
      `${pill(d.status || "draft")}
       <button class="btn btn-ghost" data-back>${icon("chevron", 16)} Project</button>
       ${wf.join("")}
       <button class="btn btn-ghost" data-revisions>${icon("layers", 15)} Revisions</button>
       <button class="btn btn-ghost" data-report>${icon("download", 15)} Report PDF</button>
       <button class="btn btn-primary" data-sync>${icon("sync", 15)} Sync</button>`)}
    <div class="editor-host" style="height:calc(100vh - var(--topbar) - var(--cui-h)*2 - 120px);min-height:480px"></div>`;

  const editorHost = $(".editor-host", host);
  const editor = new Editor(editorHost, d, {
    readOnly: !can("drawing.edit"),
    onDirty: () => setMeta("dirty_" + d.id, true),
  });
  await editor.mount();

  $("[data-back]", host).addEventListener("click", () => navigate("project/" + d.project_id));
  $("[data-sync]", host).addEventListener("click", async () => {
    toast("Syncing…", "info", 1500);
    try {
      await syncDrawing(d);
      await setMeta("dirty_" + d.id, false);
      await logAudit("drawing.sync", "drawing", d.id, {});
      toast("Synced to server", "success");
    } catch (e) { toast(e.message, "error", 5000); }
  });
  $("[data-report]", host).addEventListener("click", () => buildReport(d, project));
  $("[data-revisions]", host).addEventListener("click", () => revisionsModal(d, () => renderEditor(host, drawingId)));
  $$("[data-wf]", host).forEach((b) => b.addEventListener("click", () => signAction("drawing", d.id, b.dataset.wf, async (newStatus) => {
    if (newStatus) {
      d.status = newStatus; d.updated_at = new Date().toISOString(); await put("drawings", d);
      // capture an immutable snapshot at each lifecycle transition
      await saveRevision(d, `${b.dataset.wf} · status → ${newStatus}`);
    }
    renderEditor(host, drawingId);
  })));
}

/* =================================================================
   Snapshots · Revisions · Compare · PDF report
   ================================================================= */
async function buildSnapshot(drawing) {
  const strokes = await byIndex("strokes", "drawing_id", drawing.id);
  const annotations = await byIndex("annotations", "drawing_id", drawing.id);
  return {
    width: drawing.width, height: drawing.height, background_data: drawing.background_data,
    scale_ratio: drawing.scale_ratio, units: drawing.units, strokes, annotations,
  };
}

async function saveRevision(drawing, note) {
  const existing = await byIndex("revisions", "drawing_id", drawing.id);
  const rev = {
    id: uid(), client_uid: uid(), drawing_id: drawing.id,
    version: existing.length + 1, snapshot: await buildSnapshot(drawing),
    note: note || "", created_by: currentUser().display_name, created_at: new Date().toISOString(),
  };
  await put("revisions", rev);
  await logAudit("drawing.revision", "drawing", drawing.id, { version: rev.version, note: rev.note });
  return rev;
}

async function buildReport(drawing, project) {
  toast("Preparing report…", "info", 1500);
  const snap = await buildSnapshot(drawing);
  const [img, ncrs, approvals] = await Promise.all([
    flattenPNG(snap),
    byIndex("ncrs", "project_id", drawing.project_id),
    byIndex("approvals", "entity", `drawing:${drawing.id}`),
  ]);
  const cls = drawing.classification || (await getClassification());
  const measures = (snap.strokes || []).filter((s) => s.kind === "measure");
  const notes = (snap.annotations || []).filter((a) => a.kind === "note");
  const linkedNcr = ncrs.filter((n) => n.drawing_id === drawing.id);
  const when = fmtDate(new Date().toISOString());

  const metaRows = [
    ["Drawing No.", drawing.drawing_number || "—"], ["Revision", drawing.revision || "A"],
    ["Title", drawing.title], ["Status", (drawing.status || "draft").replace(/_/g, " ")],
    ["Project", project ? project.name : "—"], ["Tail / Part", `${project?.tail_number || "—"} / ${project?.part_number || "—"}`],
    ["Work Order", project?.work_order || "—"], ["Scale", drawing.scale_ratio ? `1px = ${drawing.scale_ratio.toFixed(4)} ${drawing.units || "in"}` : "uncalibrated"],
  ];
  const tbl = (head, rows) => rows.length
    ? `<table><thead><tr>${head.map((h) => `<th>${esc(h)}</th>`).join("")}</tr></thead><tbody>${rows.join("")}</tbody></table>`
    : `<p style="font-size:9pt;color:#555">None.</p>`;

  const rpt = el(`<div class="print-report">
    <div class="report-header">
      <div><h1>Engineering Markup Report</h1>
        <div class="mono">${esc(drawing.drawing_number || drawing.title)} · Rev ${esc(drawing.revision || "A")}</div></div>
      <div style="text-align:right"><div><strong>${esc(cls)}</strong></div><div>${esc(when)}</div>
        <div style="font-size:9pt">Generated by ${esc(currentUser().display_name)}</div></div>
    </div>
    <table><tbody>${metaRows.map(([k, v]) => `<tr><th style="width:160px">${esc(k)}</th><td>${esc(String(v))}</td></tr>`).join("")}</tbody></table>
    <h3 style="margin:14px 0 6px">Marked-up Drawing</h3>
    <img src="${img}" style="max-width:100%;border:1px solid #999"/>
    <h3 style="margin:14px 0 6px">Measurements</h3>
    ${tbl(["#", "Dimension"], measures.map((m, i) => {
      const px = Math.hypot(m.x2 - m.x, m.y2 - m.y);
      const val = drawing.scale_ratio ? (px * drawing.scale_ratio).toFixed(3) + " " + (drawing.units || "in") : Math.round(px) + " px";
      return `<tr><td>${i + 1}</td><td>${esc(val)}</td></tr>`;
    }))}
    <h3 style="margin:14px 0 6px">Notes</h3>
    ${tbl(["#", "Note"], notes.map((n, i) => `<tr><td>${i + 1}</td><td>${esc(n.text || "")}</td></tr>`))}
    <h3 style="margin:14px 0 6px">Linked Nonconformances</h3>
    ${tbl(["NCR", "Title", "Severity", "Status", "Disposition"], linkedNcr.map((n) =>
      `<tr><td class="mono">${esc(n.ncr_number)}</td><td>${esc(n.title)}</td><td>${esc(n.severity)}</td><td>${esc(n.status)}</td><td>${esc(n.disposition || "—")}</td></tr>`))}
    <h3 style="margin:14px 0 6px">Approval Signatures</h3>
    ${tbl(["When", "Action", "Signed By", "Signature Hash"], approvals.map((a) =>
      `<tr><td>${esc(fmtDate(a.created_at))}</td><td>${esc(a.action)}</td><td>${esc(a.actor_name)}</td><td class="mono">${esc(a.signature_hash || "")}</td></tr>`))}
    <div class="report-footer"><span>AeroMarkup · Controlled Engineering Record</span><span>${esc(cls)}</span></div>
  </div>`);

  document.body.appendChild(rpt);
  const cleanup = () => { rpt.remove(); window.removeEventListener("afterprint", cleanup); };
  window.addEventListener("afterprint", cleanup);
  setTimeout(() => window.print(), 120);
  setTimeout(cleanup, 120000); // safety net if afterprint never fires
}

async function revisionsModal(drawing, onChange) {
  const list = (await byIndex("revisions", "drawing_id", drawing.id)).sort((a, b) => b.version - a.version);
  const body = el(`<div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--s3)">
      <span class="muted">Immutable snapshots captured at each lifecycle transition (and on demand).</span>
      <button class="btn btn-sm btn-outline" data-save-rev>${icon("plus", 14)} Snapshot now</button>
    </div>
    ${list.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>Rev</th><th>Note</th><th>By</th><th>When</th><th>A</th><th>B</th></tr></thead><tbody>
      ${list.map((r) => `<tr>
        <td class="mono">v${r.version}</td><td>${esc(r.note || "—")}</td><td>${esc(r.created_by || "—")}</td>
        <td>${fmtDate(r.created_at)}</td>
        <td><input type="radio" name="rev-a" value="${r.id}"></td>
        <td><input type="radio" name="rev-b" value="${r.id}"></td>
      </tr>`).join("")}</tbody></table></div>`
      : empty("No revisions yet. Snapshot now or submit for review.")}
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Close</button>
    <button class="btn btn-primary" data-compare ${list.length < 2 ? "disabled" : ""}>${icon("layers", 15)} Compare A vs B</button></div>`);
  const m = modal({ title: `Revisions · ${drawing.drawing_number || drawing.title}`, body, footer: foot, width: 720 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-save-rev]", body).addEventListener("click", async () => {
    const note = prompt("Revision note (optional):") || "manual snapshot";
    await saveRevision(drawing, note); m.close(); toast("Revision saved", "success");
    onChange && onChange();
  });
  const cmp = $("[data-compare]", foot);
  cmp && cmp.addEventListener("click", () => {
    const aId = (body.querySelector('input[name="rev-a"]:checked') || {}).value;
    const bId = (body.querySelector('input[name="rev-b"]:checked') || {}).value;
    if (!aId || !bId || aId === bId) return toast("Pick two different revisions (A and B).", "error");
    const a = list.find((r) => r.id === aId), b = list.find((r) => r.id === bId);
    m.close(); compareModal(drawing, a, b);
  });
}

function compareModal(drawing, revA, revB) {
  const d = diffSnapshots(revA.snapshot, revB.snapshot);
  const body = el(`<div>
    <div class="grid-3" style="margin-bottom:var(--s3)">
      <div class="kpi kpi-accent kpi-success"><div class="kpi-label">Added in v${revB.version}</div><div class="kpi-value">${d.added.length}</div></div>
      <div class="kpi kpi-accent kpi-danger"><div class="kpi-label">Removed since v${revA.version}</div><div class="kpi-value">${d.removed.length}</div></div>
      <div class="kpi kpi-accent"><div class="kpi-label">Unchanged</div><div class="kpi-value">${d.unchanged}</div></div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-head"><div class="card-title">v${revA.version} — ${esc(revA.note || "")}</div></div>
        <div class="card-body" style="text-align:center"><canvas data-cv-a style="max-width:100%;border:1px solid var(--border);border-radius:var(--radius)"></canvas></div></div>
      <div class="card"><div class="card-head"><div class="card-title">v${revB.version} — ${esc(revB.note || "")}</div></div>
        <div class="card-body" style="text-align:center"><canvas data-cv-b style="max-width:100%;border:1px solid var(--border);border-radius:var(--radius)"></canvas></div></div>
    </div>
    <div class="card" style="margin-top:var(--s4)">
      <div class="card-head"><div class="card-title">Overlay diff</div>
        <span class="muted" style="font-size:.75rem"><span style="color:var(--success)">■</span> added &nbsp;<span style="color:var(--danger)">■</span> removed &nbsp;<span style="color:var(--text-faint)">■</span> unchanged</span></div>
      <div class="card-body" style="text-align:center"><canvas data-cv-diff style="max-width:100%;border:1px solid var(--border);border-radius:var(--radius)"></canvas></div>
    </div>
  </div>`);
  const m = modal({ title: `Compare Revisions · v${revA.version} → v${revB.version}`, body, width: 980 });
  // render after the modal is in the DOM
  setTimeout(async () => {
    try {
      await renderInto($("[data-cv-a]", body), revA.snapshot, 440, 330);
      await renderInto($("[data-cv-b]", body), revB.snapshot, 440, 330);
      await renderDiff($("[data-cv-diff]", body), revA.snapshot, revB.snapshot, 900, 520);
    } catch (e) { console.error(e); toast("Could not render comparison: " + e.message, "error"); }
  }, 30);
}

/* =================================================================
   E-signature workflow (drawings, NCRs, inspections)
   ================================================================= */
async function signHash(name, entityId) {
  const data = new TextEncoder().encode(`${name}|${entityId}|${Date.now()}`);
  try {
    const buf = await crypto.subtle.digest("SHA-256", data);
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("").slice(0, 32);
  } catch { return "sig-" + Date.now().toString(16); }
}
const NEXT_STATUS = { submit: "in_review", approve: "approved", reject: "draft", release: "released" };

export function signAction(entityType, entityId, action, after) {
  const u = currentUser();
  const verb = { submit: "Submit for Review", approve: "Approve", reject: "Reject", release: "Release" }[action] || action;
  const body = el(`<div>
    <p class="muted">You are about to <strong>${esc(verb)}</strong> this ${esc(entityType)}. This is recorded in the
    immutable audit trail as an electronic signature.</p>
    <div class="signature-box">
      <div class="kpi-label">Electronic Signature</div>
      <div class="signature-line">${esc(u.display_name || u.username)} · <span class="role-pill">${esc(u.role)}</span></div>
      <div class="field" style="margin-top:var(--s3)"><label>Type your full name to sign <span class="required">*</span></label>
        <input class="input" data-name="sig" placeholder="${esc(u.display_name || u.username)}"></div>
      <div class="field"><label>Comment</label><textarea class="textarea" data-name="comment"></textarea></div>
    </div></div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Cancel</button>
    <button class="btn btn-primary" data-sign>${icon("signature", 15)} Sign &amp; ${esc(verb)}</button></div>`);
  const m = modal({ title: verb, body, footer: foot, width: 520 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-sign]", foot).addEventListener("click", async () => {
    const v = formValues(body);
    if (!v.sig || v.sig.trim().length < 2) return toast("Type your name to sign", "error");
    const hash = await signHash(v.sig, entityId);
    const rec = { id: uid(), client_uid: uid(), entity: entityType, entity_key: `${entityType}:${entityId}`,
      entity_type: entityType, entity_id: entityId, action, actor_id: u.id, actor_name: v.sig.trim(),
      signature_hash: hash, comment: v.comment || "", created_at: new Date().toISOString() };
    await put("approvals", rec);
    pushApproval(rec).catch(() => {});
    await logAudit(`${entityType}.${action}`, entityType, entityId, { signature: hash, by: rec.actor_name });
    m.close(); toast(`${verb} signed`, "success");
    after && after(NEXT_STATUS[action] || null);
  });
}

/* =================================================================
   Nonconformance Reports (NCR)
   ================================================================= */
export async function renderNCR(host) {
  const [ncrs, projects] = await Promise.all([all("ncrs"), all("projects")]);
  ncrs.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
  const actions = can("ncr.create")
    ? `<button class="btn btn-primary" data-new>${icon("plus", 16)} Raise NCR</button>` : "";
  host.innerHTML = `${header("Nonconformance Reports", "Defect capture, disposition and closure", actions)}
    ${ncrs.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>NCR</th><th>Title</th><th>Project</th><th>Severity</th><th>Status</th><th>Disposition</th><th></th>
      </tr></thead><tbody>${ncrs.map((n) => {
        const proj = projects.find((p) => p.id === n.project_id);
        return `<tr>
          <td class="mono">${esc(n.ncr_number)}</td>
          <td>${esc(n.title)}</td>
          <td>${esc(proj ? proj.name : "—")}</td>
          <td>${pill(n.severity)}</td>
          <td>${pill(n.status)}</td>
          <td>${n.disposition ? pill(n.disposition) : '<span class="muted">—</span>'}</td>
          <td style="text-align:right"><button class="btn btn-sm btn-outline" data-view="${n.id}">Open</button></td>
        </tr>`; }).join("")}</tbody></table></div>` : empty("No nonconformances raised.")}`;
  const nb = $("[data-new]", host); nb && nb.addEventListener("click", () => ncrModal(host));
  $$("[data-view]", host).forEach((b) => b.addEventListener("click", () => ncrDetail(host, b.dataset.view)));
}

async function nextNcrNumber() {
  const n = (await all("ncrs")).length + 1;
  return `NCR-${new Date().getFullYear()}-${String(n).padStart(4, "0")}`;
}

async function ncrModal(host) {
  const projects = await all("projects");
  const body = el(`<div class="form-grid">
    <div class="field" style="grid-column:1/-1"><label>Title <span class="required">*</span></label><input class="input" data-name="title"></div>
    <div class="field" style="grid-column:1/-1"><label>Description</label><textarea class="textarea" data-name="description"></textarea></div>
    <div class="field"><label>Project <span class="required">*</span></label><select class="select" data-name="project_id">
      ${projects.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select></div>
    <div class="field"><label>Severity</label><select class="select" data-name="severity"><option>minor</option><option selected>major</option><option>critical</option></select></div>
    <div class="field"><label>Defect Type</label><input class="input" data-name="defect_type" placeholder="Dimensional, Surface, Material…"></div>
    <div class="field"><label>Assigned To</label><input class="input" data-name="assigned_to" placeholder="Quality"></div>
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Cancel</button><button class="btn btn-primary" data-save>Raise NCR</button></div>`);
  const m = modal({ title: "Raise Nonconformance", body, footer: foot, width: 640 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-save]", foot).addEventListener("click", async () => {
    const v = formValues(body);
    if (!v.title) return toast("Title is required", "error");
    const n = { id: uid(), client_uid: uid(), ncr_number: await nextNcrNumber(), ...v,
      status: "open", disposition: null, raised_by: currentUser().display_name,
      classification: "CUI", created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
    await put("ncrs", n); pushNcr(n).catch(() => {});
    await logAudit("ncr.create", "ncr", n.id, { number: n.ncr_number, severity: n.severity });
    m.close(); toast(`${n.ncr_number} raised`, "success"); renderNCR(host);
  });
}

async function ncrDetail(host, id) {
  const n = await get("ncrs", id); if (!n) return;
  const canDisp = can("ncr.disposition");
  const dispOpts = ["use_as_is", "rework", "repair", "scrap", "return_to_vendor"];
  const body = el(`<div>
    <div class="form-grid">
      <div><div class="kpi-label">NCR</div><div class="mono">${esc(n.ncr_number)}</div></div>
      <div><div class="kpi-label">Severity</div>${pill(n.severity)}</div>
      <div><div class="kpi-label">Status</div>${pill(n.status)}</div>
      <div><div class="kpi-label">Raised By</div><div>${esc(n.raised_by || "—")}</div></div>
    </div>
    <div class="field" style="margin-top:var(--s4)"><label>Description</label>
      <div class="card-body" style="border:1px solid var(--border);border-radius:var(--radius)">${esc(n.description || "—")}</div></div>
    ${canDisp ? `<div class="form-grid" style="margin-top:var(--s4)">
      <div class="field"><label>Disposition</label><select class="select" data-name="disposition">
        <option value="">— select —</option>${dispOpts.map((o) => `<option value="${o}" ${n.disposition === o ? "selected" : ""}>${o.replace(/_/g, " ")}</option>`).join("")}</select></div>
      <div class="field"><label>Status</label><select class="select" data-name="status">
        ${["open", "in_review", "dispositioned", "closed"].map((s) => `<option ${n.status === s ? "selected" : ""}>${s}</option>`).join("")}</select></div>
      <div class="field" style="grid-column:1/-1"><label>Disposition Notes</label><textarea class="textarea" data-name="disposition_notes">${esc(n.disposition_notes || "")}</textarea></div>
    </div>` : `<p class="muted" style="margin-top:var(--s4)">Disposition requires approver/admin role.</p>`}
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Close</button>
    ${canDisp ? `<button class="btn btn-primary" data-save>Save Disposition</button>` : ""}</div>`);
  const m = modal({ title: n.title, body, footer: foot, width: 640 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  const sv = $("[data-save]", foot);
  sv && sv.addEventListener("click", async () => {
    const v = formValues(body);
    Object.assign(n, { disposition: v.disposition || null, status: v.status, disposition_notes: v.disposition_notes, updated_at: new Date().toISOString() });
    await put("ncrs", n); pushNcr(n).catch(() => {});
    await logAudit("ncr.disposition", "ncr", n.id, { disposition: n.disposition, status: n.status });
    m.close(); toast("NCR updated", "success"); renderNCR(host);
  });
}

/* =================================================================
   Inspections
   ================================================================= */
export async function renderInspections(host) {
  const [insp, projects] = await Promise.all([all("inspections"), all("projects")]);
  insp.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
  const actions = can("inspection.perform")
    ? `<button class="btn btn-primary" data-new>${icon("plus", 16)} New Inspection</button>` : "";
  host.innerHTML = `${header("Inspections", "Quality inspection records (AS9100-style)", actions)}
    ${insp.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>Type</th><th>Project</th><th>Result</th><th>Inspector</th><th>Performed</th></tr></thead><tbody>
      ${insp.map((i) => { const p = projects.find((x) => x.id === i.project_id); return `<tr>
        <td>${esc(i.type || "General")}</td><td>${esc(p ? p.name : "—")}</td>
        <td>${pill(i.result)}</td><td>${esc(i.inspector || "—")}</td><td>${fmtDate(i.performed_at)}</td></tr>`; }).join("")}
    </tbody></table></div>` : empty("No inspections recorded.")}`;
  const nb = $("[data-new]", host); nb && nb.addEventListener("click", () => inspectionModal(host));
}
async function inspectionModal(host) {
  const projects = await all("projects");
  const body = el(`<div class="form-grid">
    <div class="field"><label>Type</label><input class="input" data-name="type" placeholder="First Article, In-Process, Final…"></div>
    <div class="field"><label>Project</label><select class="select" data-name="project_id">${projects.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select></div>
    <div class="field"><label>Result</label><select class="select" data-name="result"><option>pending</option><option>pass</option><option>fail</option></select></div>
    <div class="field"><label>Inspector</label><input class="input" data-name="inspector" value="${esc(currentUser().display_name)}"></div>
    <div class="field" style="grid-column:1/-1"><label>Notes</label><textarea class="textarea" data-name="notes"></textarea></div>
  </div>`);
  const foot = el(`<div><button class="btn btn-ghost" data-cancel>Cancel</button><button class="btn btn-primary" data-save>Record</button></div>`);
  const m = modal({ title: "New Inspection", body, footer: foot, width: 600 });
  $("[data-cancel]", foot).addEventListener("click", m.close);
  $("[data-save]", foot).addEventListener("click", async () => {
    const v = formValues(body);
    const i = { id: uid(), client_uid: uid(), ...v, performed_at: new Date().toISOString(), created_at: new Date().toISOString() };
    await put("inspections", i);
    await logAudit("inspection.record", "inspection", i.id, { result: i.result });
    m.close(); toast("Inspection recorded", "success"); renderInspections(host);
  });
}

/* =================================================================
   Approvals / review queue
   ================================================================= */
export async function renderApprovals(host) {
  const [drawings, approvals] = await Promise.all([all("drawings"), all("approvals")]);
  approvals.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
  const queue = drawings.filter((d) => d.status === "in_review" || d.status === "approved");
  host.innerHTML = `${header("Approvals & E-Signatures", "Review queue and the signed approval trail")}
    ${card("Review Queue", queue.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>Drawing</th><th>Rev</th><th>Status</th><th></th></tr></thead><tbody>
      ${queue.map((d) => `<tr><td class="mono">${esc(d.drawing_number || d.title)}</td>
        <td class="mono">${esc(d.revision || "A")}</td><td>${pill(d.status)}</td>
        <td style="text-align:right"><button class="btn btn-sm btn-outline" data-open="${d.id}">${icon("edit", 14)} Review</button></td></tr>`).join("")}
      </tbody></table></div>` : empty("Nothing awaiting approval."))}
    ${card("Signature Trail", approvals.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>When</th><th>Action</th><th>Entity</th><th>Signed By</th><th>Signature</th></tr></thead><tbody>
      ${approvals.slice(0, 50).map((a) => `<tr><td>${fmtDate(a.created_at)}</td><td>${pill(a.action)}</td>
        <td>${esc(a.entity_type)}</td><td>${esc(a.actor_name)}</td>
        <td class="mono" style="font-size:.75rem">${esc(a.signature_hash || "")}</td></tr>`).join("")}
      </tbody></table></div>` : empty("No approvals signed yet."))}`;
  $$("[data-open]", host).forEach((b) => b.addEventListener("click", () => navigate("editor/" + b.dataset.open)));
}

/* =================================================================
   Audit log
   ================================================================= */
export async function renderAudit(host) {
  const rows = await recentAudit(300);
  host.innerHTML = `${header("Audit Trail", "Immutable, append-only activity record")}
    ${rows.length ? `<div class="table-wrap"><table class="table"><thead><tr>
      <th>Timestamp</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Detail</th></tr></thead><tbody>
      ${rows.map((r) => `<tr><td>${fmtDate(r.ts)}</td><td>${esc(r.actor)}</td>
        <td><span class="role-pill">${esc(r.actor_role || "—")}</span></td>
        <td class="mono">${esc(r.action)}</td><td>${esc(r.entity_type || "—")}</td>
        <td class="mono" style="font-size:.75rem">${esc(JSON.stringify(r.detail || {}))}</td></tr>`).join("")}
    </tbody></table></div>` : empty("No audit records.")}`;
}

/* =================================================================
   Admin — users & roles + session identity
   ================================================================= */
export async function renderAdmin(host) {
  const u = currentUser();
  const users = await all("users");
  host.innerHTML = `${header("Administration", "Identity, roles and access")}
    ${card("Your Session", `<div class="form-grid">
      <div class="field"><label>Display Name</label><input class="input" data-name="display_name" value="${esc(u.display_name || "")}"></div>
      <div class="field"><label>Role</label><select class="select" data-name="role">
        ${["viewer", "engineer", "inspector", "approver", "admin"].map((r) => `<option ${u.role === r ? "selected" : ""}>${r}</option>`).join("")}</select></div>
      </div>`, `<button class="btn btn-primary" data-save-session>Update</button>`)}
    ${card("Role Capabilities", `<div class="table-wrap"><table class="table"><thead><tr><th>Role</th><th>Can do</th></tr></thead><tbody>
      <tr><td><span class="role-pill">viewer</span></td><td>Read-only access to drawings, NCRs, audit.</td></tr>
      <tr><td><span class="role-pill">engineer</span></td><td>Create/edit drawings & projects, raise NCRs, submit for review.</td></tr>
      <tr><td><span class="role-pill">inspector</span></td><td>Perform inspections, raise NCRs.</td></tr>
      <tr><td><span class="role-pill">approver</span></td><td>Approve/release drawings, disposition NCRs (e-signature).</td></tr>
      <tr><td><span class="role-pill">admin</span></td><td>Full access incl. user management.</td></tr>
    </tbody></table></div>`)}`;
  $("[data-save-session]", host).addEventListener("click", async () => {
    const { setUser } = await import("./session.js");
    const v = formValues(host);
    await setUser({ display_name: v.display_name, role: v.role });
    await logAudit("session.update", "user", u.id, { role: v.role });
    toast("Session updated", "success");
    window.dispatchEvent(new CustomEvent("am:session-changed"));
    renderAdmin(host);
  });
}
