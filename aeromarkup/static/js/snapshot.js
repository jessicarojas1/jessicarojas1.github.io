/* AeroMarkup — snapshot rendering, flattening, and revision diff.
   Pure rendering of a drawing's markup onto a canvas (no DOM, no libs) so we
   can: (a) flatten to PNG for the PDF report, and (b) render historical
   revisions side-by-side and as an overlay diff. */

function loadImage(src) {
  return new Promise((res) => {
    if (!src) return res(null);
    const img = new Image();
    img.onload = () => res(img);
    img.onerror = () => res(null);
    img.src = src;
  });
}

function strokePath(ctx, s, color, alpha) {
  const pts = s.points; if (!pts || !pts.length) return;
  ctx.lineJoin = ctx.lineCap = "round";
  ctx.strokeStyle = ctx.fillStyle = color || s.color;
  ctx.globalAlpha = alpha != null ? alpha : (s.tool === "highlighter" ? Math.min(s.opacity ?? 1, 0.4) : (s.opacity ?? 1));
  ctx.globalCompositeOperation = s.tool === "eraser" && !color ? "destination-out" : "source-over";
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

function shape(ctx, a, color, alpha, scaleRatio, units) {
  ctx.save();
  ctx.strokeStyle = ctx.fillStyle = color || a.color;
  ctx.lineWidth = a.stroke_w || 2; ctx.globalAlpha = alpha != null ? alpha : (a.opacity ?? 1);
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
      const px = Math.hypot(a.x2 - a.x, a.y2 - a.y);
      const label = scaleRatio ? (px * scaleRatio).toFixed(2) + " " + (units || "in") : Math.round(px) + " px";
      const mx = (a.x + a.x2) / 2, my = (a.y + a.y2) / 2;
      ctx.font = "600 13px ui-monospace, monospace";
      const tw = ctx.measureText(label).width;
      ctx.globalAlpha = 1; ctx.fillStyle = "rgba(0,0,0,.72)";
      ctx.fillRect(mx - tw / 2 - 5, my - 18, tw + 10, 18);
      ctx.fillStyle = color || "#fff"; ctx.fillText(label, mx - tw / 2, my - 5);
    }
  } else if (a.kind === "rect") {
    ctx.strokeRect(a.x, a.y, a.width, a.height);
  } else if (a.kind === "ellipse") {
    ctx.beginPath();
    ctx.ellipse(a.x + a.width / 2, a.y + a.height / 2, Math.abs(a.width / 2), Math.abs(a.height / 2), 0, 0, Math.PI * 2);
    ctx.stroke();
  } else if (a.kind === "note") {
    ctx.globalAlpha = 1; ctx.fillStyle = color || a.color || "#ffcc00";
    ctx.fillRect(a.x, a.y, 190, 26);
    ctx.fillStyle = "#111"; ctx.font = "14px sans-serif";
    ctx.fillText((a.text || "").slice(0, 28), a.x + 6, a.y + 17);
  } else if (a.kind === "pin") {
    ctx.beginPath(); ctx.fillStyle = color || a.color || "#ff5811";
    ctx.arc(a.x, a.y, 7, 0, Math.PI * 2); ctx.fill();
  }
  ctx.restore();
}

/* Render a snapshot into an offscreen canvas at the drawing's native size. */
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
  for (const s of snap.strokes || []) {
    const rc = recolor ? recolor(s) : null;
    if (rc && rc.skip) continue;
    if (s.kind) shape(ctx, s, rc && rc.color, rc && rc.alpha, snap.scale_ratio, snap.units);
    else strokePath(ctx, s, rc && rc.color, rc && rc.alpha);
  }
  for (const a of snap.annotations || []) {
    const rc = recolor ? recolor(a) : null;
    if (rc && rc.skip) continue;
    shape(ctx, a, rc && rc.color, rc && rc.alpha, snap.scale_ratio, snap.units);
  }
  return cv;
}

/* Render a snapshot scaled to fit a target canvas (for side-by-side compare). */
export async function renderInto(targetCanvas, snap, boxW, boxH, recolor) {
  const native = await renderNative(snap, recolor);
  const s = Math.min(boxW / native.width, boxH / native.height);
  targetCanvas.width = Math.round(native.width * s);
  targetCanvas.height = Math.round(native.height * s);
  const ctx = targetCanvas.getContext("2d");
  ctx.drawImage(native, 0, 0, targetCanvas.width, targetCanvas.height);
  return targetCanvas;
}

export async function flattenPNG(snap) {
  return (await renderNative(snap)).toDataURL("image/png");
}

const key = (it) => it.client_uid || it.id;

export function snapItems(snap) {
  return [
    ...(snap.strokes || []).map((s) => ({ ...s, _bucket: "stroke" })),
    ...(snap.annotations || []).map((a) => ({ ...a, _bucket: "anno" })),
  ];
}

/* Diff two snapshots by stable id. */
export function diffSnapshots(a, b) {
  const ak = new Map(snapItems(a).map((i) => [key(i), i]));
  const bk = new Map(snapItems(b).map((i) => [key(i), i]));
  const added = [...bk.values()].filter((i) => !ak.has(key(i)));
  const removed = [...ak.values()].filter((i) => !bk.has(key(i)));
  const unchanged = [...bk.keys()].filter((k) => ak.has(k)).length;
  return { added, removed, unchanged };
}

/* Build an overlay-diff snapshot: unchanged ghosted, added green, removed red. */
export async function renderDiff(targetCanvas, a, b, boxW, boxH) {
  const { added, removed } = diffSnapshots(a, b);
  const addedSet = new Set(added.map(key));
  const removedSet = new Set(removed.map(key));
  // base layer = B's content, ghosting unchanged, additions in green
  const merged = {
    width: b.width, height: b.height, background_data: b.background_data,
    scale_ratio: b.scale_ratio, units: b.units,
    strokes: [...(b.strokes || []), ...(a.strokes || []).filter((s) => removedSet.has(key(s)))],
    annotations: [...(b.annotations || []), ...(a.annotations || []).filter((s) => removedSet.has(key(s)))],
  };
  const recolor = (it) => {
    const k = key(it);
    if (addedSet.has(k)) return { color: "#3ad07a", alpha: 1 };       // added → green
    if (removedSet.has(k)) return { color: "#ff4d4d", alpha: 0.55 };  // removed → red ghost
    return { color: "#6b7689", alpha: 0.5 };                          // unchanged → gray ghost
  };
  return renderInto(targetCanvas, merged, boxW, boxH, recolor);
}
