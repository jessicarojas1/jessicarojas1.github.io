/* AeroMarkup — self-contained 3D model viewer & annotator.
   No external libraries (air-gap / CSP safe). Imports STL (binary + ASCII)
   and OBJ meshes, renders them with WebGL (headlight Lambert shading), and
   supports orbit / pan / zoom on mouse + touch. You can drop 3D annotation
   pins on the surface (CPU ray-cast), label them, and "Capture View" to hand
   a snapshot to the full 2D markup engine. A built-in sample model loads when
   none is present, so the pipeline is verifiable immediately. */
import { put, byIndex, del, uid } from "./store.js";
import { icon } from "./icons.js";
import { $, $$, el, toast, esc } from "./ui.js";

/* ───────────── tiny vec/mat math (column-major, WebGL order) ───────────── */
const V = {
  sub: (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]],
  add: (a, b) => [a[0] + b[0], a[1] + b[1], a[2] + b[2]],
  scale: (a, s) => [a[0] * s, a[1] * s, a[2] * s],
  cross: (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]],
  dot: (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2],
  len: (a) => Math.hypot(a[0], a[1], a[2]),
  norm: (a) => { const l = Math.hypot(a[0], a[1], a[2]) || 1; return [a[0] / l, a[1] / l, a[2] / l]; },
};
const M = {
  mul(a, b) { const o = new Array(16); for (let c = 0; c < 4; c++) for (let r = 0; r < 4; r++) { let s = 0; for (let k = 0; k < 4; k++) s += a[k * 4 + r] * b[c * 4 + k]; o[c * 4 + r] = s; } return o; },
  perspective(fovy, asp, n, f) { const t = 1 / Math.tan(fovy / 2); return [t / asp, 0, 0, 0, 0, t, 0, 0, 0, 0, (f + n) / (n - f), -1, 0, 0, (2 * f * n) / (n - f), 0]; },
  lookAt(eye, c, up) {
    const z = V.norm(V.sub(eye, c)), x = V.norm(V.cross(up, z)), y = V.cross(z, x);
    return [x[0], y[0], z[0], 0, x[1], y[1], z[1], 0, x[2], y[2], z[2], 0, -V.dot(x, eye), -V.dot(y, eye), -V.dot(z, eye), 1];
  },
  mulVec(m, v) { const o = [0, 0, 0, 0]; for (let r = 0; r < 4; r++) o[r] = m[r] * v[0] + m[4 + r] * v[1] + m[8 + r] * v[2] + m[12 + r] * v[3]; return o; },
  invert(m) {
    const inv = new Array(16), a = m;
    inv[0] = a[5] * a[10] * a[15] - a[5] * a[11] * a[14] - a[9] * a[6] * a[15] + a[9] * a[7] * a[14] + a[13] * a[6] * a[11] - a[13] * a[7] * a[10];
    inv[4] = -a[4] * a[10] * a[15] + a[4] * a[11] * a[14] + a[8] * a[6] * a[15] - a[8] * a[7] * a[14] - a[12] * a[6] * a[11] + a[12] * a[7] * a[10];
    inv[8] = a[4] * a[9] * a[15] - a[4] * a[11] * a[13] - a[8] * a[5] * a[15] + a[8] * a[7] * a[13] + a[12] * a[5] * a[11] - a[12] * a[7] * a[9];
    inv[12] = -a[4] * a[9] * a[14] + a[4] * a[10] * a[13] + a[8] * a[5] * a[14] - a[8] * a[6] * a[13] - a[12] * a[5] * a[10] + a[12] * a[6] * a[9];
    inv[1] = -a[1] * a[10] * a[15] + a[1] * a[11] * a[14] + a[9] * a[2] * a[15] - a[9] * a[3] * a[14] - a[13] * a[2] * a[11] + a[13] * a[3] * a[10];
    inv[5] = a[0] * a[10] * a[15] - a[0] * a[11] * a[14] - a[8] * a[2] * a[15] + a[8] * a[3] * a[14] + a[12] * a[2] * a[11] - a[12] * a[3] * a[10];
    inv[9] = -a[0] * a[9] * a[15] + a[0] * a[11] * a[13] + a[8] * a[1] * a[15] - a[8] * a[3] * a[13] - a[12] * a[1] * a[11] + a[12] * a[3] * a[9];
    inv[13] = a[0] * a[9] * a[14] - a[0] * a[10] * a[13] - a[8] * a[1] * a[14] + a[8] * a[2] * a[13] + a[12] * a[1] * a[10] - a[12] * a[2] * a[9];
    inv[2] = a[1] * a[6] * a[15] - a[1] * a[7] * a[14] - a[5] * a[2] * a[15] + a[5] * a[3] * a[14] + a[13] * a[2] * a[7] - a[13] * a[3] * a[6];
    inv[6] = -a[0] * a[6] * a[15] + a[0] * a[7] * a[14] + a[4] * a[2] * a[15] - a[4] * a[3] * a[14] - a[12] * a[2] * a[7] + a[12] * a[3] * a[6];
    inv[10] = a[0] * a[5] * a[15] - a[0] * a[7] * a[13] - a[4] * a[1] * a[15] + a[4] * a[3] * a[13] + a[12] * a[1] * a[7] - a[12] * a[3] * a[5];
    inv[14] = -a[0] * a[5] * a[14] + a[0] * a[6] * a[13] + a[4] * a[1] * a[14] - a[4] * a[2] * a[13] - a[12] * a[1] * a[6] + a[12] * a[2] * a[5];
    inv[3] = -a[1] * a[6] * a[11] + a[1] * a[7] * a[10] + a[5] * a[2] * a[11] - a[5] * a[3] * a[10] - a[9] * a[2] * a[7] + a[9] * a[3] * a[6];
    inv[7] = a[0] * a[6] * a[11] - a[0] * a[7] * a[10] - a[4] * a[2] * a[11] + a[4] * a[3] * a[10] + a[8] * a[2] * a[7] - a[8] * a[3] * a[6];
    inv[11] = -a[0] * a[5] * a[11] + a[0] * a[7] * a[9] + a[4] * a[1] * a[11] - a[4] * a[3] * a[9] - a[8] * a[1] * a[7] + a[8] * a[3] * a[5];
    inv[15] = a[0] * a[5] * a[10] - a[0] * a[6] * a[9] - a[4] * a[1] * a[10] + a[4] * a[2] * a[9] + a[8] * a[1] * a[6] - a[8] * a[2] * a[5];
    let det = a[0] * inv[0] + a[1] * inv[4] + a[2] * inv[8] + a[3] * inv[12];
    if (!det) return null; det = 1 / det;
    return inv.map((x) => x * det);
  },
};

