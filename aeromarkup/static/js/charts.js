/* AeroMarkup — dependency-free inline SVG charts (offline / air-gap safe). */
import { esc } from "./ui.js";

export function donut(segments, size = 132, thickness = 18) {
  const total = segments.reduce((s, x) => s + x.value, 0) || 1;
  const r = (size - thickness) / 2, cx = size / 2, cy = size / 2, C = 2 * Math.PI * r;
  let off = 0;
  const rings = segments.filter((s) => s.value > 0).map((s) => {
    const len = (s.value / total) * C;
    const dash = `${len} ${C - len}`;
    const el = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${esc(s.color)}"
      stroke-width="${thickness}" stroke-dasharray="${dash}" stroke-dashoffset="${-off}"
      transform="rotate(-90 ${cx} ${cy})"></circle>`;
    off += len; return el;
  }).join("");
  const sum = segments.reduce((a, b) => a + b.value, 0);
  const svg = `<svg class="chart-donut" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="var(--surface-3)" stroke-width="${thickness}"></circle>
    ${rings}
    <text x="${cx}" y="${cy - 2}" text-anchor="middle" font-size="26" font-weight="700" fill="var(--text)">${sum}</text>
    <text x="${cx}" y="${cy + 16}" text-anchor="middle" font-size="10" fill="var(--text-muted)">TOTAL</text>
  </svg>`;
  const legend = `<div class="chart-legend">${segments.map((s) => `
    <div class="legend-item"><span class="legend-swatch" style="background:${esc(s.color)}"></span>
      <span style="text-transform:capitalize">${esc(s.label.replace(/_/g, " "))}</span>
      <span class="legend-val">${s.value}</span></div>`).join("")}</div>`;
  return `<div class="chart-wrap">${svg}${legend}</div>`;
}

export function bars(rows) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return `<div class="chart-bars">${rows.map((r) => `
    <div class="bar-row">
      <span class="bar-label">${esc(r.label.replace(/_/g, " "))}</span>
      <span class="bar-track"><span class="bar-fill" style="width:${(r.value / max) * 100}%;background:${esc(r.color)}"></span></span>
      <span class="bar-value">${r.value}</span>
    </div>`).join("")}</div>`;
}
