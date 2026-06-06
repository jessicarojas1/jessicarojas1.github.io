/* ===================================================================
   AeroMarkup — offline-first drawing & annotation engine
   -------------------------------------------------------------------
   Works fully offline (IndexedDB + service worker). When online and a
   backend is reachable, Sync pushes/pulls changes via /api/sync.
   Input: Pointer Events → mouse, touch, and pressure-sensitive stylus
   on 2-in-1s, iPads, and Android tablets.
   =================================================================== */
(() => {
  "use strict";

  // ── tiny helpers ───────────────────────────────────────────────
  const $ = (s) => document.querySelector(s);
  const uid = () =>
    (crypto.randomUUID ? crypto.randomUUID()
      : "id-" + Date.now() + "-" + Math.random().toString(16).slice(2));
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const DPR = Math.min(window.devicePixelRatio || 1, 2);
  const DEVICE_ID = localStorage.getItem("am_device") ||
    (localStorage.setItem("am_device", uid()), localStorage.getItem("am_device"));

  // ── IndexedDB store (offline persistence) ──────────────────────
  const DB = {
    _db: null,
    open() {
      return new Promise((res, rej) => {
        const r = indexedDB.open("aeromarkup", 1);
        r.onupgradeneeded = (e) => {
          const db = e.target.result;
          for (const s of ["projects", "drawings", "strokes", "annotations", "meta"]) {
            if (!db.objectStoreNames.contains(s)) {
              const store = db.createObjectStore(s, { keyPath: "id" });
              if (s === "strokes" || s === "annotations")
                store.createIndex("drawing_id", "drawing_id", { unique: false });
              if (s === "drawings")
                store.createIndex("project_id", "project_id", { unique: false });
            }
          }
        };
        r.onsuccess = () => { this._db = r.result; res(); };
        r.onerror = () => rej(r.error);
      });
    },
    tx(store, mode = "readonly") {
      return this._db.transaction(store, mode).objectStore(store);
    },
    put(store, val) {
      return new Promise((res, rej) => {
        const rq = this.tx(store, "readwrite").put(val);
        rq.onsuccess = () => res(val); rq.onerror = () => rej(rq.error);
      });
    },
    get(store, id) {
      return new Promise((res, rej) => {
        const rq = this.tx(store).get(id);
        rq.onsuccess = () => res(rq.result); rq.onerror = () => rej(rq.error);
      });
    },
    all(store) {
      return new Promise((res, rej) => {
        const rq = this.tx(store).getAll();
        rq.onsuccess = () => res(rq.result || []); rq.onerror = () => rej(rq.error);
      });
    },
    byIndex(store, index, val) {
      return new Promise((res, rej) => {
        const rq = this.tx(store).index(index).getAll(val);
        rq.onsuccess = () => res(rq.result || []); rq.onerror = () => rej(rq.error);
      });
    },
    del(store, id) {
      return new Promise((res, rej) => {
        const rq = this.tx(store, "readwrite").delete(id);
        rq.onsuccess = () => res(); rq.onerror = () => rej(rq.error);
      });
    },
  };

  // ── App state ──────────────────────────────────────────────────
  const state = {
    project: null,
    drawing: null,
    strokes: [],        // committed strokes for current drawing
    annotations: [],    // committed annotations
    tool: "pen",
    color: "#ff5811",
    size: 3,
    opacity: 1,
    view: { scale: 1, tx: 0, ty: 0 },
    undoStack: [],
    redoStack: [],
    dirty: new Set(),   // ids changed since last sync (client_uids)
  };

  // ── DOM refs ───────────────────────────────────────────────────
  const stage = $("#stage");
  const bg = $("#bgCanvas"), ink = $("#inkCanvas"), live = $("#liveCanvas");
  const annoLayer = $("#annoLayer");
  const bgCtx = bg.getContext("2d");
  const inkCtx = ink.getContext("2d");
  const liveCtx = live.getContext("2d");

  // =================================================================
  // Coordinate system & transforms
  // =================================================================
  function applyTransform() {
    const { scale, tx, ty } = state.view;
    const t = `translate(${tx}px, ${ty}px) scale(${scale})`;
    for (const el of [bg, ink, live, annoLayer]) el.style.transform = t;
    $("#zoomReadout").textContent = Math.round(scale * 100) + "%";
  }
  function screenToWorld(clientX, clientY) {
    const r = stage.getBoundingClientRect();
    const { scale, tx, ty } = state.view;
    return {
      x: (clientX - r.left - tx) / scale,
      y: (clientY - r.top - ty) / scale,
    };
  }
  function fitToScreen() {
    if (!state.drawing) return;
    const r = stage.getBoundingClientRect();
    const s = Math.min(r.width / state.drawing.width, r.height / state.drawing.height) * 0.95;
    state.view.scale = s || 1;
    state.view.tx = (r.width - state.drawing.width * s) / 2;
    state.view.ty = (r.height - state.drawing.height * s) / 2;
    applyTransform();
  }

  // =================================================================
  // Canvas sizing & rendering
  // =================================================================
  function sizeCanvases() {
    if (!state.drawing) return;
    const w = state.drawing.width, h = state.drawing.height;
    for (const c of [bg, ink, live]) {
      c.width = w * DPR; c.height = h * DPR;
      c.style.width = w + "px"; c.style.height = h + "px";
      c.getContext("2d").setTransform(DPR, 0, 0, DPR, 0, 0);
    }
    annoLayer.style.width = w + "px";
    annoLayer.style.height = h + "px";
  }

  function renderBackground() {
    bgCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
    bgCtx.fillStyle = "#1b2230";
    bgCtx.fillRect(0, 0, state.drawing.width, state.drawing.height);
    if (state.drawing.background_data) {
      const img = new Image();
      img.onload = () => {
        // contain the image within the canvas
        const s = Math.min(state.drawing.width / img.width, state.drawing.height / img.height);
        const w = img.width * s, h = img.height * s;
        bgCtx.drawImage(img, (state.drawing.width - w) / 2, (state.drawing.height - h) / 2, w, h);
      };
      img.src = state.drawing.background_data;
    }
  }

  function strokePath(ctx, s) {
    const pts = s.points;
    if (!pts.length) return;
    ctx.lineJoin = ctx.lineCap = "round";
    ctx.strokeStyle = s.color;
    ctx.globalAlpha = s.tool === "highlighter" ? Math.min(s.opacity, 0.4) : s.opacity;
    ctx.globalCompositeOperation = s.tool === "eraser" ? "destination-out" : "source-over";
    if (pts.length === 1) {
      ctx.beginPath();
      ctx.fillStyle = s.color;
      ctx.arc(pts[0].x, pts[0].y, s.width / 2, 0, Math.PI * 2);
      ctx.fill();
      ctx.globalCompositeOperation = "source-over";
      ctx.globalAlpha = 1;
      return;
    }
    // pressure-aware variable width: draw segment-by-segment
    for (let i = 1; i < pts.length; i++) {
      const a = pts[i - 1], b = pts[i];
      ctx.beginPath();
      ctx.lineWidth = s.width * (0.5 + ((a.p ?? 0.5) + (b.p ?? 0.5)) / 2);
      ctx.moveTo(a.x, a.y);
      ctx.lineTo(b.x, b.y);
      ctx.stroke();
    }
    ctx.globalCompositeOperation = "source-over";
    ctx.globalAlpha = 1;
  }

  function renderInk() {
    inkCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
    for (const s of state.strokes) {
      if (s.kind) drawShape(inkCtx, s); else strokePath(inkCtx, s);
    }
  }

  // shape-type annotations rendered on ink canvas (arrow/line/rect/ellipse)
  function drawShape(ctx, a) {
    ctx.save();
    ctx.strokeStyle = a.color; ctx.fillStyle = a.color;
    ctx.lineWidth = a.stroke_w || 2; ctx.globalAlpha = a.opacity ?? 1;
    ctx.lineJoin = ctx.lineCap = "round";
    if (a.kind === "line" || a.kind === "arrow") {
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(a.x2, a.y2); ctx.stroke();
      if (a.kind === "arrow") {
        const ang = Math.atan2(a.y2 - a.y, a.x2 - a.x);
        const hl = 8 + (a.stroke_w || 2) * 3;
        ctx.beginPath();
        ctx.moveTo(a.x2, a.y2);
        ctx.lineTo(a.x2 - hl * Math.cos(ang - 0.4), a.y2 - hl * Math.sin(ang - 0.4));
        ctx.lineTo(a.x2 - hl * Math.cos(ang + 0.4), a.y2 - hl * Math.sin(ang + 0.4));
        ctx.closePath(); ctx.fill();
      }
    } else if (a.kind === "rect") {
      ctx.strokeRect(a.x, a.y, a.width, a.height);
    } else if (a.kind === "ellipse") {
      ctx.beginPath();
      ctx.ellipse(a.x + a.width / 2, a.y + a.height / 2,
        Math.abs(a.width / 2), Math.abs(a.height / 2), 0, 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.restore();
  }

  // DOM annotations (notes & pins) — positioned in world coords
  function renderAnnoLayer() {
    annoLayer.innerHTML = "";
    for (const a of state.annotations) {
      if (a.kind !== "note" && a.kind !== "pin") continue;
      const el = document.createElement("div");
      el.className = "anno";
      el.style.left = a.x + "px"; el.style.top = a.y + "px";
      if (a.kind === "note") {
        const n = document.createElement("div");
        n.className = "anno-note";
        n.style.background = (a.color || "#ffcc00");
        n.textContent = a.text || "";
        el.appendChild(n);
      } else {
        const p = document.createElement("div");
        p.className = "anno-pin"; p.textContent = "📍";
        el.appendChild(p);
      }
      makeDraggable(el, a);
      annoLayer.appendChild(el);
    }
  }

  function makeDraggable(el, a) {
    let start = null;
    el.addEventListener("pointerdown", (e) => {
      if (state.tool !== "pan" && state.tool !== "note" && state.tool !== "pin") return;
      e.stopPropagation();
      el.setPointerCapture(e.pointerId);
      start = screenToWorld(e.clientX, e.clientY);
    });
    el.addEventListener("pointermove", (e) => {
      if (!start) return;
      const w = screenToWorld(e.clientX, e.clientY);
      a.x += w.x - start.x; a.y += w.y - start.y; start = w;
      el.style.left = a.x + "px"; el.style.top = a.y + "px";
    });
    el.addEventListener("pointerup", (e) => {
      if (!start) return;
      start = null; markDirty(a); persistAnnotation(a);
    });
  }

  function renderAll() {
    renderInk(); renderAnnoLayer();
  }

  // =================================================================
  // Persistence helpers
  // =================================================================
  async function persistStroke(s) { await DB.put("strokes", s); }
  async function persistAnnotation(a) { await DB.put("annotations", a); }
  function markDirty(rec) { state.dirty.add(rec.id); setSyncBadge("local"); }

  // =================================================================
  // Drawing interaction (pointer events)
  // =================================================================
  let drawing = null;          // active freehand stroke
  let shapeDraft = null;       // active shape annotation
  const pointers = new Map();  // multi-touch tracking
  let pinch = null;            // {dist, cx, cy}
  let panStart = null;

  function activePressure(e) {
    // Pen reports 0..1; touch/mouse report 0 or 0.5 → normalize
    if (e.pointerType === "pen" && e.pressure > 0) return e.pressure;
    return 0.5;
  }

  stage.addEventListener("pointerdown", (e) => {
    if (!state.drawing) return;
    stage.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, e);

    // two-finger pinch/pan
    if (pointers.size === 2) {
      drawing = shapeDraft = null;
      liveCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
      const [p1, p2] = [...pointers.values()];
      pinch = {
        dist: Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY),
        cx: (p1.clientX + p2.clientX) / 2,
        cy: (p1.clientY + p2.clientY) / 2,
        scale: state.view.scale, tx: state.view.tx, ty: state.view.ty,
      };
      return;
    }

    const w = screenToWorld(e.clientX, e.clientY);
    const t = state.tool;

    if (t === "pan") { panStart = { x: e.clientX, y: e.clientY, tx: state.view.tx, ty: state.view.ty }; return; }
    if (t === "note") { openNoteEditor(e.clientX, e.clientY, w); return; }
    if (t === "pin") { addAnnotation({ kind: "pin", x: w.x, y: w.y, color: state.color }); return; }

    if (t === "pen" || t === "highlighter" || t === "eraser") {
      drawing = {
        id: uid(), client_uid: uid(), drawing_id: state.drawing.id,
        tool: t, color: state.color, width: state.size,
        opacity: state.opacity, points: [{ x: w.x, y: w.y, p: activePressure(e) }],
      };
    } else if (["arrow", "line", "rect", "ellipse"].includes(t)) {
      shapeDraft = {
        id: uid(), client_uid: uid(), drawing_id: state.drawing.id, kind: t,
        x: w.x, y: w.y, x2: w.x, y2: w.y, width: 0, height: 0,
        color: state.color, stroke_w: state.size, opacity: state.opacity,
      };
    }
  });

  stage.addEventListener("pointermove", (e) => {
    if (!state.drawing) return;
    if (pointers.has(e.pointerId)) pointers.set(e.pointerId, e);

    // pinch zoom + pan
    if (pinch && pointers.size === 2) {
      const [p1, p2] = [...pointers.values()];
      const dist = Math.hypot(p2.clientX - p1.clientX, p2.clientY - p1.clientY);
      const cx = (p1.clientX + p2.clientX) / 2, cy = (p1.clientY + p2.clientY) / 2;
      const r = stage.getBoundingClientRect();
      const factor = dist / pinch.dist;
      const ns = clamp(pinch.scale * factor, 0.1, 8);
      // zoom around pinch center
      const wx = (pinch.cx - r.left - pinch.tx) / pinch.scale;
      const wy = (pinch.cy - r.top - pinch.ty) / pinch.scale;
      state.view.scale = ns;
      state.view.tx = (cx - r.left) - wx * ns;
      state.view.ty = (cy - r.top) - wy * ns;
      applyTransform();
      return;
    }

    if (panStart) {
      state.view.tx = panStart.tx + (e.clientX - panStart.x);
      state.view.ty = panStart.ty + (e.clientY - panStart.y);
      applyTransform(); return;
    }

    const w = screenToWorld(e.clientX, e.clientY);

    if (drawing) {
      // coalesced events → smoother high-rate stylus capture
      const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      for (const ce of evs) {
        const cw = screenToWorld(ce.clientX, ce.clientY);
        drawing.points.push({ x: cw.x, y: cw.y, p: activePressure(ce) });
      }
      liveCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
      strokePath(liveCtx, drawing);
    } else if (shapeDraft) {
      shapeDraft.x2 = w.x; shapeDraft.y2 = w.y;
      shapeDraft.width = w.x - shapeDraft.x;
      shapeDraft.height = w.y - shapeDraft.y;
      liveCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
      drawShape(liveCtx, shapeDraft);
    }
  });

  function endPointer(e) {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) pinch = null;
    if (panStart) { panStart = null; return; }

    if (drawing) {
      liveCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
      pushUndo({ type: "stroke", id: drawing.id });
      state.strokes.push(drawing);
      persistStroke(drawing); markDirty(drawing);
      drawing = null; renderInk();
    } else if (shapeDraft) {
      liveCtx.clearRect(0, 0, state.drawing.width, state.drawing.height);
      // shapes stored as annotations
      pushUndo({ type: "anno", id: shapeDraft.id });
      state.strokes.push(shapeDraft); // rendered on ink layer
      persistStroke(shapeDraft); markDirty(shapeDraft);
      shapeDraft = null; renderInk();
    }
  }
  stage.addEventListener("pointerup", endPointer);
  stage.addEventListener("pointercancel", endPointer);
  stage.addEventListener("pointerleave", (e) => { if (e.pointerType === "mouse") endPointer(e); });

  // mouse wheel zoom (2-in-1 trackpads / desktop)
  stage.addEventListener("wheel", (e) => {
    e.preventDefault();
    const r = stage.getBoundingClientRect();
    const factor = e.deltaY < 0 ? 1.1 : 0.9;
    const ns = clamp(state.view.scale * factor, 0.1, 8);
    const wx = (e.clientX - r.left - state.view.tx) / state.view.scale;
    const wy = (e.clientY - r.top - state.view.ty) / state.view.scale;
    state.view.scale = ns;
    state.view.tx = (e.clientX - r.left) - wx * ns;
    state.view.ty = (e.clientY - r.top) - wy * ns;
    applyTransform();
  }, { passive: false });

  // =================================================================
  // Undo / redo
  // =================================================================
  function pushUndo(action) { state.undoStack.push(action); state.redoStack = []; }
  async function undo() {
    const a = state.undoStack.pop();
    if (!a) return;
    const idx = state.strokes.findIndex((s) => s.id === a.id);
    if (idx >= 0) {
      const [rec] = state.strokes.splice(idx, 1);
      state.redoStack.push({ ...a, rec });
      await DB.del("strokes", a.id); renderInk();
    } else {
      const j = state.annotations.findIndex((s) => s.id === a.id);
      if (j >= 0) {
        const [rec] = state.annotations.splice(j, 1);
        state.redoStack.push({ ...a, rec });
        await DB.del("annotations", a.id); renderAnnoLayer();
      }
    }
  }
  async function redo() {
    const a = state.redoStack.pop();
    if (!a || !a.rec) return;
    if (a.type === "stroke" || a.rec.kind) {
      state.strokes.push(a.rec); await persistStroke(a.rec); renderInk();
    } else {
      state.annotations.push(a.rec); await persistAnnotation(a.rec); renderAnnoLayer();
    }
    state.undoStack.push({ type: a.type, id: a.id });
  }

  // =================================================================
  // Annotations (notes / pins)
  // =================================================================
  function addAnnotation(a) {
    a.id = a.id || uid(); a.client_uid = a.client_uid || uid();
    a.drawing_id = state.drawing.id;
    pushUndo({ type: "anno", id: a.id });
    state.annotations.push(a);
    persistAnnotation(a); markDirty(a); renderAnnoLayer();
  }

  let noteCtx = null;
  function openNoteEditor(clientX, clientY, world) {
    noteCtx = world;
    const ed = $("#noteEditor");
    ed.style.left = clamp(clientX, 10, window.innerWidth - 280) + "px";
    ed.style.top = clamp(clientY, 60, window.innerHeight - 160) + "px";
    ed.classList.remove("hidden");
    $("#noteText").value = ""; $("#noteText").focus();
  }
  $("#noteSave").addEventListener("click", () => {
    const text = $("#noteText").value.trim();
    if (text && noteCtx) addAnnotation({ kind: "note", x: noteCtx.x, y: noteCtx.y, text, color: state.color });
    $("#noteEditor").classList.add("hidden"); noteCtx = null;
  });
  $("#noteCancel").addEventListener("click", () => {
    $("#noteEditor").classList.add("hidden"); noteCtx = null;
  });

  // =================================================================
  // Toolbar / properties wiring
  // =================================================================
  function setTool(t) {
    state.tool = t;
    document.querySelectorAll(".tool[data-tool]").forEach((b) =>
      b.classList.toggle("active", b.dataset.tool === t));
    stage.style.cursor = (t === "pan") ? "grab" : (t === "note" || t === "pin") ? "copy" : "crosshair";
  }
  document.querySelectorAll(".tool[data-tool]").forEach((b) =>
    b.addEventListener("click", () => setTool(b.dataset.tool)));

  const SWATCHES = ["#ff5811", "#ffcc00", "#3ad07a", "#3ba7ff", "#ff4d4d",
                    "#ffffff", "#000000", "#b06cff", "#ff8ad6", "#00e5d0"];
  const swWrap = $("#swatches");
  SWATCHES.forEach((c) => {
    const s = document.createElement("button");
    s.className = "swatch"; s.style.background = c;
    s.addEventListener("click", () => {
      state.color = c; $("#colorInput").value = c;
      document.querySelectorAll(".swatch").forEach((x) => x.classList.remove("active"));
      s.classList.add("active");
    });
    swWrap.appendChild(s);
  });
  $("#colorInput").addEventListener("input", (e) => { state.color = e.target.value; });
  $("#sizeInput").addEventListener("input", (e) => {
    state.size = +e.target.value; $("#sizeVal").textContent = e.target.value;
  });
  $("#opacityInput").addEventListener("input", (e) => {
    state.opacity = +e.target.value / 100; $("#opacityVal").textContent = e.target.value;
  });

  $("#undoBtn").addEventListener("click", undo);
  $("#redoBtn").addEventListener("click", redo);
  $("#fitBtn").addEventListener("click", fitToScreen);
  $("#clearBtn").addEventListener("click", async () => {
    if (!confirm("Clear all markup on this drawing? (background stays)")) return;
    for (const s of state.strokes) await DB.del("strokes", s.id);
    for (const a of state.annotations) await DB.del("annotations", a.id);
    state.strokes = []; state.annotations = []; state.undoStack = []; state.redoStack = [];
    renderAll();
  });

  // keyboard shortcuts (2-in-1 / desktop)
  const KEYS = { p: "pen", h: "highlighter", e: "eraser", a: "arrow", l: "line",
                 r: "rect", o: "ellipse", n: "note", m: "pin" };
  window.addEventListener("keydown", (e) => {
    if (e.target.tagName === "TEXTAREA" || e.target.tagName === "INPUT") return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "z") { e.preventDefault(); undo(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "y") { e.preventDefault(); redo(); return; }
    if (e.key === " ") { setTool("pan"); return; }
    if (e.key.toLowerCase() === "f") { fitToScreen(); return; }
    const t = KEYS[e.key.toLowerCase()];
    if (t) setTool(t);
  });

  // =================================================================
  // Project / drawing management
  // =================================================================
  async function ensureSeedData() {
    let projects = await DB.all("projects");
    if (!projects.length) {
      const p = { id: uid(), client_uid: uid(), name: "My First Project",
                  category: "aerospace", description: "", updated_at: Date.now() };
      await DB.put("projects", p);
      const d = await newDrawingRecord(p.id, "Untitled Drawing");
      projects = [p];
    }
    return projects;
  }

  async function newDrawingRecord(projectId, title, bg = null) {
    const d = {
      id: uid(), client_uid: uid(), project_id: projectId, title,
      background_kind: bg ? "image" : "blank", background_data: bg,
      width: 1600, height: 1200, version: 1, updated_at: Date.now(),
    };
    await DB.put("drawings", d);
    return d;
  }

  async function refreshSelectors() {
    const projects = await DB.all("projects");
    const ps = $("#projectSelect"); ps.innerHTML = "";
    for (const p of projects) {
      const o = document.createElement("option");
      o.value = p.id; o.textContent = p.name; ps.appendChild(o);
    }
    if (state.project) ps.value = state.project.id;

    const drawings = state.project ? await DB.byIndex("drawings", "project_id", state.project.id) : [];
    const dsel = $("#drawingSelect"); dsel.innerHTML = "";
    for (const d of drawings) {
      const o = document.createElement("option");
      o.value = d.id; o.textContent = d.title; dsel.appendChild(o);
    }
    if (state.drawing) dsel.value = state.drawing.id;
  }

  async function loadDrawing(id) {
    const d = await DB.get("drawings", id);
    if (!d) return;
    state.drawing = d;
    state.strokes = await DB.byIndex("strokes", "drawing_id", id);
    state.annotations = await DB.byIndex("annotations", "drawing_id", id);
    state.undoStack = []; state.redoStack = [];
    sizeCanvases(); renderBackground(); renderAll(); fitToScreen();
    await refreshSelectors();
  }

  $("#projectSelect").addEventListener("change", async (e) => {
    state.project = await DB.get("projects", e.target.value);
    const drawings = await DB.byIndex("drawings", "project_id", state.project.id);
    if (drawings.length) await loadDrawing(drawings[0].id);
    else { const d = await newDrawingRecord(state.project.id, "Untitled Drawing"); await loadDrawing(d.id); }
  });
  $("#drawingSelect").addEventListener("change", (e) => loadDrawing(e.target.value));

  $("#newDrawingBtn").addEventListener("click", async () => {
    const title = prompt("Drawing name:", "Drawing " + (Date.now() % 1000));
    if (title === null) return;
    const d = await newDrawingRecord(state.project.id, title || "Untitled");
    await loadDrawing(d.id);
  });

  // =================================================================
  // Background image loading
  // =================================================================
  $("#loadImageBtn").addEventListener("click", () => $("#imageFile").click());
  $("#imageFile").addEventListener("change", (e) => {
    const f = e.target.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = async () => {
      const img = new Image();
      img.onload = async () => {
        state.drawing.width = Math.max(1200, img.width);
        state.drawing.height = Math.max(900, img.height);
        state.drawing.background_kind = "image";
        state.drawing.background_data = reader.result;
        state.drawing.background_name = f.name;
        await DB.put("drawings", state.drawing);
        markDirty(state.drawing);
        sizeCanvases(); renderBackground(); renderAll(); fitToScreen();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(f);
    e.target.value = "";
  });

  // =================================================================
  // Export PNG (flattened background + ink + notes)
  // =================================================================
  $("#exportBtn").addEventListener("click", () => {
    const out = document.createElement("canvas");
    out.width = state.drawing.width; out.height = state.drawing.height;
    const o = out.getContext("2d");
    o.drawImage(bg, 0, 0, out.width, out.height);
    o.drawImage(ink, 0, 0, out.width, out.height);
    // notes: simple text render
    for (const a of state.annotations) {
      if (a.kind === "note") {
        o.fillStyle = a.color || "#ffcc00";
        o.fillRect(a.x, a.y, 180, 24);
        o.fillStyle = "#1a1a1a"; o.font = "14px sans-serif";
        o.fillText((a.text || "").slice(0, 26), a.x + 6, a.y + 16);
      } else if (a.kind === "pin") {
        o.font = "24px sans-serif"; o.fillText("📍", a.x - 8, a.y);
      }
    }
    const link = document.createElement("a");
    link.download = (state.drawing.title || "aeromarkup") + ".png";
    link.href = out.toDataURL("image/png");
    link.click();
  });

  // =================================================================
  // Online / offline + sync
  // =================================================================
  function setNetBadge(online) {
    const b = $("#netBadge");
    b.textContent = online ? "Online" : "Offline";
    b.className = "badge " + (online ? "badge-online" : "badge-offline");
  }
  function setSyncBadge(stateName) {
    const b = $("#syncBadge");
    const map = { local: ["Local", "badge-muted"], syncing: ["Syncing…", "badge-syncing"],
                  synced: ["Synced", "badge-synced"], error: ["Sync error", "badge-offline"] };
    const [t, c] = map[stateName] || map.local;
    b.textContent = t; b.className = "badge " + c;
  }
  window.addEventListener("online", () => setNetBadge(true));
  window.addEventListener("offline", () => setNetBadge(false));

  async function apiReachable() {
    try {
      const r = await fetch("api/health", { cache: "no-store" });
      return r.ok;
    } catch { return false; }
  }

  async function doSync() {
    if (!navigator.onLine) { setSyncBadge("error"); alert("You're offline — changes are saved locally and will sync next time you connect."); return; }
    if (!(await apiReachable())) { setSyncBadge("error"); alert("Server not reachable. Your work is safe on this device."); return; }
    setSyncBadge("syncing");
    try {
      // gather dirty strokes/annotations for the current drawing
      const strokes = state.strokes.filter((s) => state.dirty.has(s.id) && !s.kind);
      const annotations = [
        ...state.strokes.filter((s) => state.dirty.has(s.id) && s.kind), // shapes
        ...state.annotations.filter((a) => state.dirty.has(a.id)),
      ];
      const since = +(localStorage.getItem("am_cursor_" + state.drawing.id) || 0);
      const body = {
        device_id: DEVICE_ID, since,
        drawing: {
          client_uid: state.drawing.client_uid, project_id: state.drawing.project_id,
          title: state.drawing.title, background_kind: state.drawing.background_kind,
          background_data: state.drawing.background_data,
          width: state.drawing.width, height: state.drawing.height,
        },
        strokes, annotations,
      };
      const r = await fetch("api/sync", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!r.ok) throw new Error("sync failed " + r.status);
      const data = await r.json();
      // apply peer changes
      await applyRemoteChanges(data.changes || []);
      if (data.cursor != null) localStorage.setItem("am_cursor_" + state.drawing.id, data.cursor);
      state.dirty.clear();
      setSyncBadge("synced");
    } catch (err) {
      console.error(err); setSyncBadge("error");
      alert("Sync error — your work is saved locally. " + err.message);
    }
  }

  async function applyRemoteChanges(changes) {
    let touched = false;
    for (const c of changes) {
      const p = c.payload; if (!p) continue;
      if (c.entity === "stroke" && p.client_uid) {
        if (!state.strokes.find((s) => s.client_uid === p.client_uid)) {
          const rec = { ...p, id: p.client_uid, drawing_id: state.drawing.id };
          state.strokes.push(rec); await persistStroke(rec); touched = true;
        }
      } else if (c.entity === "annotation" && p.client_uid) {
        const bucket = p.kind && p.kind !== "note" && p.kind !== "pin" ? state.strokes : state.annotations;
        if (!bucket.find((s) => s.client_uid === p.client_uid)) {
          const rec = { ...p, id: p.client_uid, drawing_id: state.drawing.id };
          bucket.push(rec);
          await (bucket === state.strokes ? persistStroke : persistAnnotation)(rec); touched = true;
        }
      }
    }
    if (touched) renderAll();
  }

  $("#syncBtn").addEventListener("click", doSync);

  // =================================================================
  // Help modal + service worker
  // =================================================================
  $("#helpBtn").addEventListener("click", () => $("#helpModal").classList.remove("hidden"));
  $("#helpClose").addEventListener("click", () => $("#helpModal").classList.add("hidden"));

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () =>
      navigator.serviceWorker.register("sw.js").catch((e) => console.warn("SW reg failed", e)));
  }

  window.addEventListener("resize", () => { /* transform is fixed in world space */ });

  // =================================================================
  // Boot
  // =================================================================
  (async function boot() {
    await DB.open();
    setTool("pen");
    setNetBadge(navigator.onLine);
    setSyncBadge("local");
    const projects = await ensureSeedData();
    state.project = projects[0];
    const drawings = await DB.byIndex("drawings", "project_id", state.project.id);
    await loadDrawing(drawings[0].id);
    if (navigator.onLine && await apiReachable()) {
      $("#syncBadge").title = "Server reachable — press Sync to save to the cloud";
    }
    // show help on first run
    if (!localStorage.getItem("am_seen_help")) {
      $("#helpModal").classList.remove("hidden");
      localStorage.setItem("am_seen_help", "1");
    }
  })();
})();