/* ───────────── mesh builders / parsers ───────────── */
function meshFromTris(tris) {
  // tris: flat array of triangles [[ax,ay,az],[bx,by,bz],[cx,cy,cz]]
  const pos = [], nor = [];
  let min = [Infinity, Infinity, Infinity], max = [-Infinity, -Infinity, -Infinity];
  for (const t of tris) {
    const n = V.norm(V.cross(V.sub(t[1], t[0]), V.sub(t[2], t[0])));
    for (const p of t) {
      pos.push(p[0], p[1], p[2]); nor.push(n[0], n[1], n[2]);
      for (let i = 0; i < 3; i++) { min[i] = Math.min(min[i], p[i]); max[i] = Math.max(max[i], p[i]); }
    }
  }
  // center at origin
  const c = [(min[0] + max[0]) / 2, (min[1] + max[1]) / 2, (min[2] + max[2]) / 2];
  for (let i = 0; i < pos.length; i += 3) { pos[i] -= c[0]; pos[i + 1] -= c[1]; pos[i + 2] -= c[2]; }
  const size = V.len(V.sub(max, min)) || 1;
  return { position: new Float32Array(pos), normal: new Float32Array(nor), triCount: tris.length, size };
}

function parseSTL(buf) {
  const dv = new DataView(buf);
  // ASCII?
  const head = new TextDecoder().decode(new Uint8Array(buf, 0, Math.min(buf.byteLength, 256))).trim();
  if (head.startsWith("solid") && head.includes("facet")) {
    const text = new TextDecoder().decode(new Uint8Array(buf));
    const verts = [...text.matchAll(/vertex\s+([\-\d.eE+]+)\s+([\-\d.eE+]+)\s+([\-\d.eE+]+)/g)].map((m) => [+m[1], +m[2], +m[3]]);
    const tris = []; for (let i = 0; i + 2 < verts.length; i += 3) tris.push([verts[i], verts[i + 1], verts[i + 2]]);
    return meshFromTris(tris);
  }
  const n = dv.getUint32(80, true); const tris = []; let o = 84;
  for (let i = 0; i < n && o + 50 <= buf.byteLength; i++, o += 50) {
    const v = (k) => [dv.getFloat32(o + k, true), dv.getFloat32(o + k + 4, true), dv.getFloat32(o + k + 8, true)];
    tris.push([v(12), v(24), v(36)]);
  }
  return meshFromTris(tris);
}

