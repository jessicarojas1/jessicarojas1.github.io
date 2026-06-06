/* AeroMarkup — UI primitives: escaping, toasts, modals, formatting, pills. */
import { icon } from "./icons.js";

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

/* XSS-safe escaping for ALL user-supplied text rendered as HTML */
export function esc(s) {
  if (s == null) return "";
  return String(s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

export function el(html) {
  const t = document.createElement("template");
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

export function fmtDate(v) {
  if (!v) return "—";
  const d = new Date(v);
  if (isNaN(d)) return "—";
  return d.toLocaleString(undefined, { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });
}
export function fmtDay(v) {
  if (!v) return "—";
  const d = new Date(v);
  return isNaN(d) ? "—" : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "2-digit" });
}
export function timeAgo(v) {
  const d = new Date(v); if (isNaN(d)) return "";
  const s = Math.floor((Date.now() - d.getTime()) / 1000);
  if (s < 60) return s + "s ago";
  if (s < 3600) return Math.floor(s / 60) + "m ago";
  if (s < 86400) return Math.floor(s / 3600) + "h ago";
  return Math.floor(s / 86400) + "d ago";
}

const PILL_CLASS = {
  open: "pill-open", in_review: "pill-review", review: "pill-review",
  approved: "pill-approved", released: "pill-released", dispositioned: "pill-approved",
  closed: "pill-closed", obsolete: "pill-obsolete", draft: "pill-draft",
  critical: "pill-critical", major: "pill-major", minor: "pill-minor",
  pass: "pill-pass", fail: "pill-fail", pending: "pill-pending",
};
export function pill(value) {
  const v = String(value || "").toLowerCase();
  const cls = PILL_CLASS[v] || "pill-draft";
  return `<span class="pill ${cls}">${esc(String(value || "—").replace(/_/g, " "))}</span>`;
}

/* ---- toasts ---- */
let toastHost;
export function toast(msg, kind = "info", ms = 3600) {
  if (!toastHost) {
    toastHost = el('<div class="toast-host"></div>');
    document.body.appendChild(toastHost);
  }
  const t = el(`<div class="toast toast-${kind}">${esc(msg)}</div>`);
  toastHost.appendChild(t);
  setTimeout(() => { t.style.opacity = "0"; setTimeout(() => t.remove(), 250); }, ms);
}

/* ---- modal ---- */
export function modal({ title, body, footer, width = 560, onClose }) {
  const overlay = el(`
    <div class="modal-overlay">
      <div class="modal" style="max-width:${width}px" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>${esc(title)}</h3>
          <button class="btn-icon modal-close" aria-label="Close">${icon("close", 18)}</button>
        </div>
        <div class="modal-body"></div>
        <div class="modal-foot"></div>
      </div>
    </div>`);
  const bodyEl = $(".modal-body", overlay);
  const footEl = $(".modal-foot", overlay);
  if (typeof body === "string") bodyEl.innerHTML = body; else if (body) bodyEl.appendChild(body);
  if (footer) (typeof footer === "string" ? (footEl.innerHTML = footer) : footEl.appendChild(footer));
  const close = () => { overlay.remove(); onClose && onClose(); };
  $(".modal-close", overlay).addEventListener("click", close);
  overlay.addEventListener("mousedown", (e) => { if (e.target === overlay) close(); });
  document.body.appendChild(overlay);
  return { overlay, bodyEl, footEl, close };
}

/* read values from a form-grid built of [data-name] inputs */
export function formValues(root) {
  const out = {};
  $$("[data-name]", root).forEach((i) => {
    out[i.dataset.name] = i.type === "checkbox" ? i.checked : i.value;
  });
  return out;
}
