/* AeroMarkup — engineering markup engine.
   Pointer Events (mouse / touch / pressure stylus), pan + pinch-zoom,
   pen/highlighter/eraser, arrows, shapes, notes, pins, and dimensioned
   measurements with real-world scale calibration. Vector storage so markup
   stays crisp on tablets. All edits persist to IndexedDB immediately. */
import { put, byIndex, del, uid } from "./store.js";
import { icon } from "./icons.js";
import { $, $$, el, toast, esc } from "./ui.js";

const DPR = Math.min(window.devicePixelRatio || 1, 2);
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

const TOOLS = [
  ["pen", "Pen / sketch", "pen"], ["highlighter", "Highlighter", "highlighter"],
  ["eraser", "Eraser", "eraser"], ["arrow", "Pointer / arrow", "arrow"],
  ["line", "Line", "line"], ["rect", "Rectangle", "rect"],
  ["ellipse", "Ellipse", "ellipse"], ["measure", "Measure", "measure"],
  ["note", "Note / callout", "note"], ["pin", "Pin marker", "pin"],
  ["pan", "Pan / move", "pan"],
];
const SWATCHES = ["#ff5811", "#ffcc00", "#3ad07a", "#3ba7ff", "#ff4d4d", "#ffffff", "#b06cff", "#00e5d0"];

export class Editor {
  constructor(container, drawing, opts = {}) {
    this.c = container;
    this.drawing = drawing;
    this.opts = opts;             // { onDirty, readOnly }
    this.tool = "pen";
    this.color = "#ff5811";
    this.size = 3;
    this.opacity = 1;
    this.view = { scale: 1, tx: 0, ty: 0 };
    this.strokes = [];
    this.annotations = [];
    this.undoStack = [];
    this.redoStack = [];
    this.pointers = new Map();
    this.pinch = null;
    this.panStart = null;
    this.draft = null;
    this.calibrating = false;
  }

  async mount() {
    this.c.innerHTML = `
      <div class="editor" style="height:100%;width:100%">
        <div class="tool-rail">
          ${TOOLS.map(([t, label, ic]) => `<button class="tool" data-tool="${t}" title="${label}">${icon(ic, 20)}</button>`).join("")}
          <div class="tool-sep"></div>
          <button class="tool" data-act="undo" title="Undo">${icon("undo", 20)}</button>
          <button class="tool" data-act="redo" title="Redo">${icon("redo", 20)}</button>
          <button class="tool" data-act="fit"  title="Fit">${icon("fit", 20)}</button>
          <button class="tool" data-act="image" title="Load image">${icon("image", 20)}</button>
          <button class="tool" data-act="calibrate" title="Calibrate scale">${icon("measure", 20)}</button>
          <button class="tool" data-act="export" title="Export PNG">${icon("export", 20)}</button>
          <div class="tool-sep"></div>
          <button class="tool tool-danger" data-act="clear" title="Clear markup">${icon("trash", 20)}</button>
        </div>

        <div class="stage" tabindex="0">
          <canvas class="layer" data-c="bg"></canvas>
          <canvas class="layer" data-c="ink"></canvas>
          <canvas class="layer" data-c="live"></canvas>
          <div class="anno-layer"></div>
          <div class="zoom-readout">100%</div>
        </div>

        <div class="props-panel">
          <div class="prop-group">
            <div class="prop-label">Color</div>
            <div class="swatches">${SWATCHES.map((s) => `<button class="swatch" style="background:${s}" data-color="${s}"></button>`).join("")}</div>
            <input type="color" class="input" data-color-input value="#ff5811" />
          </div>
          <div class="prop-group">
            <div class="prop-label">Size <span data-size-val>3</span>px</div>
            <input type="range" class="range" data-size min="1" max="40" value="3" />
          </div>
          <div class="prop-group">
            <div class="prop-label">Opacity <span data-op-val>100</span>%</div>
            <input type="range" class="range" data-op min="10" max="100" value="100" />
          </div>
          <div class="prop-group">
            <div class="prop-label">Scale</div>
            <div class="mono" data-scale>uncalibrated</div>
          </div>
        </div>

        <input type="file" accept="image/*" data-image-file hidden />
        <div class="note-editor hidden" data-note-editor>
          <textarea class="textarea" data-note-text placeholder="Type a note…"></textarea>
          <div class="modal-foot">
            <button class="btn btn-ghost btn-sm" data-note-cancel>Cancel</button>
            <button class="btn btn-primary btn-sm" data-note-save>Add note</button>
          </div>
        </div>
      </div>`;

    this.stage = $(".stage", this.c);
    this.bg = $('[data-c="bg"]', this.c); this.bgX = this.bg.getContext("2d");
    this.ink = $('[data-c="ink"]', this.c); this.inkX = this.ink.getContext("2d");
    this.live = $('[data-c="live"]', this.c); this.liveX = this.live.getContext("2d");
    this.annoLayer = $(".anno-layer", this.c);

    this.strokes = await byIndex("strokes", "drawing_id", this.drawing.id);
    this.annotations = await byIndex("annotations", "drawing_id", this.drawing.id);

    this._wire();
    this._setTool("pen");
    this._sizeCanvases();
    this._renderBg();
    this._renderAll();
    this._updateScaleLabel();
    requestAnimationFrame(() => this.fit());
  }

