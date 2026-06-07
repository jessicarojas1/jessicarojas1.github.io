/* AeroMarkup — unified markup rendering, snapshot flatten, and revision diff.
   drawItem() is the single source of truth for how every mark is drawn, used
   by the live editor, PNG export, the PDF report, and revision compare. */

function loadImage(src) {
  return new Promise((res) => {
    if (!src) return res(null);
    const img = new Image();
    img.onload = () => res(img);
    img.onerror = () => res(null);
    img.src = src;
  });
}

export function typeOf(it) { return it.kind || it.tool || it.type; }

function norm(it) {
  // normalized box for rect/ellipse/cloud (handles negative w/h)
  const x = Math.min(it.x, it.x + (it.width || 0));
  const y = Math.min(it.y, it.y + (it.height || 0));
  return { x, y, w: Math.abs(it.width || 0), h: Math.abs(it.height || 0) };
}

function freehand(ctx, s, color, alpha) {
  const pts = s.points; if (!pts || !pts.length) return;
  ctx.lineJoin = ctx.lineCap = "round";
  ctx.strokeStyle = ctx.fillStyle = color;
  const t = typeOf(s);
  ctx.globalAlpha = alpha != null ? alpha : (t === "highlighter" ? Math.min(s.opacity ?? 1, 0.4) : (s.opacity ?? 1));
  ctx.globalCompositeOperation = (t === "eraser" && !color) ? "destination-out" : "source-over";
  if (pts.length === 1) {
    ctx.beginPath(); ctx.arc(pts[0].x, pts[0].y, (s.width || 3) / 2, 0, Math.PI * 2); ctx.fill();
  } else {
    for (let i = 1; i < pts.length; i++) {
      const a = pts[i - 1], b = pts[i];
      ctx.beginPath();
      ctx.lineWidth = (s.width || 3) * (0.5 + ((a.p ?? 0.5) + (b.p ?? 0.5)) / 2);
      ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
    }
  }
  ctx.globalCompositeOperation = "source-over"; ctx.globalAlpha = 1;
}

function chip(ctx, text, cx, cy, fg) {
  ctx.font = "600 13px ui-monospace, monospace";
  const w = ctx.measureText(text).width;
  ctx.globalAlpha = 1; ctx.fillStyle = "rgba(0,0,0,.72)";
  ctx.fillRect(cx - w / 2 - 5, cy - 16, w + 10, 18);
  ctx.fillStyle = fg || "#fff"; ctx.fillText(text, cx - w / 2, cy - 3);
}

