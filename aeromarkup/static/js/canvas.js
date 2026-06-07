/* AeroMarkup — engineering markup engine (pro).
   Pointer Events (mouse/touch/pressure stylus), pan + pinch-zoom, grid + snap
   + ortho, a full selection model (move / edit / delete / duplicate / z-order),
   layers, and a deep tool set: pen, highlighter, eraser, line, arrow, rect,
   ellipse, revision cloud, text, note, pin, QA stamp, balloon, and linear /
   angle / area measurement with scale calibration. Rendering is delegated to
   snapshot.drawItem so screen, export, report and diff are pixel-identical. */
import { put, byIndex, del, uid } from "./store.js";
import { icon } from "./icons.js";
import { $, $$, el, toast, esc } from "./ui.js";
import { drawItem, typeOf } from "./snapshot.js";

const DPR = Math.min(window.devicePixelRatio || 1, 2);
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const ANNO = new Set(["text", "note", "pin", "stamp", "balloon"]);
const FREE = new Set(["pen", "highlighter", "eraser"]);
const SEG = new Set(["line", "arrow", "measure"]);
const BOX = new Set(["rect", "ellipse", "cloud"]);
const POLY = new Set(["area", "angle"]);
const bucketOf = (t) => (ANNO.has(t) ? "annotations" : "strokes");

const GROUPS = [
  ["Select", [["select", "Select / move", "cursor", "V"]]],
  ["Draw", [["pen", "Pen", "pen", "P"], ["highlighter", "Highlighter", "highlighter", "H"], ["eraser", "Eraser", "eraser", "E"]]],
  ["Shapes", [["line", "Line", "line", "L"], ["arrow", "Arrow", "arrow", "A"], ["rect", "Rectangle", "rect", "R"],
    ["ellipse", "Ellipse", "ellipse", "O"], ["cloud", "Revision cloud", "cloud", "C"]]],
  ["Annotate", [["text", "Text", "text", "T"], ["note", "Note", "note", "N"], ["pin", "Pin", "pin", "M"],
    ["stamp", "QA stamp", "stamp", "S"], ["balloon", "Balloon (FAI)", "balloon", "B"]]],
  ["Measure", [["measure", "Linear", "measure", "D"], ["angle", "Angle", "angle", "G"], ["area", "Area", "area", "Z"]]],
  ["Nav", [["pan", "Pan", "pan", "Space"]]],
];
const SWATCHES = ["#ff5811", "#ffcc00", "#3ad07a", "#3ba7ff", "#ff4d4d", "#ffffff", "#b06cff", "#00e5d0"];

let CURRENT = null; // editor receiving keyboard shortcuts

export class Editor {
  constructor(container, drawing, opts = {}) {
    this.c = container; this.drawing = drawing; this.opts = opts;
    this.tool = "select";
    this.color = "#ff5811"; this.size = 3; this.opacity = 1;
    this.view = { scale: 1, tx: 0, ty: 0 };
    this.items = [];
    this.selection = new Set();
    this.layers = []; this.activeLayerId = null;
    this.undoStack = []; this.redoStack = [];
    this.pointers = new Map(); this.pinch = null; this.panStart = null;
    this.draft = null; this.pending = null; this.moving = null;
    this.grid = false; this.snap = false; this.gridSize = 25;
    this.shift = false;
  }

  async mount() {
    CURRENT = this;
    this.c.innerHTML = `
      <div class="editor">
        <div class="tool-rail">
          ${GROUPS.map(([, tools], gi) => (gi ? '<div class="tool-sep"></div>' : "") +
            tools.map(([t, label, ic, key]) => `<button class="tool" data-tool="${t}" title="${label} (${key})">${icon(ic, 20)}</button>`).join("")).join("")}
          <div class="tool-sep"></div>
          <button class="tool toggle" data-act="grid" title="Grid (#)">${icon("grid", 20)}</button>
          <button class="tool toggle" data-act="snap" title="Snap to grid">${icon("magnet", 20)}</button>
          <div class="tool-sep"></div>
          <button class="tool" data-act="undo" title="Undo (Ctrl+Z)">${icon("undo", 20)}</button>
          <button class="tool" data-act="redo" title="Redo (Ctrl+Y)">${icon("redo", 20)}</button>
          <button class="tool" data-act="fit" title="Fit (F)">${icon("fit", 20)}</button>
          <button class="tool" data-act="image" title="Load image">${icon("image", 20)}</button>
          <button class="tool" data-act="calibrate" title="Calibrate scale">${icon("measure", 20)}</button>
          <button class="tool" data-act="export" title="Export PNG">${icon("export", 20)}</button>
          <button class="tool" data-act="help" title="Shortcuts (?)">${icon("help", 20)}</button>
          <button class="tool tool-danger" data-act="clear" title="Clear markup">${icon("trash", 20)}</button>
        </div>

        <div class="stage" tabindex="0">
          <canvas class="layer" data-c="bg"></canvas>
          <canvas class="layer" data-c="ink"></canvas>
          <canvas class="layer" data-c="live"></canvas>
          <div class="zoom-readout">100%</div>
        </div>

        <div class="statusbar">
          <span class="status-seg" data-st-pos>x 0, y 0</span>
          <span class="status-seg" data-st-real></span>
          <span class="status-seg push" data-st-sel>0 selected</span>
          <span class="status-seg" data-st-layer>Markup</span>
          <span class="status-seg" data-st-zoom>100%</span>
        </div>

        <div class="right-panel" data-panel></div>

        <input type="file" accept="image/*" data-image-file hidden />
        <div class="note-editor hidden" data-note-editor>
          <textarea class="textarea" data-note-text placeholder="Type text…"></textarea>
          <div class="modal-foot">
            <button class="btn btn-ghost btn-sm" data-note-cancel>Cancel</button>
            <button class="btn btn-primary btn-sm" data-note-save>Apply</button>
          </div>
        </div>
      </div>`;

    this.stage = $(".stage", this.c);
    this.bg = $('[data-c="bg"]', this.c); this.bgX = this.bg.getContext("2d");
    this.ink = $('[data-c="ink"]', this.c); this.inkX = this.ink.getContext("2d");
    this.live = $('[data-c="live"]', this.c); this.liveX = this.live.getContext("2d");
    this.panel = $("[data-panel]", this.c);

    await this._loadLayers();
    await this._loadItems();

    this._wire();
    this._setTool("select");
    this._sizeCanvases();
    this._renderBg();
    this._render();
    this._renderPanel();
    window.addEventListener("am:fai-changed", () => { if (CURRENT === this && document.body.contains(this.c)) this._reload(); });
    requestAnimationFrame(() => this.fit());
  }