  /* ---- coordinate transforms ---- */
  _applyTransform() {
    const { scale, tx, ty } = this.view;
    const t = `translate(${tx}px,${ty}px) scale(${scale})`;
    [this.bg, this.ink, this.live, this.annoLayer].forEach((e) => (e.style.transform = t));
    $(".zoom-readout", this.c).textContent = Math.round(scale * 100) + "%";
  }
  _toWorld(cx, cy) {
    const r = this.stage.getBoundingClientRect();
    const { scale, tx, ty } = this.view;
    return { x: (cx - r.left - tx) / scale, y: (cy - r.top - ty) / scale };
  }
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
      cv.width = w * DPR; cv.height = h * DPR;
      cv.style.width = w + "px"; cv.style.height = h + "px";
      cv.getContext("2d").setTransform(DPR, 0, 0, DPR, 0, 0);
    });
    this.annoLayer.style.width = w + "px"; this.annoLayer.style.height = h + "px";
  }

  /* ---- rendering ---- */
  _renderBg() {
    const w = this.drawing.width, h = this.drawing.height;
    this.bgX.clearRect(0, 0, w, h);
    this.bgX.fillStyle = "#0f1420"; this.bgX.fillRect(0, 0, w, h);
    if (this.drawing.background_data) {
      const img = new Image();
      img.onload = () => {
        const s = Math.min(w / img.width, h / img.height);
        const iw = img.width * s, ih = img.height * s;
        this.bgX.drawImage(img, (w - iw) / 2, (h - ih) / 2, iw, ih);
      };
      img.src = this.drawing.background_data;
    }
  }

  _strokePath(ctx, s) {
    const pts = s.points; if (!pts || !pts.length) return;
    ctx.lineJoin = ctx.lineCap = "round";
    ctx.strokeStyle = s.color; ctx.fillStyle = s.color;
    ctx.globalAlpha = s.tool === "highlighter" ? Math.min(s.opacity, 0.4) : s.opacity;
    ctx.globalCompositeOperation = s.tool === "eraser" ? "destination-out" : "source-over";
    if (pts.length === 1) {
      ctx.beginPath(); ctx.arc(pts[0].x, pts[0].y, s.width / 2, 0, Math.PI * 2); ctx.fill();
    } else {
      for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1], b = pts[i];
        ctx.beginPath();
        ctx.lineWidth = s.width * (0.5 + ((a.p ?? 0.5) + (b.p ?? 0.5)) / 2);
        ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
      }
    }
    ctx.globalCompositeOperation = "source-over"; ctx.globalAlpha = 1;
  }

  _drawShape(ctx, a) {
    ctx.save();
    ctx.strokeStyle = a.color; ctx.fillStyle = a.color;
    ctx.lineWidth = a.stroke_w || 2; ctx.globalAlpha = a.opacity ?? 1;
    ctx.lineJoin = ctx.lineCap = "round";
    if (a.kind === "line" || a.kind === "arrow" || a.kind === "measure") {
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(a.x2, a.y2); ctx.stroke();
      if (a.kind === "arrow") {
        const ang = Math.atan2(a.y2 - a.y, a.x2 - a.x), hl = 8 + (a.stroke_w || 2) * 3;
        ctx.beginPath(); ctx.moveTo(a.x2, a.y2);
        ctx.lineTo(a.x2 - hl * Math.cos(ang - 0.4), a.y2 - hl * Math.sin(ang - 0.4));
        ctx.lineTo(a.x2 - hl * Math.cos(ang + 0.4), a.y2 - hl * Math.sin(ang + 0.4));
        ctx.closePath(); ctx.fill();
      }
      if (a.kind === "measure") {
        const ang = Math.atan2(a.y2 - a.y, a.x2 - a.x) + Math.PI / 2, t = 6;
        for (const [px, py] of [[a.x, a.y], [a.x2, a.y2]]) {
          ctx.beginPath();
          ctx.moveTo(px - t * Math.cos(ang), py - t * Math.sin(ang));
          ctx.lineTo(px + t * Math.cos(ang), py + t * Math.sin(ang)); ctx.stroke();
        }
        const label = this._measureLabel(a);
        const mx = (a.x + a.x2) / 2, my = (a.y + a.y2) / 2;
        ctx.font = "600 13px ui-monospace, monospace";
        const tw = ctx.measureText(label).width;
        ctx.globalAlpha = 1; ctx.fillStyle = "rgba(0,0,0,.72)";
        ctx.fillRect(mx - tw / 2 - 5, my - 18, tw + 10, 18);
        ctx.fillStyle = "#fff"; ctx.fillText(label, mx - tw / 2, my - 5);
      }
    } else if (a.kind === "rect") {
      ctx.strokeRect(a.x, a.y, a.width, a.height);
    } else if (a.kind === "ellipse") {
      ctx.beginPath();
      ctx.ellipse(a.x + a.width / 2, a.y + a.height / 2, Math.abs(a.width / 2), Math.abs(a.height / 2), 0, 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.restore();
  }

  _measureLabel(a) {
    const px = Math.hypot(a.x2 - a.x, a.y2 - a.y);
    if (this.drawing.scale_ratio) {
      const val = px * this.drawing.scale_ratio;
      return val.toFixed(2) + " " + (this.drawing.units || "in");
    }
    return Math.round(px) + " px";
  }

  _renderInk() {
    this.inkX.clearRect(0, 0, this.drawing.width, this.drawing.height);
    for (const s of this.strokes) (s.kind ? this._drawShape(this.inkX, s) : this._strokePath(this.inkX, s));
  }

  _renderAnno() {
    this.annoLayer.innerHTML = "";
    for (const a of this.annotations) {
      if (a.kind !== "note" && a.kind !== "pin") continue;
      const node = el(`<div class="anno" style="left:${a.x}px;top:${a.y}px"></div>`);
      if (a.kind === "note") {
        node.appendChild(el(`<div class="anno-note" style="--note:${esc(a.color || "#ffcc00")}">${esc(a.text || "")}</div>`));
      } else {
        node.appendChild(el(`<div class="anno-pin" style="color:${esc(a.color || "#ff5811")}">${icon("pin", 26)}</div>`));
      }
      this._makeDraggable(node, a);
      this.annoLayer.appendChild(node);
    }
  }
  _renderAll() { this._renderInk(); this._renderAnno(); }

  _makeDraggable(node, a) {
    let start = null;
    node.style.pointerEvents = "auto";
    node.addEventListener("pointerdown", (e) => {
      if (this.tool !== "pan") return;
      e.stopPropagation(); node.setPointerCapture(e.pointerId);
      start = this._toWorld(e.clientX, e.clientY);
    });
    node.addEventListener("pointermove", (e) => {
      if (!start) return;
      const w = this._toWorld(e.clientX, e.clientY);
      a.x += w.x - start.x; a.y += w.y - start.y; start = w;
      node.style.left = a.x + "px"; node.style.top = a.y + "px";
    });
    node.addEventListener("pointerup", () => { if (start) { start = null; this._persistAnno(a); } });
  }

  /* ---- persistence ---- */
  async _persistStroke(s) { await put("strokes", s); this._dirty(); }
  async _persistAnno(a) { await put("annotations", a); this._dirty(); }
  _dirty() { this.opts.onDirty && this.opts.onDirty(); }

  /* ---- tools & events ---- */
  _setTool(t) {
    this.tool = t;
    $$(".tool[data-tool]", this.c).forEach((b) => b.classList.toggle("active", b.dataset.tool === t));
    this.stage.style.cursor = t === "pan" ? "grab" : (t === "note" || t === "pin") ? "copy" : "crosshair";
  }

  _wire() {
    $$(".tool[data-tool]", this.c).forEach((b) =>
      b.addEventListener("click", () => this._setTool(b.dataset.tool)));
    $$(".tool[data-act]", this.c).forEach((b) =>
      b.addEventListener("click", () => this._action(b.dataset.act)));
    $$(".swatch", this.c).forEach((s) =>
      s.addEventListener("click", () => { this.color = s.dataset.color; $("[data-color-input]", this.c).value = s.dataset.color; }));
    $("[data-color-input]", this.c).addEventListener("input", (e) => (this.color = e.target.value));
    $("[data-size]", this.c).addEventListener("input", (e) => { this.size = +e.target.value; $("[data-size-val]", this.c).textContent = e.target.value; });
    $("[data-op]", this.c).addEventListener("input", (e) => { this.opacity = +e.target.value / 100; $("[data-op-val]", this.c).textContent = e.target.value; });

    $("[data-image-file]", this.c).addEventListener("change", (e) => this._loadImage(e));
    $("[data-note-cancel]", this.c).addEventListener("click", () => this._closeNote());
    $("[data-note-save]", this.c).addEventListener("click", () => this._saveNote());

    this.stage.addEventListener("pointerdown", (e) => this._down(e));
    this.stage.addEventListener("pointermove", (e) => this._move(e));
    this.stage.addEventListener("pointerup", (e) => this._up(e));
    this.stage.addEventListener("pointercancel", (e) => this._up(e));
    this.stage.addEventListener("wheel", (e) => this._wheel(e), { passive: false });
  }

  _activePressure(e) { return e.pointerType === "pen" && e.pressure > 0 ? e.pressure : 0.5; }

  _down(e) {
    if (this.opts.readOnly) return;
    this.stage.setPointerCapture(e.pointerId);
    this.pointers.set(e.pointerId, e);
    if (this.pointers.size === 2) { this.draft = null; this._clearLive(); this._startPinch(); return; }
    const w = this._toWorld(e.clientX, e.clientY), t = this.tool;
    if (t === "pan") { this.panStart = { x: e.clientX, y: e.clientY, tx: this.view.tx, ty: this.view.ty }; return; }
    if (t === "note") { this._openNote(e.clientX, e.clientY, w); return; }
    if (t === "pin") { this._addAnno({ kind: "pin", x: w.x, y: w.y, color: this.color }); return; }
    if (this.calibrating || t === "measure" || ["arrow", "line", "rect", "ellipse"].includes(t)) {
      this.draft = {
        id: uid(), client_uid: uid(), drawing_id: this.drawing.id,
        kind: this.calibrating ? "measure" : t,
        x: w.x, y: w.y, x2: w.x, y2: w.y, width: 0, height: 0,
        color: this.color, stroke_w: this.size, opacity: this.opacity, _calib: this.calibrating,
      };
    } else {
      this.draft = {
        id: uid(), client_uid: uid(), drawing_id: this.drawing.id,
        tool: t, color: this.color, width: this.size, opacity: this.opacity,
        points: [{ x: w.x, y: w.y, p: this._activePressure(e) }],
      };
    }
  }

  _move(e) {
    if (this.pointers.has(e.pointerId)) this.pointers.set(e.pointerId, e);
    if (this.pinch && this.pointers.size === 2) return this._doPinch();
    if (this.panStart) {
      this.view.tx = this.panStart.tx + (e.clientX - this.panStart.x);
      this.view.ty = this.panStart.ty + (e.clientY - this.panStart.y);
      return this._applyTransform();
    }
    if (!this.draft) return;
    const w = this._toWorld(e.clientX, e.clientY);
    if (this.draft.points) {
      const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      for (const ce of evs) { const cw = this._toWorld(ce.clientX, ce.clientY); this.draft.points.push({ x: cw.x, y: cw.y, p: this._activePressure(ce) }); }
      this._clearLive(); this._strokePath(this.liveX, this.draft);
    } else {
      this.draft.x2 = w.x; this.draft.y2 = w.y;
      this.draft.width = w.x - this.draft.x; this.draft.height = w.y - this.draft.y;
      this._clearLive(); this._drawShape(this.liveX, this.draft);
    }
  }

  _up(e) {
    this.pointers.delete(e.pointerId);
    if (this.pointers.size < 2) this.pinch = null;
    if (this.panStart) { this.panStart = null; return; }
    if (!this.draft) return;
    this._clearLive();
    const d = this.draft; this.draft = null;
    if (d._calib) { delete d._calib; return this._finishCalibration(d); }
    delete d._calib;
    if (d.points) {
      this.strokes.push(d); this._persistStroke(d); this._pushUndo("stroke", d.id); this._renderInk();
    } else if (d.kind === "note" || d.kind === "pin") {
      this.annotations.push(d); this._persistAnno(d); this._pushUndo("anno", d.id); this._renderAnno();
    } else {
      // shapes + measures render on the ink layer, stored in strokes bucket
      this.strokes.push(d); this._persistStroke(d); this._pushUndo("stroke", d.id); this._renderInk();
    }
  }

  _clearLive() { this.liveX.clearRect(0, 0, this.drawing.width, this.drawing.height); }

  _startPinch() {
    const [p1, p2] = [...this.pointers.values()];
    this.pinch = {
      dist: Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY),
      cx: (p1.clientX + p2.clientX) / 2, cy: (p1.clientY + p2.clientY) / 2,
      scale: this.view.scale, tx: this.view.tx, ty: this.view.ty,
    };
  }
  _doPinch() {
    const [p1, p2] = [...this.pointers.values()];
    const dist = Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY);
    const cx = (p1.clientX + p2.clientX) / 2, cy = (p1.clientY + p2.clientY) / 2;
    const r = this.stage.getBoundingClientRect();
    const ns = clamp(this.pinch.scale * (dist / this.pinch.dist), 0.1, 10);
    const wx = (this.pinch.cx - r.left - this.pinch.tx) / this.pinch.scale;
    const wy = (this.pinch.cy - r.top - this.pinch.ty) / this.pinch.scale;
    this.view.scale = ns; this.view.tx = cx - r.left - wx * ns; this.view.ty = cy - r.top - wy * ns;
    this._applyTransform();
  }
  _wheel(e) {
    e.preventDefault();
    const r = this.stage.getBoundingClientRect();
    const ns = clamp(this.view.scale * (e.deltaY < 0 ? 1.1 : 0.9), 0.1, 10);
    const wx = (e.clientX - r.left - this.view.tx) / this.view.scale;
    const wy = (e.clientY - r.top - this.view.ty) / this.view.scale;
    this.view.scale = ns; this.view.tx = e.clientX - r.left - wx * ns; this.view.ty = e.clientY - r.top - wy * ns;
    this._applyTransform();
  }

  /* ---- annotations / notes ---- */
  _addAnno(a) {
    a.id = a.id || uid(); a.client_uid = a.client_uid || uid(); a.drawing_id = this.drawing.id;
    this.annotations.push(a); this._persistAnno(a); this._pushUndo("anno", a.id); this._renderAnno();
  }
  _openNote(cx, cy, world) {
    this._noteWorld = world;
    const ed = $("[data-note-editor]", this.c);
    ed.style.left = clamp(cx, 8, window.innerWidth - 300) + "px";
    ed.style.top = clamp(cy, 70, window.innerHeight - 180) + "px";
    ed.classList.remove("hidden");
    const ta = $("[data-note-text]", this.c); ta.value = ""; ta.focus();
  }
  _closeNote() { $("[data-note-editor]", this.c).classList.add("hidden"); this._noteWorld = null; }
  _saveNote() {
    const text = $("[data-note-text]", this.c).value.trim();
    if (text && this._noteWorld) this._addAnno({ kind: "note", x: this._noteWorld.x, y: this._noteWorld.y, text, color: this.color });
    this._closeNote();
  }

  /* ---- undo / redo ---- */
  _pushUndo(type, id) { this.undoStack.push({ type, id }); this.redoStack = []; }
  async _undo() {
    const a = this.undoStack.pop(); if (!a) return;
    let i = this.strokes.findIndex((s) => s.id === a.id);
    if (i >= 0) { const [rec] = this.strokes.splice(i, 1); this.redoStack.push({ ...a, rec }); await del("strokes", a.id); this._renderInk(); this._dirty(); return; }
    i = this.annotations.findIndex((s) => s.id === a.id);
    if (i >= 0) { const [rec] = this.annotations.splice(i, 1); this.redoStack.push({ ...a, rec }); await del("annotations", a.id); this._renderAnno(); this._dirty(); }
  }
  async _redo() {
    const a = this.redoStack.pop(); if (!a || !a.rec) return;
    if (a.type === "stroke") { this.strokes.push(a.rec); await this._persistStroke(a.rec); this._renderInk(); }
    else { this.annotations.push(a.rec); await this._persistAnno(a.rec); this._renderAnno(); }
    this.undoStack.push({ type: a.type, id: a.id });
  }

  /* ---- actions ---- */
  async _action(act) {
    if (act === "undo") return this._undo();
    if (act === "redo") return this._redo();
    if (act === "fit") return this.fit();
    if (act === "image") return $("[data-image-file]", this.c).click();
    if (act === "export") return this.exportPNG();
    if (act === "calibrate") return this._beginCalibration();
    if (act === "clear") return this._clear();
  }

  _beginCalibration() {
    this.calibrating = true; this._setTool("measure");
    toast("Calibration: draw a line over a known dimension.", "info");
  }
  async _finishCalibration(line) {
    const px = Math.hypot(line.x2 - line.x, line.y2 - line.y);
    this.calibrating = false;
    if (px < 4) return;
    const val = prompt("Real length of that line (e.g. 12)\nLeave blank to cancel:");
    if (!val) return;
    const unit = prompt("Units (in, mm, cm, ft):", this.drawing.units || "in") || "in";
    const real = parseFloat(val); if (!real || real <= 0) return;
    this.drawing.scale_ratio = real / px; this.drawing.units = unit;
    await put("drawings", this.drawing); this._dirty();
    this._updateScaleLabel(); this._renderInk();
    toast(`Scale set: 1 px = ${this.drawing.scale_ratio.toFixed(4)} ${unit}`, "success");
  }
  _updateScaleLabel() {
    const lab = $("[data-scale]", this.c);
    lab.textContent = this.drawing.scale_ratio
      ? `1px = ${this.drawing.scale_ratio.toFixed(4)} ${this.drawing.units || "in"}`
      : "uncalibrated";
  }

  _loadImage(e) {
    const f = e.target.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = async () => {
      const img = new Image();
      img.onload = async () => {
        this.drawing.width = Math.max(1200, img.width);
        this.drawing.height = Math.max(900, img.height);
        this.drawing.background_kind = "image";
        this.drawing.background_data = reader.result;
        this.drawing.background_name = f.name;
        await put("drawings", this.drawing); this._dirty();
        this._sizeCanvases(); this._renderBg(); this._renderAll(); this.fit();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(f); e.target.value = "";
  }

  async _clear() {
    if (!confirm("Clear all markup on this drawing? The background image is kept.")) return;
    for (const s of this.strokes) await del("strokes", s.id);
    for (const a of this.annotations) await del("annotations", a.id);
    this.strokes = []; this.annotations = []; this.undoStack = []; this.redoStack = [];
    this._renderAll(); this._dirty();
  }

  exportPNG() {
    const out = document.createElement("canvas");
    out.width = this.drawing.width; out.height = this.drawing.height;
    const o = out.getContext("2d");
    o.drawImage(this.bg, 0, 0, out.width, out.height);
    o.drawImage(this.ink, 0, 0, out.width, out.height);
    for (const a of this.annotations) {
      if (a.kind === "note") {
        o.fillStyle = a.color || "#ffcc00"; o.fillRect(a.x, a.y, 190, 26);
        o.fillStyle = "#111"; o.font = "14px sans-serif"; o.fillText((a.text || "").slice(0, 28), a.x + 6, a.y + 17);
      }
    }
    const link = document.createElement("a");
    link.download = `${(this.drawing.drawing_number || this.drawing.title || "aeromarkup")}_rev${this.drawing.revision || "A"}.png`;
    link.href = out.toDataURL("image/png"); link.click();
  }

  hasMarkup() { return this.strokes.length + this.annotations.length; }
}
