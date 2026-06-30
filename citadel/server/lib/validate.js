'use strict';
/* CITADEL — reusable input validators / coercers.
 *
 * Centralizes the small, security-relevant validation primitives that were
 * previously hand-rolled inline across route handlers (branding, scan-url,
 * login). Each function is pure and side-effect-free: it returns a cleaned
 * value (or a sentinel), never throws, so callers stay simple. Bounds are
 * applied so an oversized field can't be stored or echoed.
 */

// Clamp to a string of at most `max` chars (default 1000). Non-strings -> ''.
function str(v, max) { return (typeof v === 'string' ? v : '').slice(0, max == null ? 1000 : max); }
// Trimmed variant.
function trimStr(v, max) { return str(v, max).trim(); }

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
function isEmail(v) { return typeof v === 'string' && v.length <= 254 && EMAIL_RE.test(v.trim()); }

// Normalize a hex color to "#rrggbb" form, or '' if invalid (3–8 hex digits).
function hexColor(v) {
  const s = String(v == null ? '' : v).trim();
  return /^#?[0-9a-fA-F]{3,8}$/.test(s) ? (s[0] === '#' ? s : '#' + s) : '';
}

// Return `v` if it's a member of `list`, else null.
function inEnum(list, v) { return list.indexOf(v) >= 0 ? v : null; }

// Accept only an https:// URL or an inline raster data: image URI (no SVG — it
// can carry script). Returns the bounded URL, or '' if neither.
const DATA_IMG_RE = /^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$/i;
function logoUrl(v) {
  const s = String(v == null ? '' : v).trim();
  if (/^https:\/\/\S+$/i.test(s)) return s.slice(0, 500);
  if (DATA_IMG_RE.test(s)) return s.slice(0, 200000);
  return '';
}

module.exports = { str, trimStr, isEmail, hexColor, inEnum, logoUrl };