  async _reload() { await this._loadItems(); this._render(); this._renderPanel(); }

  /* ---- data ---- */
  async _loadLayers() {
    this.layers = await byIndex("layers", "drawing_id", this.drawing.id);
    if (!this.layers.length) {
      const l = { id: uid(), client_uid: uid(), drawing_id: this.drawing.id, name: "Markup", color: "#ff5811", visible: true, locked: false, z_index: 0 };
      await put("layers", l); this.layers = [l];
    }
    this.layers.sort((a, b) => (a.z_index || 0) - (b.z_index || 0));
    this.activeLayerId = this.layers[0].id;
  }
  async _loadItems() {
    const s = await byIndex("strokes", "drawing_id", this.drawing.id);
    const a = await byIndex("annotations", "drawing_id", this.drawing.id);
    this.items = [...s, ...a].map((r) => { r.type = typeOf(r); if (!r.layer_id && this.layers[0]) r.layer_id = this.layers[0].id; return r; });
  }
  layerById(id) { return this.layers.find((l) => l.id === id); }
  _layerVisible(it) { const l = this.layerById(it.layer_id); return !l || l.visible; }
  _layerLocked(it) { const l = this.layerById(it.layer_id); return l && l.locked; }
  async _persist(it) { await put(bucketOf(it.type), it); this._dirty(); }
  async _del(it) { await del(bucketOf(it.type), it.id); }
  _dirty() { this.opts.onDirty && this.opts.onDirty(); window.dispatchEvent(new CustomEvent("am:markup-changed")); }

  async _syncStore() {
    const sIds = (await byIndex("strokes", "drawing_id", this.drawing.id)).map((r) => r.id);
    const aIds = (await byIndex("annotations", "drawing_id", this.drawing.id)).map((r) => r.id);
    const keep = new Set(this.items.map((i) => i.id));
    for (const id of sIds) if (!keep.has(id)) await del("strokes", id);
    for (const id of aIds) if (!keep.has(id)) await del("annotations", id);
    for (const it of this.items) await put(bucketOf(it.type), it);
    this._dirty();
  }

  /* ---- history ---- */
  _push() { this.undoStack.push(JSON.stringify(this.items)); if (this.undoStack.length > 60) this.undoStack.shift(); this.redoStack = []; }
  async _undo() {
    if (!this.undoStack.length) return;
    this.redoStack.push(JSON.stringify(this.items));
    this.items = JSON.parse(this.undoStack.pop()).map((r) => (r.type = typeOf(r), r));
    this.selection.clear(); await this._syncStore(); this._render(); this._renderPanel();
  }
  async _redo() {
    if (!this.redoStack.length) return;
    this.undoStack.push(JSON.stringify(this.items));
    this.items = JSON.parse(this.redoStack.pop()).map((r) => (r.type = typeOf(r), r));
    this.selection.clear(); await this._syncStore(); this._render(); this._renderPanel();
  }

  /* ---- transforms ---- */
  _applyTransform() {
    const { scale, tx, ty } = this.view;
    const t = `translate(${tx}px,${ty}px) scale(${scale})`;
    [this.bg, this.ink, this.live].forEach((e) => (e.style.transform = t));
    $(".zoom-readout", this.c).textContent = Math.round(scale * 100) + "%";
    const z = $("[data-st-zoom]", this.c); if (z) z.textContent = Math.round(scale * 100) + "%";
  }
  _toWorld(cx, cy) {
    const r = this.stage.getBoundingClientRect(); const { scale, tx, ty } = this.view;
    let x = (cx - r.left - tx) / scale, y = (cy - r.top - ty) / scale;
    return { x, y };
  }
  _snap(p) { return this.snap ? { x: Math.round(p.x / this.gridSize) * this.gridSize, y: Math.round(p.y / this.gridSize) * this.gridSize } : p; }
  fit() {
    const r = this.stage.getBoundingClientRect();
    const s = Math.min(r.width / this.drawing.width, r.height / this.drawing.height) * 0.94 || 1;
    this.view.scale = s;
    this.view.tx = (r.width - this.drawing.width * s) / 2;
    this.view.ty = (r.height - this.drawing.height * s) / 2;
    this._applyTransform();
  }
  _sizeCanvases() {
    const w = this.drawing.width, h = this.drawing.height;
    [this.bg, this.ink, this.live].forEach((cv) => {
      cv.width = w * DPR; cv.height = h * DPR; cv.style.width = w + "px"; cv.style.height = h + "px";
      cv.getContext("2d").setTransform(DPR, 0, 0, DPR, 0, 0);
    });
  }