function parseOBJ(text) {
  const vs = [], tris = [];
  for (const line of text.split("\n")) {
    const p = line.trim().split(/\s+/);
    if (p[0] === "v") vs.push([+p[1], +p[2], +p[3]]);
    else if (p[0] === "f") {
      const idx = p.slice(1).map((s) => { let i = parseInt(s.split("/")[0], 10); return i < 0 ? vs.length + i : i - 1; });
      for (let i = 1; i + 1 < idx.length; i++) if (vs[idx[0]] && vs[idx[i]] && vs[idx[i + 1]]) tris.push([vs[idx[0]], vs[idx[i]], vs[idx[i + 1]]]);
    }
  }
  return meshFromTris(tris);
}

function sampleAircraft() {
  // low-poly airframe from boxes (fuselage, wings, tail) so the viewer always
  // has something relevant to show / annotate.
  const tris = [];
  const box = (cx, cy, cz, sx, sy, sz) => {
    const x0 = cx - sx, x1 = cx + sx, y0 = cy - sy, y1 = cy + sy, z0 = cz - sz, z1 = cz + sz;
    const c = [[x0, y0, z0], [x1, y0, z0], [x1, y1, z0], [x0, y1, z0], [x0, y0, z1], [x1, y0, z1], [x1, y1, z1], [x0, y1, z1]];
    const f = [[0, 1, 2, 3], [5, 4, 7, 6], [4, 0, 3, 7], [1, 5, 6, 2], [4, 5, 1, 0], [3, 2, 6, 7]];
    for (const q of f) { tris.push([c[q[0]], c[q[1]], c[q[2]]]); tris.push([c[q[0]], c[q[2]], c[q[3]]]); }
  };
  box(0, 0, 0, 3.2, 0.5, 0.5);      // fuselage
  box(0, 0, 0, 0.6, 0.15, 3.2);     // main wing
  box(-2.6, 0.2, 0, 0.4, 0.1, 1.1); // tailplane
  box(-2.8, 0.6, 0, 0.3, 0.6, 0.1); // vertical stabilizer
  box(2.9, 0, 0, 0.5, 0.45, 0.45);  // nose
  return meshFromTris(tris);
}

/* ───────────── Viewer ───────────── */
export class Viewer3D {
  constructor(container, drawing, opts = {}) {
    this.c = container; this.drawing = drawing; this.opts = opts;
    this.tool = "orbit";
    this.cam = { theta: 0.9, phi: 1.1, radius: 8, target: [0, 0, 0] };
    this.pins = [];
    this.selected = null;
    this.mesh = null;
    this.wire = false;
    this.pointers = new Map(); this.last = null; this.pinch = null;
  }