/* Draw any mark. opt: { color, alpha, scaleRatio, units } */
export function drawItem(ctx, it, opt = {}) {
  const type = typeOf(it);
  const color = opt.color || it.color || "#ff5811";
  const alpha = opt.alpha != null ? opt.alpha : (it.opacity ?? 1);
  const sr = opt.scaleRatio, units = opt.units || "in";

  if (type === "pen" || type === "highlighter" || type === "eraser" || type === "marker")
    return freehand(ctx, it, opt.color || it.color, opt.alpha);

  ctx.save();
  ctx.strokeStyle = ctx.fillStyle = color;
  ctx.lineWidth = it.stroke_w || 2;
  ctx.globalAlpha = alpha;
  ctx.lineJoin = ctx.lineCap = "round";

  if (type === "line" || type === "arrow" || type === "measure") {
    ctx.beginPath(); ctx.moveTo(it.x, it.y); ctx.lineTo(it.x2, it.y2); ctx.stroke();
    if (type === "arrow") {
      const a = Math.atan2(it.y2 - it.y, it.x2 - it.x), hl = 8 + (it.stroke_w || 2) * 3;
      ctx.beginPath(); ctx.moveTo(it.x2, it.y2);
      ctx.lineTo(it.x2 - hl * Math.cos(a - 0.4), it.y2 - hl * Math.sin(a - 0.4));
      ctx.lineTo(it.x2 - hl * Math.cos(a + 0.4), it.y2 - hl * Math.sin(a + 0.4));
      ctx.closePath(); ctx.fill();
    }
    if (type === "measure") {
      const px = Math.hypot(it.x2 - it.x, it.y2 - it.y);
      const a = Math.atan2(it.y2 - it.y, it.x2 - it.x) + Math.PI / 2, t = 6;
      for (const [px0, py0] of [[it.x, it.y], [it.x2, it.y2]]) {
        ctx.beginPath();
        ctx.moveTo(px0 - t * Math.cos(a), py0 - t * Math.sin(a));
        ctx.lineTo(px0 + t * Math.cos(a), py0 + t * Math.sin(a)); ctx.stroke();
      }
      chip(ctx, sr ? (px * sr).toFixed(2) + " " + units : Math.round(px) + " px", (it.x + it.x2) / 2, (it.y + it.y2) / 2, color);
    }
  } else if (type === "rect") {
    const b = norm(it); ctx.strokeRect(b.x, b.y, b.w, b.h);
  } else if (type === "ellipse") {
    const b = norm(it);
    ctx.beginPath(); ctx.ellipse(b.x + b.w / 2, b.y + b.h / 2, b.w / 2, b.h / 2, 0, 0, Math.PI * 2); ctx.stroke();
  } else if (type === "cloud") {
    drawCloud(ctx, norm(it));
  } else if (type === "area") {
    const p = it.pts || []; if (p.length >= 2) {
      ctx.beginPath(); ctx.moveTo(p[0].x, p[0].y);
      for (let i = 1; i < p.length; i++) ctx.lineTo(p[i].x, p[i].y);
      ctx.closePath();
      ctx.globalAlpha = alpha * 0.12; ctx.fill();
      ctx.globalAlpha = alpha; ctx.stroke();
      if (p.length >= 3) {
        let A = 0, cx = 0, cy = 0;
        for (let i = 0; i < p.length; i++) {
          const j = (i + 1) % p.length, cr = p[i].x * p[j].y - p[j].x * p[i].y;
          A += cr; cx += (p[i].x + p[j].x) * cr; cy += (p[i].y + p[j].y) * cr;
        }
        A = A / 2; cx /= (6 * A); cy /= (6 * A);
        const areaPx = Math.abs(A);
        const label = sr ? (areaPx * sr * sr).toFixed(2) + " " + units + "²" : Math.round(areaPx) + " px²";
        chip(ctx, label, cx, cy + 8, color);
      }
    }
  } else if (type === "angle") {
    const p = it.pts || []; if (p.length >= 3) {
      const [a, v, b] = p;
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(v.x, v.y); ctx.lineTo(b.x, b.y); ctx.stroke();
      let a1 = Math.atan2(a.y - v.y, a.x - v.x), a2 = Math.atan2(b.y - v.y, b.x - v.x);
      let d = (a2 - a1) * 180 / Math.PI; d = Math.abs(((d + 540) % 360) - 180);
      ctx.beginPath(); ctx.arc(v.x, v.y, 22, a1, a2); ctx.stroke();
      chip(ctx, d.toFixed(1) + "°", v.x, v.y - 6, color);
    }
  } else if (type === "text") {
    ctx.globalAlpha = alpha; ctx.fillStyle = color;
    const fs = it.font_size || 16; ctx.font = `600 ${fs}px var(--font, sans-serif)`;
    (it.text || "").split("\n").forEach((ln, i) => ctx.fillText(ln, it.x, it.y + fs + i * fs * 1.25));
  } else if (type === "note") {
    const lines = (it.text || "").split("\n");
    const w = 196, h = 14 + lines.length * 18;
    ctx.globalAlpha = alpha; ctx.fillStyle = it.color || "#ffcc00";
    roundRect(ctx, it.x, it.y, w, h, 8); ctx.fill();
    ctx.fillStyle = "#15181f"; ctx.font = "14px sans-serif";
    lines.forEach((ln, i) => ctx.fillText(ln.slice(0, 30), it.x + 9, it.y + 20 + i * 18));
  } else if (type === "pin") {
    ctx.globalAlpha = alpha; ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(it.x, it.y - 14, 9, Math.PI, 0); ctx.lineTo(it.x, it.y); ctx.closePath(); ctx.fill();
    ctx.fillStyle = "#fff"; ctx.beginPath(); ctx.arc(it.x, it.y - 14, 3.2, 0, Math.PI * 2); ctx.fill();
  } else if (type === "stamp") {
    const kind = (it.meta && it.meta.kind) || "fai";
    const label = it.text || (kind === "accept" ? "ACCEPTED" : kind === "reject" ? "REJECTED" : "FAI");
    const col = kind === "accept" ? "#3ad07a" : kind === "reject" ? "#ff4d4d" : "#3ba7ff";
    ctx.font = "700 14px ui-monospace, monospace";
    const w = ctx.measureText(label).width + 22, h = 28;
    ctx.globalAlpha = alpha; ctx.strokeStyle = col; ctx.fillStyle = "rgba(0,0,0,.35)";
    ctx.lineWidth = 2.5; roundRect(ctx, it.x, it.y, w, h, 6); ctx.fill(); ctx.stroke();
    ctx.fillStyle = col; ctx.fillText(label, it.x + 11, it.y + 19);
  } else if (type === "balloon") {
    const n = String((it.meta && it.meta.number) != null ? it.meta.number : "•");
    ctx.globalAlpha = alpha; ctx.fillStyle = it.color || "#ff5811";
    ctx.beginPath(); ctx.arc(it.x, it.y, 15, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = "#fff"; ctx.lineWidth = 1.5; ctx.stroke();
    ctx.fillStyle = "#fff"; ctx.font = "700 13px ui-monospace, monospace";
    const tw = ctx.measureText(n).width; ctx.fillText(n, it.x - tw / 2, it.y + 4.5);
  }
  ctx.restore();
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function drawCloud(ctx, b) {
  const r = 9, per = 2 * (b.w + b.h);
  if (per < 8) { ctx.strokeRect(b.x, b.y, b.w, b.h); return; }
  ctx.beginPath();
  const edge = (x1, y1, x2, y2) => {
    const len = Math.hypot(x2 - x1, y2 - y1), n = Math.max(1, Math.round(len / (r * 1.6)));
    for (let i = 0; i < n; i++) {
      const t0 = i / n, t1 = (i + 1) / n;
      const mx = x1 + (x2 - x1) * (t0 + t1) / 2, my = y1 + (y2 - y1) * (t0 + t1) / 2;
      const a0 = Math.atan2(y2 - y1, x2 - x1) - Math.PI / 2;
      ctx.arc(mx, my, len / n / 1.7, a0 - Math.PI, a0);
    }
  };
  edge(b.x, b.y, b.x + b.w, b.y);
  edge(b.x + b.w, b.y, b.x + b.w, b.y + b.h);
  edge(b.x + b.w, b.y + b.h, b.x, b.y + b.h);
  edge(b.x, b.y + b.h, b.x, b.y);
  ctx.stroke();
}

/* ---- snapshot composition ---- */
export async function renderNative(snap, recolor) {
  const w = snap.width || 1600, h = snap.height || 1200;
  const cv = document.createElement("canvas");
  cv.width = w; cv.height = h;
  const ctx = cv.getContext("2d");
  ctx.fillStyle = "#0f1420"; ctx.fillRect(0, 0, w, h);
  const img = await loadImage(snap.background_data);
  if (img) {
    const s = Math.min(w / img.width, h / img.height);
    ctx.drawImage(img, (w - img.width * s) / 2, (h - img.height * s) / 2, img.width * s, img.height * s);
  }
  const opt = { scaleRatio: snap.scale_ratio, units: snap.units };
  for (const it of [...(snap.strokes || []), ...(snap.annotations || [])]) {
    const rc = recolor ? recolor(it) : null;
    if (rc && rc.skip) continue;
    drawItem(ctx, it, { ...opt, color: rc && rc.color, alpha: rc && rc.alpha });
  }
  return cv;
}

export async function renderInto(target, snap, boxW, boxH, recolor) {
  const native = await renderNative(snap, recolor);
  const s = Math.min(boxW / native.width, boxH / native.height);
  target.width = Math.round(native.width * s);
  target.height = Math.round(native.height * s);
  target.getContext("2d").drawImage(native, 0, 0, target.width, target.height);
  return target;
}

export async function flattenPNG(snap) { return (await renderNative(snap)).toDataURL("image/png"); }

const key = (it) => it.client_uid || it.id;
export function snapItems(snap) {
  return [...(snap.strokes || []), ...(snap.annotations || [])];
}
export function diffSnapshots(a, b) {
  const ak = new Map(snapItems(a).map((i) => [key(i), i]));
  const bk = new Map(snapItems(b).map((i) => [key(i), i]));
  const added = [...bk.values()].filter((i) => !ak.has(key(i)));
  const removed = [...ak.values()].filter((i) => !bk.has(key(i)));
  const unchanged = [...bk.keys()].filter((k) => ak.has(k)).length;
  return { added, removed, unchanged };
}
export async function renderDiff(target, a, b, boxW, boxH) {
  const { added, removed } = diffSnapshots(a, b);
  const addedSet = new Set(added.map(key)), removedSet = new Set(removed.map(key));
  const merged = {
    width: b.width, height: b.height, background_data: b.background_data,
    scale_ratio: b.scale_ratio, units: b.units,
    strokes: [...(b.strokes || []), ...(a.strokes || []).filter((s) => removedSet.has(key(s)))],
    annotations: [...(b.annotations || []), ...(a.annotations || []).filter((s) => removedSet.has(key(s)))],
  };
  const recolor = (it) => {
    const k = key(it);
    if (addedSet.has(k)) return { color: "#3ad07a", alpha: 1 };
    if (removedSet.has(k)) return { color: "#ff4d4d", alpha: 0.55 };
    return { color: "#6b7689", alpha: 0.5 };
  };
  return renderInto(target, merged, boxW, boxH, recolor);
}