  /* ---- rendering ---- */
  _renderBg() {
    const w = this.drawing.width, h = this.drawing.height;
    this.bgX.clearRect(0, 0, w, h);
    this.bgX.fillStyle = "#0f1420"; this.bgX.fillRect(0, 0, w, h);
    const paint = () => {
      if (this.grid) {
        this.bgX.strokeStyle = "rgba(255,255,255,.05)"; this.bgX.lineWidth = 1;
        for (let x = 0; x <= w; x += this.gridSize) { this.bgX.beginPath(); this.bgX.moveTo(x, 0); this.bgX.lineTo(x, h); this.bgX.stroke(); }
        for (let y = 0; y <= h; y += this.gridSize) { this.bgX.beginPath(); this.bgX.moveTo(0, y); this.bgX.lineTo(w, y); this.bgX.stroke(); }
      }
    };
    if (this.drawing.background_data) {
      const img = new Image();
      img.onload = () => {
        const s = Math.min(w / img.width, h / img.height);
        this.bgX.drawImage(img, (w - img.width * s) / 2, (h - img.height * s) / 2, img.width * s, img.height * s);
        paint();
      };
      img.src = this.drawing.background_data;
    } else paint();
  }

  _opt() { return { scaleRatio: this.drawing.scale_ratio, units: this.drawing.units }; }
  _render() {
    this.inkX.clearRect(0, 0, this.drawing.width, this.drawing.height);
    for (const it of this.items) { if (this._layerVisible(it)) drawItem(this.inkX, it, this._opt()); }
    this._drawSelection();
  }
  _drawSelection() {
    if (!this.selection.size) return;
    const ctx = this.inkX; ctx.save();
    ctx.strokeStyle = "#3ba7ff"; ctx.lineWidth = 1 / this.view.scale; ctx.setLineDash([6 / this.view.scale, 4 / this.view.scale]);
    for (const it of this.items) {
      if (!this.selection.has(it.id)) continue;
      const b = this._bbox(it); if (!b) continue;
      ctx.strokeRect(b.x - 4, b.y - 4, b.w + 8, b.h + 8);
    }
    ctx.restore();
  }
  _clearLive() { this.liveX.clearRect(0, 0, this.drawing.width, this.drawing.height); }

  /* ---- geometry / hit-test ---- */
  _bbox(it) {
    const t = it.type;
    if (FREE.has(t) || t === "area" || t === "angle") {
      const p = it.points || it.pts || []; if (!p.length) return null;
      let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
      for (const q of p) { x0 = Math.min(x0, q.x); y0 = Math.min(y0, q.y); x1 = Math.max(x1, q.x); y1 = Math.max(y1, q.y); }
      return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    }
    if (SEG.has(t)) return { x: Math.min(it.x, it.x2), y: Math.min(it.y, it.y2), w: Math.abs(it.x2 - it.x), h: Math.abs(it.y2 - it.y) };
    if (BOX.has(t)) return { x: Math.min(it.x, it.x + it.width), y: Math.min(it.y, it.y + it.height), w: Math.abs(it.width), h: Math.abs(it.height) };
    if (t === "balloon") return { x: it.x - 15, y: it.y - 15, w: 30, h: 30 };
    if (t === "pin") return { x: it.x - 10, y: it.y - 24, w: 20, h: 26 };
    if (t === "note") { const n = (it.text || "").split("\n").length; return { x: it.x, y: it.y, w: 196, h: 14 + n * 18 }; }
    if (t === "stamp") return { x: it.x, y: it.y, w: 120, h: 28 };
    if (t === "text") { const fs = it.font_size || 16; const lines = (it.text || " ").split("\n"); const w = Math.max(...lines.map((l) => l.length)) * fs * 0.6; return { x: it.x, y: it.y, w: Math.max(20, w), h: lines.length * fs * 1.25 + 4 }; }
    return null;
  }
  _segDist(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1, dy = y2 - y1, L2 = dx * dx + dy * dy;
    let t = L2 ? ((px - x1) * dx + (py - y1) * dy) / L2 : 0; t = clamp(t, 0, 1);
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
  }
  _hit(it, p, tol) {
    const t = it.type;
    if (FREE.has(t)) { const q = it.points || []; for (let i = 1; i < q.length; i++) if (this._segDist(p.x, p.y, q[i - 1].x, q[i - 1].y, q[i].x, q[i].y) < tol + (it.width || 3) / 2) return true; return q.length === 1 && Math.hypot(p.x - q[0].x, p.y - q[0].y) < tol + (it.width || 3); }
    if (SEG.has(t)) return this._segDist(p.x, p.y, it.x, it.y, it.x2, it.y2) < tol + (it.stroke_w || 2);
    if (t === "angle") { const q = it.pts || []; return q.length >= 3 && (this._segDist(p.x, p.y, q[0].x, q[0].y, q[1].x, q[1].y) < tol + 3 || this._segDist(p.x, p.y, q[1].x, q[1].y, q[2].x, q[2].y) < tol + 3); }
    const b = this._bbox(it); if (!b) return false;
    return p.x >= b.x - tol && p.x <= b.x + b.w + tol && p.y >= b.y - tol && p.y <= b.y + b.h + tol;
  }
  _hitTest(p) {
    const tol = 8 / this.view.scale;
    for (let i = this.items.length - 1; i >= 0; i--) {
      const it = this.items[i];
      if (!this._layerVisible(it) || this._layerLocked(it)) continue;
      if (this._hit(it, p, tol)) return it;
    }
    return null;
  }
  _translate(it, dx, dy) {
    if (it.points) it.points = it.points.map((q) => ({ ...q, x: q.x + dx, y: q.y + dy }));
    else if (it.pts) it.pts = it.pts.map((q) => ({ x: q.x + dx, y: q.y + dy }));
    else { it.x += dx; it.y += dy; if (it.x2 != null) { it.x2 += dx; it.y2 += dy; } }
  }