  async mount() {
    this.c.innerHTML = `
      <div class="editor">
        <div class="tool-rail">
          <button class="tool active" data-t="orbit" title="Orbit (rotate)">${icon("pan", 20)}</button>
          <button class="tool" data-t="pin" title="Drop 3D pin">${icon("pin", 20)}</button>
          <div class="tool-sep"></div>
          <button class="tool" data-a="fit" title="Fit / reset view">${icon("fit", 20)}</button>
          <button class="tool" data-a="wire" title="Wireframe">${icon("layers", 20)}</button>
          <button class="tool" data-a="load" title="Load model (STL / OBJ)">${icon("image", 20)}</button>
          <button class="tool" data-a="capture" title="Capture view → 2D markup">${icon("export", 20)}</button>
          <button class="tool" data-a="help" title="Help">${icon("help", 20)}</button>
        </div>
        <div class="stage" style="cursor:grab">
          <canvas data-gl style="position:absolute;inset:0;width:100%;height:100%;touch-action:none"></canvas>
          <div class="v3d-overlay" data-ov></div>
          <div class="zoom-readout" data-msg></div>
        </div>
        <div class="statusbar">
          <span class="status-seg" data-st-tris>—</span>
          <span class="status-seg push" data-st-pins>0 pins</span>
          <span class="status-seg" data-st-fmt></span>
        </div>
        <div class="right-panel" data-panel></div>
        <input type="file" accept=".stl,.obj,model/stl,model/obj" data-file hidden />
        <div class="note-editor hidden" data-note>
          <textarea class="textarea" data-note-text placeholder="Pin note…"></textarea>
          <div class="modal-foot">
            <button class="btn btn-ghost btn-sm" data-note-cancel>Cancel</button>
            <button class="btn btn-primary btn-sm" data-note-save>Save</button>
          </div>
        </div>
      </div>`;
    this.stage = $(".stage", this.c);
    this.canvas = $("[data-gl]", this.c);
    this.ov = $("[data-ov]", this.c);
    this.panel = $("[data-panel]", this.c);

    if (!this._initGL()) {
      $("[data-msg]", this.c).textContent = "WebGL is not available in this browser.";
      $("[data-msg]", this.c).style.cssText += ";position:absolute;inset:auto 0 50% 0;text-align:center;font-size:1rem";
      return;
    }
    this._wire();
    this.pins = (await byIndex("annotations", "drawing_id", this.drawing.id)).filter((a) => (a.kind || a.type) === "pin3d");
    await this._loadInitialModel();
    this._renderPanel();
    this._resize();
    window.addEventListener("resize", this._resizeBound = () => this._resize());
  }

  destroy() { if (this._resizeBound) window.removeEventListener("resize", this._resizeBound); }