  /* ---- events ---- */
  _activePressure(e) { return e.pointerType === "pen" && e.pressure > 0 ? e.pressure : 0.5; }
  _wire() {
    $$(".tool[data-tool]", this.c).forEach((b) => b.addEventListener("click", () => this._setTool(b.dataset.tool)));
    $$(".tool[data-act]", this.c).forEach((b) => b.addEventListener("click", () => this._action(b.dataset.act)));
    $("[data-image-file]", this.c).addEventListener("change", (e) => this._loadImage(e));
    $("[data-note-cancel]", this.c).addEventListener("click", () => this._closeNote());
    $("[data-note-save]", this.c).addEventListener("click", () => this._saveNote());
    this.stage.addEventListener("pointerdown", (e) => this._down(e));
    this.stage.addEventListener("pointermove", (e) => this._move(e));
    this.stage.addEventListener("pointerup", (e) => this._up(e));
    this.stage.addEventListener("pointercancel", (e) => this._up(e));
    this.stage.addEventListener("dblclick", (e) => this._dbl(e));
    this.stage.addEventListener("wheel", (e) => this._wheel(e), { passive: false });
  }

  _setTool(t) {
    this.tool = t;
    if (this.pending) { this.pending = null; this._clearLive(); }
    $$(".tool[data-tool]", this.c).forEach((b) => b.classList.toggle("active", b.dataset.tool === t));
    this.stage.style.cursor = t === "pan" ? "grab" : t === "select" ? "default" : "crosshair";
    this._renderPanel();
  }

  _down(e) {
    if (this.opts.readOnly) return;
    this.stage.setPointerCapture(e.pointerId); this.pointers.set(e.pointerId, e); this.shift = e.shiftKey;
    if (this.pointers.size === 2) { this.draft = null; this._clearLive(); this._startPinch(); return; }
    const w = this._snap(this._toWorld(e.clientX, e.clientY)), t = this.tool;

    if (t === "pan") { this.panStart = { x: e.clientX, y: e.clientY, tx: this.view.tx, ty: this.view.ty }; return; }
    if (t === "select") return this._selectDown(w, e);
    if (t === "note" || t === "text") { this._openNote(e.clientX, e.clientY, w, t); return; }
    if (t === "pin") return this._commit({ type: "pin", kind: "pin", x: w.x, y: w.y, color: this.color });
    if (t === "stamp") return this._commit({ type: "stamp", kind: "stamp", x: w.x, y: w.y, color: this.color, meta: { kind: "fai" }, text: "" });
    if (t === "balloon") { const n = this.items.filter((i) => i.type === "balloon").length + 1; return this._commit({ type: "balloon", kind: "balloon", x: w.x, y: w.y, color: this.color, meta: { number: n } }); }
    if (t === "angle" || t === "area") return this._polyDown(w);

    if (FREE.has(t)) {
      this.draft = { id: uid(), client_uid: uid(), drawing_id: this.drawing.id, layer_id: this.activeLayerId, type: t, tool: t, color: this.color, width: this.size, opacity: this.opacity, points: [{ x: w.x, y: w.y, p: this._activePressure(e) }] };
    } else {
      this.draft = { id: uid(), client_uid: uid(), drawing_id: this.drawing.id, layer_id: this.activeLayerId, type: t, kind: t, x: w.x, y: w.y, x2: w.x, y2: w.y, width: 0, height: 0, color: this.color, stroke_w: this.size, opacity: this.opacity };
    }
  }

  _move(e) {
    if (this.pointers.has(e.pointerId)) this.pointers.set(e.pointerId, e);
    this.shift = e.shiftKey;
    const w = this._toWorld(e.clientX, e.clientY);
    this._status(w);
    if (this.pinch && this.pointers.size === 2) return this._doPinch();
    if (this.panStart) { this.view.tx = this.panStart.tx + (e.clientX - this.panStart.x); this.view.ty = this.panStart.ty + (e.clientY - this.panStart.y); return this._applyTransform(); }
    if (this.moving) return this._selectMove(this._snap(w));
    if (this.marquee) return this._marqueeMove(w);
    if (this.pending) return this._polyPreview(this._snap(w));
    if (!this.draft) return;
    const sw = this._snap(w);
    if (this.draft.points) {
      const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      for (const ce of evs) { const cw = this._snap(this._toWorld(ce.clientX, ce.clientY)); this.draft.points.push({ x: cw.x, y: cw.y, p: this._activePressure(ce) }); }
    } else {
      let x2 = sw.x, y2 = sw.y;
      if (this.shift && SEG.has(this.draft.type)) { if (Math.abs(x2 - this.draft.x) > Math.abs(y2 - this.draft.y)) y2 = this.draft.y; else x2 = this.draft.x; }
      if (this.shift && BOX.has(this.draft.type)) { const d = Math.max(Math.abs(x2 - this.draft.x), Math.abs(y2 - this.draft.y)); x2 = this.draft.x + Math.sign(x2 - this.draft.x) * d; y2 = this.draft.y + Math.sign(y2 - this.draft.y) * d; }
      this.draft.x2 = x2; this.draft.y2 = y2; this.draft.width = x2 - this.draft.x; this.draft.height = y2 - this.draft.y;
    }
    this._clearLive(); drawItem(this.liveX, this.draft, this._opt());
  }

  _up(e) {
    this.pointers.delete(e.pointerId); if (this.pointers.size < 2) this.pinch = null;
    if (this.panStart) { this.panStart = null; return; }
    if (this.moving) { this._clearLive(); if (this.moving.moved) { this._syncStore(); } this.moving = null; this._render(); this._renderPanel(); return; }
    if (this.marquee) return this._marqueeUp();
    if (!this.draft) return;
    const d = this.draft; this.draft = null; this._clearLive();
    if (d._calib) { delete d._calib; return this._finishCalibration(d); }
    if (FREE.has(d.type) && d.points.length < 1) return;
    if (SEG.has(d.type) && Math.hypot(d.x2 - d.x, d.y2 - d.y) < 2) return;
    this._commit(d);
  }

  _commit(it) {
    it.id = it.id || uid(); it.client_uid = it.client_uid || uid(); it.drawing_id = this.drawing.id;
    it.layer_id = it.layer_id || this.activeLayerId;
    this._push(); this.items.push(it); this._persist(it); this._render();
  }