  _initGL() {
    const gl = this.canvas.getContext("webgl", { antialias: true, preserveDrawingBuffer: true })
      || this.canvas.getContext("experimental-webgl", { preserveDrawingBuffer: true });
    if (!gl) return false;
    this.gl = gl;
    const vs = `attribute vec3 aPos; attribute vec3 aNor;
      uniform mat4 uProj, uView; varying vec3 vN;
      void main(){ vN = mat3(uView)*aNor; gl_Position = uProj*uView*vec4(aPos,1.0); }`;
    const fs = `precision mediump float; varying vec3 vN; uniform vec3 uColor;
      void main(){ vec3 n = normalize(vN); float d = max(dot(n, normalize(vec3(0.35,0.55,1.0))),0.0);
        gl_FragColor = vec4(uColor*(0.38 + 0.7*d), 1.0); }`;
    const sh = (t, src) => { const s = gl.createShader(t); gl.shaderSource(s, src); gl.compileShader(s); return s; };
    const prog = gl.createProgram();
    gl.attachShader(prog, sh(gl.VERTEX_SHADER, vs)); gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, fs));
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return false;
    gl.useProgram(prog); this.prog = prog;
    this.loc = {
      aPos: gl.getAttribLocation(prog, "aPos"), aNor: gl.getAttribLocation(prog, "aNor"),
      uProj: gl.getUniformLocation(prog, "uProj"), uView: gl.getUniformLocation(prog, "uView"),
      uColor: gl.getUniformLocation(prog, "uColor"),
    };
    gl.enable(gl.DEPTH_TEST); gl.clearColor(0.06, 0.08, 0.11, 1);
    this.posBuf = gl.createBuffer(); this.norBuf = gl.createBuffer(); this.edgeBuf = gl.createBuffer();
    return true;
  }

  async _loadInitialModel() {
    try {
      if (this.drawing.model_data) {
        await this._setModelFromData(this.drawing.model_data, this.drawing.model_format || "stl");
      } else {
        this._setMesh(sampleAircraft(), "sample");
        $("[data-msg]", this.c).textContent = "Sample model — load your STL/OBJ via the toolbar.";
        setTimeout(() => ($("[data-msg]", this.c).textContent = ""), 4000);
      }
    } catch (e) { console.error(e); toast("Could not load model: " + e.message, "error"); this._setMesh(sampleAircraft(), "sample"); }
  }

  async _setModelFromData(data, fmt) {
    let mesh;
    if (fmt === "obj") mesh = parseOBJ(typeof data === "string" && data.startsWith("data:") ? atob(data.split(",")[1]) : data);
    else {
      const buf = await (await fetch(data)).arrayBuffer();
      mesh = parseSTL(buf);
    }
    this._setMesh(mesh, fmt);
  }

  _setMesh(mesh, fmt) {
    if (!mesh || !mesh.triCount) { toast("Model has no geometry.", "error"); return; }
    this.mesh = mesh; const gl = this.gl;
    gl.bindBuffer(gl.ARRAY_BUFFER, this.posBuf); gl.bufferData(gl.ARRAY_BUFFER, mesh.position, gl.STATIC_DRAW);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.norBuf); gl.bufferData(gl.ARRAY_BUFFER, mesh.normal, gl.STATIC_DRAW);
    // edges for wireframe
    const ep = []; const p = mesh.position;
    for (let i = 0; i < p.length; i += 9) {
      const a = [p[i], p[i + 1], p[i + 2]], b = [p[i + 3], p[i + 4], p[i + 5]], c = [p[i + 6], p[i + 7], p[i + 8]];
      ep.push(...a, ...b, ...b, ...c, ...c, ...a);
    }
    this.edgeArr = new Float32Array(ep);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.edgeBuf); gl.bufferData(gl.ARRAY_BUFFER, this.edgeArr, gl.STATIC_DRAW);
    this.cam.radius = mesh.size * 1.3; this.cam.target = [0, 0, 0];
    const t = $("[data-st-tris]", this.c); if (t) t.textContent = mesh.triCount.toLocaleString() + " triangles";
    const f = $("[data-st-fmt]", this.c); if (f) f.textContent = (fmt || "").toUpperCase();
    this._render();
  }

  /* camera + matrices */
  _eye() {
    const { theta, phi, radius, target } = this.cam;
    const sp = Math.sin(phi);
    return [target[0] + radius * sp * Math.cos(theta), target[1] + radius * Math.cos(phi), target[2] + radius * sp * Math.sin(theta)];
  }
  _matrices() {
    const w = this.canvas.width, h = this.canvas.height;
    const proj = M.perspective(Math.PI / 4, w / h || 1, 0.05, 5000);
    const view = M.lookAt(this._eye(), this.cam.target, [0, 1, 0]);
    return { proj, view, pv: M.mul(proj, view), w, h };
  }

  _resize() {
    const r = this.stage.getBoundingClientRect(); const dpr = Math.min(window.devicePixelRatio || 1, 2);
    this.canvas.width = Math.max(1, Math.round(r.width * dpr)); this.canvas.height = Math.max(1, Math.round(r.height * dpr));
    this._render();
  }

  _render() {
    const gl = this.gl; if (!gl || !this.mesh) return;
    const { proj, view } = this._matrices();
    gl.viewport(0, 0, this.canvas.width, this.canvas.height);
    gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
    gl.uniformMatrix4fv(this.loc.uProj, false, proj);
    gl.uniformMatrix4fv(this.loc.uView, false, view);
    const bind = (buf) => { gl.bindBuffer(gl.ARRAY_BUFFER, buf); gl.enableVertexAttribArray(this.loc.aPos); gl.vertexAttribPointer(this.loc.aPos, 3, gl.FLOAT, false, 0, 0); };
    if (this.wire) {
      bind(this.edgeBuf); gl.disableVertexAttribArray(this.loc.aNor); gl.vertexAttrib3f(this.loc.aNor, 0, 0, 1);
      gl.uniform3f(this.loc.uColor, 0.6, 0.7, 0.9);
      gl.drawArrays(gl.LINES, 0, this.edgeArr.length / 3);
    } else {
      bind(this.posBuf);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.norBuf); gl.enableVertexAttribArray(this.loc.aNor); gl.vertexAttribPointer(this.loc.aNor, 3, gl.FLOAT, false, 0, 0);
      gl.uniform3f(this.loc.uColor, 0.72, 0.78, 0.86);
      gl.drawArrays(gl.TRIANGLES, 0, this.mesh.position.length / 3);
    }
    this._layoutPins();
  }

  _layoutPins() {
    const { pv, w, h } = this._matrices();
    this.ov.innerHTML = "";
    this.pins.forEach((pin, i) => {
      const cl = M.mulVec(pv, [pin.x, pin.y, pin.z, 1]);
      if (cl[3] <= 0) return;
      const sx = (cl[0] / cl[3] * 0.5 + 0.5) * this.stage.clientWidth;
      const sy = (1 - (cl[1] / cl[3] * 0.5 + 0.5)) * this.stage.clientHeight;
      const node = el(`<div class="v3d-pin ${this.selected === pin.id ? "active" : ""}" style="left:${sx}px;top:${sy}px;background:${esc(pin.color || "#ff5811")}" title="${esc(pin.text || "")}">${i + 1}</div>`);
      node.addEventListener("click", (e) => { e.stopPropagation(); this.selected = pin.id; this._renderPanel(); this._editPin(pin); });
      this.ov.appendChild(node);
    });
    const pc = $("[data-st-pins]", this.c); if (pc) pc.textContent = this.pins.length + " pins";
  }

  /* interaction */
  _wire() {
    $$(".tool[data-t]", this.c).forEach((b) => b.addEventListener("click", () => { this.tool = b.dataset.t; $$(".tool[data-t]", this.c).forEach((x) => x.classList.toggle("active", x === b)); this.stage.style.cursor = this.tool === "pin" ? "crosshair" : "grab"; }));
    $$(".tool[data-a]", this.c).forEach((b) => b.addEventListener("click", () => this._action(b.dataset.a)));
    $("[data-file]", this.c).addEventListener("change", (e) => this._loadFile(e));
    $("[data-note-cancel]", this.c).addEventListener("click", () => this._closeNote());
    $("[data-note-save]", this.c).addEventListener("click", () => this._saveNote());
    const cv = this.canvas;
    cv.addEventListener("pointerdown", (e) => this._down(e));
    cv.addEventListener("pointermove", (e) => this._move(e));
    cv.addEventListener("pointerup", (e) => this._up(e));
    cv.addEventListener("pointercancel", (e) => this._up(e));
    cv.addEventListener("wheel", (e) => { e.preventDefault(); this.cam.radius = Math.max(0.2, this.cam.radius * (e.deltaY < 0 ? 0.9 : 1.1)); this._render(); }, { passive: false });
  }
  _down(e) {
    this.canvas.setPointerCapture(e.pointerId); this.pointers.set(e.pointerId, e); this.last = { x: e.clientX, y: e.clientY, pan: e.shiftKey || e.button === 2 };
    if (this.pointers.size === 2) { const [a, b] = [...this.pointers.values()]; this.pinch = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }
    if (this.tool === "pin" && this.pointers.size === 1) this._dropPin(e);
  }
  _move(e) {
    if (!this.pointers.has(e.pointerId)) return;
    this.pointers.set(e.pointerId, e);
    if (this.pointers.size === 2 && this.pinch != null) {
      const [a, b] = [...this.pointers.values()]; const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
      this.cam.radius = Math.max(0.2, this.cam.radius * (this.pinch / (d || 1))); this.pinch = d; this._render(); return;
    }
    if (!this.last || this.tool === "pin") return;
    const dx = e.clientX - this.last.x, dy = e.clientY - this.last.y; this.last = { x: e.clientX, y: e.clientY, pan: this.last.pan };
    if (this.last.pan) {
      const right = V.norm(V.cross(V.sub(this.cam.target, this._eye()), [0, 1, 0]));
      const up = V.norm(V.cross(right, V.sub(this.cam.target, this._eye())));
      const s = this.cam.radius * 0.0015;
      this.cam.target = V.add(this.cam.target, V.add(V.scale(right, -dx * s), V.scale(up, dy * s)));
    } else {
      this.cam.theta += dx * 0.01; this.cam.phi = Math.max(0.05, Math.min(Math.PI - 0.05, this.cam.phi - dy * 0.01));
    }
    this._render();
  }
  _up(e) { this.pointers.delete(e.pointerId); if (this.pointers.size < 2) this.pinch = null; if (!this.pointers.size) this.last = null; }

  _ray(e) {
    const r = this.stage.getBoundingClientRect();
    const nx = ((e.clientX - r.left) / r.width) * 2 - 1, ny = 1 - ((e.clientY - r.top) / r.height) * 2;
    const inv = M.invert(this._matrices().pv); if (!inv) return null;
    const un = (z) => { const p = M.mulVec(inv, [nx, ny, z, 1]); return [p[0] / p[3], p[1] / p[3], p[2] / p[3]]; };
    const o = un(-1), f = un(1); return { o, d: V.norm(V.sub(f, o)) };
  }
  _dropPin(e) {
    const ray = this._ray(e); if (!ray || !this.mesh) return;
    const p = this.mesh.position; let best = Infinity, hit = null;
    for (let i = 0; i < p.length; i += 9) {
      const t = this._rayTri(ray.o, ray.d, [p[i], p[i + 1], p[i + 2]], [p[i + 3], p[i + 4], p[i + 5]], [p[i + 6], p[i + 7], p[i + 8]]);
      if (t != null && t < best) { best = t; hit = V.add(ray.o, V.scale(ray.d, t)); }
    }
    if (!hit) { toast("No surface hit — aim at the model.", "info"); return; }
    const pin = { id: uid(), client_uid: uid(), drawing_id: this.drawing.id, kind: "pin3d", type: "pin3d", x: hit[0], y: hit[1], z: hit[2], color: "#ff5811", text: "" };
    this.pins.push(pin); put("annotations", pin); this.opts.onDirty && this.opts.onDirty();
    this.selected = pin.id; this._render(); this._renderPanel(); this._editPin(pin);
  }
  _rayTri(o, d, a, b, c) {
    const e1 = V.sub(b, a), e2 = V.sub(c, a), pv = V.cross(d, e2), det = V.dot(e1, pv);
    if (Math.abs(det) < 1e-7) return null; const inv = 1 / det;
    const tv = V.sub(o, a), u = V.dot(tv, pv) * inv; if (u < 0 || u > 1) return null;
    const qv = V.cross(tv, e1), v = V.dot(d, qv) * inv; if (v < 0 || u + v > 1) return null;
    const t = V.dot(e2, qv) * inv; return t > 1e-4 ? t : null;
  }

  /* pin editing */
  _editPin(pin) {
    this._editing = pin; const ed = $("[data-note]", this.c);
    const r = this.stage.getBoundingClientRect();
    ed.style.left = clampN(r.left + 60, 8, window.innerWidth - 300) + "px";
    ed.style.top = clampN(r.top + 60, 70, window.innerHeight - 200) + "px";
    ed.classList.remove("hidden");
    const ta = $("[data-note-text]", this.c); ta.value = pin.text || ""; ta.focus();
  }
  _closeNote() { $("[data-note]", this.c).classList.add("hidden"); this._editing = null; }
  _saveNote() {
    if (this._editing) { this._editing.text = $("[data-note-text]", this.c).value; put("annotations", this._editing); this.opts.onDirty && this.opts.onDirty(); this._render(); this._renderPanel(); }
    this._closeNote();
  }
  async _deletePin(id) {
    const pin = this.pins.find((p) => p.id === id); if (!pin) return;
    await del("annotations", id); this.pins = this.pins.filter((p) => p.id !== id);
    if (this.selected === id) this.selected = null;
    this.opts.onDirty && this.opts.onDirty(); this._render(); this._renderPanel();
  }

  _renderPanel() {
    const sel = this.pins.find((p) => p.id === this.selected);
    this.panel.innerHTML = `
      <div class="panel-section">
        <div class="panel-title">3D Annotations</div>
        ${this.pins.length ? this.pins.map((p, i) => `<div class="layer-row ${p.id === this.selected ? "active" : ""}" data-pin="${p.id}">
          <span class="layer-color" style="background:${esc(p.color || "#ff5811")}"></span>
          <span class="layer-name">${i + 1}. ${esc(p.text || "(no note)")}</span>
          <button class="layer-lock" data-del="${p.id}" title="Delete">${icon("trash", 14)}</button>
        </div>`).join("") : `<div class="insp-empty">Use the Pin tool, then tap the model surface to drop an annotation.</div>`}
      </div>
      ${sel ? `<div class="panel-section">
        <div class="panel-title">Selected pin</div>
        <div class="prop-group"><div class="prop-label">Color</div>
          <input type="color" class="input" data-pin-color value="${esc(sel.color || "#ff5811")}"></div>
        <div class="insp-actions">
          <button class="btn btn-ghost btn-sm" data-edit>${icon("edit", 14)} Edit note</button>
          <button class="btn btn-danger btn-sm" data-pin-del>${icon("trash", 14)} Delete</button>
        </div></div>` : ""}`;
    $$("[data-pin]", this.panel).forEach((r) => r.addEventListener("click", (e) => { if (e.target.closest("[data-del]")) return; this.selected = r.dataset.pin; this._render(); this._renderPanel(); }));
    $$("[data-del]", this.panel).forEach((b) => b.addEventListener("click", (e) => { e.stopPropagation(); this._deletePin(b.dataset.del); }));
    const pc = $("[data-pin-color]", this.panel); pc && pc.addEventListener("input", (e) => { if (sel) { sel.color = e.target.value; put("annotations", sel); this._render(); } });
    const ed = $("[data-edit]", this.panel); ed && ed.addEventListener("click", () => sel && this._editPin(sel));
    const pd = $("[data-pin-del]", this.panel); pd && pd.addEventListener("click", () => sel && this._deletePin(sel.id));
  }

  _action(a) {
    if (a === "fit") { this.cam = { theta: 0.9, phi: 1.1, radius: (this.mesh ? this.mesh.size * 1.3 : 8), target: [0, 0, 0] }; this._render(); }
    else if (a === "wire") { this.wire = !this.wire; this._render(); }
    else if (a === "load") $("[data-file]", this.c).click();
    else if (a === "capture") this._capture();
    else if (a === "help") toast("Drag to orbit · Shift-drag / two-finger to pan · Wheel / pinch to zoom · Pin tool to annotate.", "info", 6000);
  }
  _loadFile(e) {
    const f = e.target.files[0]; if (!f) return;
    const fmt = /\.obj$/i.test(f.name) ? "obj" : "stl";
    const reader = new FileReader();
    reader.onload = async () => {
      try {
        if (fmt === "obj") { this._setMesh(parseOBJ(reader.result), "obj"); this.drawing.model_data = reader.result; }
        else { this._setMesh(parseSTL(reader.result), "stl"); this.drawing.model_data = await blobToDataURL(f); }
        this.drawing.model_format = fmt; this.drawing.model_name = f.name; this.drawing.view_kind = "3d";
        await put("drawings", this.drawing); this.opts.onDirty && this.opts.onDirty();
        toast("Model loaded: " + f.name, "success");
      } catch (err) { console.error(err); toast("Failed to parse model: " + err.message, "error"); }
    };
    if (fmt === "obj") reader.readAsText(f); else reader.readAsArrayBuffer(f);
    e.target.value = "";
  }
  _capture() {
    this._render(); // ensure current frame in buffer
    const gl = this.canvas;
    const out = document.createElement("canvas"); out.width = gl.width; out.height = gl.height;
    const o = out.getContext("2d"); o.drawImage(gl, 0, 0);
    // bake pin markers
    const { pv } = this._matrices();
    this.pins.forEach((pin, i) => {
      const cl = M.mulVec(pv, [pin.x, pin.y, pin.z, 1]); if (cl[3] <= 0) return;
      const x = (cl[0] / cl[3] * 0.5 + 0.5) * out.width, y = (1 - (cl[1] / cl[3] * 0.5 + 0.5)) * out.height;
      o.fillStyle = pin.color || "#ff5811"; o.beginPath(); o.arc(x, y, 14, 0, Math.PI * 2); o.fill();
      o.fillStyle = "#fff"; o.font = "bold 15px sans-serif"; o.textAlign = "center"; o.textBaseline = "middle"; o.fillText(String(i + 1), x, y);
    });
    if (this.opts.onCapture) this.opts.onCapture(out.toDataURL("image/png"));
  }
}

function clampN(v, a, b) { return Math.min(b, Math.max(a, v)); }
function blobToDataURL(blob) { return new Promise((res) => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(blob); }); }