  /* ---- selection ---- */
  _selectDown(w, e) {
    const hit = this._hitTest(w);
    if (hit) {
      if (e.shiftKey) { this.selection.has(hit.id) ? this.selection.delete(hit.id) : this.selection.add(hit.id); }
      else if (!this.selection.has(hit.id)) { this.selection.clear(); this.selection.add(hit.id); }
      this._push();
      this.moving = { last: w, moved: false };
      this._render(); this._renderPanel();
    } else {
      if (!e.shiftKey) this.selection.clear();
      this.marquee = { x0: w.x, y0: w.y, x1: w.x, y1: w.y };
      this._render(); this._renderPanel();
    }
  }
  _selectMove(w) {
    const dx = w.x - this.moving.last.x, dy = w.y - this.moving.last.y;
    if (!dx && !dy) return;
    this.moving.last = w; this.moving.moved = true;
    for (const it of this.items) if (this.selection.has(it.id) && !this._layerLocked(it)) this._translate(it, dx, dy);
    this._render();
  }
  _marqueeMove(w) {
    this.marquee.x1 = w.x; this.marquee.y1 = w.y;
    this._clearLive();
    const m = this.marquee, x = Math.min(m.x0, m.x1), y = Math.min(m.y0, m.y1), ww = Math.abs(m.x1 - m.x0), hh = Math.abs(m.y1 - m.y0);
    this.liveX.save(); this.liveX.strokeStyle = "#3ba7ff"; this.liveX.fillStyle = "rgba(59,167,255,.1)";
    this.liveX.lineWidth = 1 / this.view.scale; this.liveX.fillRect(x, y, ww, hh); this.liveX.strokeRect(x, y, ww, hh); this.liveX.restore();
  }
  _marqueeUp() {
    const m = this.marquee; this.marquee = null; this._clearLive();
    const x = Math.min(m.x0, m.x1), y = Math.min(m.y0, m.y1), x2 = Math.max(m.x0, m.x1), y2 = Math.max(m.y0, m.y1);
    if (Math.hypot(x2 - x, y2 - y) > 4) {
      for (const it of this.items) {
        if (!this._layerVisible(it) || this._layerLocked(it)) continue;
        const b = this._bbox(it); if (!b) continue;
        if (b.x >= x && b.y >= y && b.x + b.w <= x2 && b.y + b.h <= y2) this.selection.add(it.id);
      }
    }
    this._render(); this._renderPanel();
  }
  _selected() { return this.items.filter((i) => this.selection.has(i.id)); }
  async _deleteSelection() {
    if (!this.selection.size) return;
    this._push();
    const sel = this._selected();
    for (const it of sel) await this._del(it);
    this.items = this.items.filter((i) => !this.selection.has(i.id));
    this.selection.clear(); this._dirty(); this._render(); this._renderPanel();
  }
  _duplicateSelection() {
    if (!this.selection.size) return;
    this._push();
    const clones = this._selected().map((it) => { const c = JSON.parse(JSON.stringify(it)); c.id = uid(); c.client_uid = uid(); this._translate(c, 20, 20); return c; });
    this.selection.clear();
    for (const c of clones) { this.items.push(c); this._persist(c); this.selection.add(c.id); }
    this._render(); this._renderPanel();
  }
  _zorder(dir) {
    if (!this.selection.size) return;
    this._push();
    const sel = this._selected();
    this.items = this.items.filter((i) => !this.selection.has(i.id));
    if (dir === "front") this.items.push(...sel); else this.items.unshift(...sel);
    this._syncStore(); this._render();
  }

  /* ---- polygon tools (angle / area) ---- */
  _polyDown(w) {
    if (!this.pending) this.pending = { type: this.tool, pts: [] };
    if (this.tool === "area" && this.pending.pts.length >= 3) {
      const f = this.pending.pts[0];
      if (Math.hypot(w.x - f.x, w.y - f.y) < 10 / this.view.scale) return this._polyFinish();
    }
    this.pending.pts.push({ x: w.x, y: w.y });
    if (this.tool === "angle" && this.pending.pts.length === 3) this._polyFinish();
  }
  _polyPreview(w) {
    if (!this.pending) return;
    const pts = [...this.pending.pts, w];
    this._clearLive();
    drawItem(this.liveX, { type: this.pending.type, kind: this.pending.type, pts, color: this.color, stroke_w: this.size }, this._opt());
  }
  _polyFinish() {
    const p = this.pending; this.pending = null; this._clearLive();
    if (!p || p.pts.length < (p.type === "angle" ? 3 : 3)) return;
    this._commit({ type: p.type, kind: p.type, pts: p.pts, color: this.color, stroke_w: this.size, opacity: this.opacity });
  }

  /* ---- pinch / wheel ---- */
  _startPinch() {
    const [p1, p2] = [...this.pointers.values()];
    this.pinch = { dist: Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY), cx: (p1.clientX + p2.clientX) / 2, cy: (p1.clientY + p2.clientY) / 2, scale: this.view.scale, tx: this.view.tx, ty: this.view.ty };
  }
  _doPinch() {
    const [p1, p2] = [...this.pointers.values()];
    const dist = Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY);
    const cx = (p1.clientX + p2.clientX) / 2, cy = (p1.clientY + p2.clientY) / 2;
    const r = this.stage.getBoundingClientRect();
    const ns = clamp(this.pinch.scale * (dist / this.pinch.dist), 0.08, 12);
    const wx = (this.pinch.cx - r.left - this.pinch.tx) / this.pinch.scale, wy = (this.pinch.cy - r.top - this.pinch.ty) / this.pinch.scale;
    this.view.scale = ns; this.view.tx = cx - r.left - wx * ns; this.view.ty = cy - r.top - wy * ns; this._applyTransform();
  }
  _wheel(e) {
    e.preventDefault();
    const r = this.stage.getBoundingClientRect();
    const ns = clamp(this.view.scale * (e.deltaY < 0 ? 1.1 : 0.9), 0.08, 12);
    const wx = (e.clientX - r.left - this.view.tx) / this.view.scale, wy = (e.clientY - r.top - this.view.ty) / this.view.scale;
    this.view.scale = ns; this.view.tx = e.clientX - r.left - wx * ns; this.view.ty = e.clientY - r.top - wy * ns; this._applyTransform();
  }

  /* ---- notes / text editor ---- */
  _openNote(cx, cy, world, kind) {
    this._noteWorld = world; this._noteKind = kind; this._editing = null;
    const ed = $("[data-note-editor]", this.c);
    ed.style.left = clamp(cx, 8, window.innerWidth - 300) + "px";
    ed.style.top = clamp(cy, 70, window.innerHeight - 200) + "px";
    ed.classList.remove("hidden");
    const ta = $("[data-note-text]", this.c); ta.value = ""; ta.focus();
  }
  _editText(it) {
    this._editing = it; this._noteKind = it.type;
    const ed = $("[data-note-editor]", this.c);
    const b = this.stage.getBoundingClientRect();
    ed.style.left = clamp(b.left + 60, 8, window.innerWidth - 300) + "px";
    ed.style.top = clamp(b.top + 60, 70, window.innerHeight - 200) + "px";
    ed.classList.remove("hidden");
    const ta = $("[data-note-text]", this.c); ta.value = it.text || ""; ta.focus();
  }
  _closeNote() { $("[data-note-editor]", this.c).classList.add("hidden"); this._noteWorld = null; this._editing = null; }
  _saveNote() {
    const text = $("[data-note-text]", this.c).value;
    if (this._editing) { this._push(); this._editing.text = text; this._persist(this._editing); this._render(); }
    else if (text.trim() && this._noteWorld) {
      const k = this._noteKind || "note";
      this._commit({ type: k, kind: k, x: this._noteWorld.x, y: this._noteWorld.y, text: text.trim(), color: this.color, font_size: 16 });
    }
    this._closeNote();
  }
  _dbl(e) {
    const w = this._toWorld(e.clientX, e.clientY);
    if (this.tool === "area" && this.pending) return this._polyFinish();
    const hit = this._hitTest(w);
    if (hit && ["text", "note", "stamp"].includes(hit.type)) this._editText(hit);
  }

  /* ---- status bar ---- */
  _status(w) {
    const pos = $("[data-st-pos]", this.c); if (pos) pos.textContent = `x ${Math.round(w.x)}, y ${Math.round(w.y)}`;
    const real = $("[data-st-real]", this.c);
    if (real) real.textContent = this.drawing.scale_ratio ? `${(w.x * this.drawing.scale_ratio).toFixed(2)}, ${(w.y * this.drawing.scale_ratio).toFixed(2)} ${this.drawing.units || "in"}` : "";
  }
  _statusSel() {
    const s = $("[data-st-sel]", this.c); if (s) s.textContent = `${this.selection.size} selected`;
    const l = $("[data-st-layer]", this.c); const lay = this.layerById(this.activeLayerId); if (l && lay) l.textContent = "▣ " + lay.name;
  }

  /* ---- right panel ---- */
  _renderPanel() {
    this._statusSel();
    const sel = this._selected();
    let html = "";
    if (this.tool === "select" && sel.length) {
      html += this._inspectorHTML(sel);
    } else {
      html += this._propsHTML();
    }
    html += this._layersHTML();
    this.panel.innerHTML = html;
    this._wirePanel();
  }
  _propsHTML() {
    return `<div class="panel-section">
      <div class="panel-title">Tool</div>
      <div class="prop-group"><div class="prop-label">Color</div>
        <div class="swatches">${SWATCHES.map((s) => `<button class="swatch ${s === this.color ? "active" : ""}" style="background:${s}" data-color="${s}"></button>`).join("")}</div>
        <input type="color" class="input" data-color-input value="${this.color}"></div>
      <div class="prop-group"><div class="prop-label">Size <span data-size-val>${this.size}</span>px</div>
        <input type="range" class="range" data-size min="1" max="40" value="${this.size}"></div>
      <div class="prop-group"><div class="prop-label">Opacity <span data-op-val>${Math.round(this.opacity * 100)}</span>%</div>
        <input type="range" class="range" data-op min="10" max="100" value="${Math.round(this.opacity * 100)}"></div>
      <div class="prop-group"><div class="prop-label">Scale</div>
        <div class="mono" style="font-size:.78rem">${this.drawing.scale_ratio ? "1px = " + this.drawing.scale_ratio.toFixed(4) + " " + (this.drawing.units || "in") : "uncalibrated"}</div></div>
    </div>`;
  }
  _inspectorHTML(sel) {
    const one = sel.length === 1 ? sel[0] : null;
    const stamp = one && one.type === "stamp";
    const balloon = one && one.type === "balloon";
    return `<div class="panel-section">
      <div class="panel-title">Selection · ${sel.length}</div>
      <div class="prop-group"><div class="prop-label">Color</div>
        <div class="swatches">${SWATCHES.map((s) => `<button class="swatch" style="background:${s}" data-sel-color="${s}"></button>`).join("")}</div></div>
      <div class="prop-group"><div class="prop-label">Width</div>
        <input type="range" class="range" data-sel-width min="1" max="40" value="${one ? (one.stroke_w || one.width || 2) : 2}"></div>
      <div class="prop-group"><div class="prop-label">Opacity</div>
        <input type="range" class="range" data-sel-op min="10" max="100" value="${one ? Math.round((one.opacity ?? 1) * 100) : 100}"></div>
      ${stamp ? `<div class="prop-group"><div class="prop-label">Stamp</div>
        <select class="select" data-stamp-kind>${["fai", "accept", "reject"].map((k) => `<option value="${k}" ${one.meta && one.meta.kind === k ? "selected" : ""}>${k.toUpperCase()}</option>`).join("")}</select></div>` : ""}
      ${balloon ? `<div class="prop-group"><div class="prop-label">Balloon #</div>
        <input class="input" data-balloon-num value="${esc(String(one.meta && one.meta.number || ""))}"></div>` : ""}
      <div class="insp-actions">
        <button class="btn btn-ghost btn-sm" data-dup>${icon("copy", 14)} Duplicate</button>
        <button class="btn btn-ghost btn-sm" data-front>${icon("front", 14)}</button>
        <button class="btn btn-ghost btn-sm" data-back>${icon("back", 14)}</button>
        <button class="btn btn-danger btn-sm" data-del>${icon("trash", 14)} Delete</button>
      </div>
    </div>`;
  }
  _layersHTML() {
    return `<div class="panel-section">
      <div class="panel-title">Layers <button class="btn-icon" data-add-layer title="Add layer">${icon("plus", 16)}</button></div>
      <div class="layers-panel">
        ${[...this.layers].reverse().map((l) => `<div class="layer-row ${l.id === this.activeLayerId ? "active" : ""} ${l.visible ? "" : "hidden-layer"}" data-layer="${l.id}">
          <button class="layer-vis" data-vis="${l.id}" title="Visibility">${icon(l.visible ? "eye" : "eye", 15)}</button>
          <span class="layer-color" style="background:${esc(l.color || "#ff5811")}"></span>
          <span class="layer-name" data-name="${l.id}">${esc(l.name)}</span>
          <button class="layer-lock ${l.locked ? "on" : ""}" data-lock="${l.id}" title="Lock">${icon("lock", 14)}</button>
        </div>`).join("")}
      </div>
    </div>`;
  }
  _wirePanel() {
    $$("[data-color]", this.panel).forEach((b) => b.addEventListener("click", () => { this.color = b.dataset.color; this._renderPanel(); }));
    const ci = $("[data-color-input]", this.panel); ci && ci.addEventListener("input", (e) => (this.color = e.target.value));
    const sz = $("[data-size]", this.panel); sz && sz.addEventListener("input", (e) => { this.size = +e.target.value; $("[data-size-val]", this.panel).textContent = e.target.value; });
    const op = $("[data-op]", this.panel); op && op.addEventListener("input", (e) => { this.opacity = +e.target.value / 100; $("[data-op-val]", this.panel).textContent = e.target.value; });

    // selection edits
    $$("[data-sel-color]", this.panel).forEach((b) => b.addEventListener("click", () => this._applySel((it) => (it.color = b.dataset.selColor))));
    const sw = $("[data-sel-width]", this.panel); sw && sw.addEventListener("change", (e) => this._applySel((it) => { if (it.width != null) it.width = +e.target.value; else it.stroke_w = +e.target.value; }));
    const so = $("[data-sel-op]", this.panel); so && so.addEventListener("change", (e) => this._applySel((it) => (it.opacity = +e.target.value / 100)));
    const sk = $("[data-stamp-kind]", this.panel); sk && sk.addEventListener("change", (e) => this._applySel((it) => { it.meta = { ...(it.meta || {}), kind: e.target.value }; }));
    const bn = $("[data-balloon-num]", this.panel); bn && bn.addEventListener("change", (e) => this._applySel((it) => { it.meta = { ...(it.meta || {}), number: e.target.value }; }));
    const dup = $("[data-dup]", this.panel); dup && dup.addEventListener("click", () => this._duplicateSelection());
    const del = $("[data-del]", this.panel); del && del.addEventListener("click", () => this._deleteSelection());
    const fr = $("[data-front]", this.panel); fr && fr.addEventListener("click", () => this._zorder("front"));
    const bk = $("[data-back]", this.panel); bk && bk.addEventListener("click", () => this._zorder("back"));

    // layers
    const addL = $("[data-add-layer]", this.panel); addL && addL.addEventListener("click", () => this._addLayer());
    $$("[data-layer]", this.panel).forEach((r) => r.addEventListener("click", (e) => { if (e.target.closest("[data-vis],[data-lock]")) return; this.activeLayerId = r.dataset.layer; this._renderPanel(); }));
    $$("[data-vis]", this.panel).forEach((b) => b.addEventListener("click", (e) => { e.stopPropagation(); this._toggleLayer(b.dataset.vis, "visible"); }));
    $$("[data-lock]", this.panel).forEach((b) => b.addEventListener("click", (e) => { e.stopPropagation(); this._toggleLayer(b.dataset.lock, "locked"); }));
    $$("[data-name]", this.panel).forEach((s) => s.addEventListener("dblclick", () => { const l = this.layerById(s.dataset.name); const n = prompt("Layer name:", l.name); if (n) { l.name = n; put("layers", l); this._renderPanel(); } }));
  }
  _applySel(fn) { this._push(); for (const it of this._selected()) { fn(it); this._persist(it); } this._render(); }
  async _addLayer() {
    const l = { id: uid(), client_uid: uid(), drawing_id: this.drawing.id, name: "Layer " + (this.layers.length + 1), color: SWATCHES[this.layers.length % SWATCHES.length], visible: true, locked: false, z_index: this.layers.length };
    await put("layers", l); this.layers.push(l); this.activeLayerId = l.id; this._renderPanel();
  }
  _toggleLayer(id, prop) { const l = this.layerById(id); if (!l) return; l[prop] = !l[prop]; put("layers", l); this._render(); this._renderPanel(); }

  /* ---- toolbar actions ---- */
  async _action(act) {
    if (act === "undo") return this._undo();
    if (act === "redo") return this._redo();
    if (act === "fit") return this.fit();
    if (act === "image") return $("[data-image-file]", this.c).click();
    if (act === "export") return this.exportPNG();
    if (act === "calibrate") return this._beginCalibration();
    if (act === "help") return this._showShortcuts();
    if (act === "grid") { this.grid = !this.grid; $('[data-act="grid"]', this.c).classList.toggle("active", this.grid); this._renderBg(); return; }
    if (act === "snap") { this.snap = !this.snap; $('[data-act="snap"]', this.c).classList.toggle("active", this.snap); return; }
    if (act === "clear") return this._clear();
  }
  _beginCalibration() {
    this._setTool("measure"); this._calib = true;
    toast("Calibration: draw a line over a known dimension.", "info");
    const onceDown = () => { if (this.draft) this.draft._calib = true; };
    this.stage.addEventListener("pointerdown", onceDown, { once: true });
  }
  async _finishCalibration(line) {
    const px = Math.hypot(line.x2 - line.x, line.y2 - line.y); this._calib = false;
    if (px < 4) return;
    const val = prompt("Real length of that line (e.g. 12):"); if (!val) return;
    const unit = prompt("Units (in, mm, cm, ft):", this.drawing.units || "in") || "in";
    const real = parseFloat(val); if (!real || real <= 0) return;
    this.drawing.scale_ratio = real / px; this.drawing.units = unit;
    await put("drawings", this.drawing); this._dirty(); this._render(); this._renderPanel();
    toast(`Scale set: 1px = ${this.drawing.scale_ratio.toFixed(4)} ${unit}`, "success");
  }
  _loadImage(e) {
    const f = e.target.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = async () => {
        this.drawing.width = Math.max(1200, img.width); this.drawing.height = Math.max(900, img.height);
        this.drawing.background_kind = "image"; this.drawing.background_data = reader.result; this.drawing.background_name = f.name;
        await put("drawings", this.drawing); this._dirty();
        this._sizeCanvases(); this._renderBg(); this._render(); this.fit();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(f); e.target.value = "";
  }
  async _clear() {
    if (!confirm("Clear all markup on this drawing? The background image is kept.")) return;
    this._push();
    for (const it of this.items) await this._del(it);
    this.items = []; this.selection.clear(); this._dirty(); this._render(); this._renderPanel();
  }
  exportPNG() {
    const sel = this.selection; this.selection = new Set(); this._render(); // clean (no selection outlines)
    const out = document.createElement("canvas");
    out.width = this.drawing.width; out.height = this.drawing.height;
    const o = out.getContext("2d");
    o.drawImage(this.bg, 0, 0, out.width, out.height);
    o.drawImage(this.ink, 0, 0, out.width, out.height);
    this.selection = sel; this._render();
    const a = document.createElement("a");
    a.download = `${this.drawing.drawing_number || this.drawing.title || "aeromarkup"}_rev${this.drawing.revision || "A"}.png`;
    a.href = out.toDataURL("image/png"); a.click();
  }
  _showShortcuts() {
    const rows = [
      ["V", "Select / move"], ["P / H / E", "Pen / highlighter / eraser"], ["L / A", "Line / arrow"],
      ["R / O / C", "Rect / ellipse / cloud"], ["T / N", "Text / note"], ["M / S / B", "Pin / stamp / balloon"],
      ["D / G / Z", "Linear / angle / area measure"], ["Space", "Pan"], ["#", "Toggle grid"],
      ["Ctrl/⌘ Z", "Undo"], ["Ctrl/⌘ Y", "Redo"], ["Ctrl/⌘ D", "Duplicate selection"],
      ["Delete", "Delete selection"], ["Esc", "Cancel / deselect"], ["F", "Fit to screen"], ["?", "This help"],
    ];
    const ov = el(`<div class="shortcuts-overlay"><div class="shortcuts-card">
      <h2>Keyboard Shortcuts</h2>
      <div class="sc-grid">${rows.map(([k, d]) => `<div class="sc-row"><span class="sc-desc">${esc(d)}</span><span class="sc-key">${esc(k)}</span></div>`).join("")}</div>
      <div class="modal-foot" style="margin-top:16px"><button class="btn btn-primary" data-close>Close</button></div>
    </div></div>`);
    ov.addEventListener("mousedown", (e) => { if (e.target === ov || e.target.closest("[data-close]")) ov.remove(); });
    document.body.appendChild(ov);
  }

  /* keyboard (module-level dispatch to CURRENT) */
  handleKey(e) {
    if (e.target.tagName === "TEXTAREA" || e.target.tagName === "INPUT" || e.target.tagName === "SELECT") return;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === "z") { e.preventDefault(); return this._undo(); }
    if (mod && e.key.toLowerCase() === "y") { e.preventDefault(); return this._redo(); }
    if (mod && e.key.toLowerCase() === "d") { e.preventDefault(); return this._duplicateSelection(); }
    if (mod) return;
    if (e.key === "Delete" || e.key === "Backspace") { e.preventDefault(); return this._deleteSelection(); }
    if (e.key === "Escape") { this.pending = null; this.selection.clear(); this._clearLive(); this._render(); this._renderPanel(); return; }
    if (e.key === "Enter" && this.pending) return this._polyFinish();
    if (e.key === " ") { this._setTool("pan"); return; }
    if (e.key === "#") return this._action("grid");
    if (e.key === "?") return this._showShortcuts();
    const map = { v: "select", p: "pen", h: "highlighter", e: "eraser", l: "line", a: "arrow", r: "rect", o: "ellipse", c: "cloud", t: "text", n: "note", m: "pin", s: "stamp", b: "balloon", d: "measure", g: "angle", z: "area", f: null };
    const k = e.key.toLowerCase();
    if (k === "f") return this.fit();
    if (map[k] !== undefined && map[k] !== null) this._setTool(map[k]);
  }

  hasMarkup() { return this.items.length; }
}

/* one global keydown → current editor */
window.addEventListener("keydown", (e) => { if (CURRENT && document.body.contains(CURRENT.c)) CURRENT.handleKey(e); });
