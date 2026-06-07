// Branding settings — types, defaults, sanitization, and persistence helpers.
//
// Persistence model (per CLAUDE.md "Settings & Branding Standard"):
//  - Server-side (shared) via /api/settings/branding (Supabase `app_settings`).
//  - localStorage (per-browser) as fallback for static / no-backend hosting.
//  - The backend value wins when both are present.

export interface Branding {
  /** Logo image URL — http(s):// or data:image/... only. Empty = built-in mark. */
  logoUrl: string;
  /** Organization / product display name. Empty = built-in name. */
  displayName: string;
  /** Primary accent / brand color as a #rrggbb hex string. */
  accentColor: string;
}

/** Built-in empty-state defaults (the app's own brand). */
export const DEFAULT_BRANDING: Branding = {
  logoUrl: '',
  displayName: 'Compliance Copilot',
  accentColor: '#2563eb', // matches Tailwind brand-600 / blue-600
};

/** The product tagline shown under the brand name. */
export const BRAND_TAGLINE = 'CMMC / NIST 800-171';

const STORAGE_KEY = 'cc.branding.v1';

/** localStorage key holding the admin token used to authorize shared writes
 *  (only needed when the server sets BRANDING_ADMIN_TOKEN). */
export const ADMIN_TOKEN_KEY = 'cc.admin_token';

/**
 * Validate a logo URL. Only http(s):// and data:image/... are allowed to
 * prevent javascript:, vbscript:, file:, and other dangerous schemes.
 * Returns the trimmed URL when valid, otherwise an empty string.
 */
export function sanitizeLogoUrl(raw: unknown): string {
  if (typeof raw !== 'string') return '';
  const url = raw.trim();
  if (url === '') return '';
  // data: must be a data:image/... payload.
  if (/^data:image\/[a-z0-9.+-]+;/i.test(url) || /^data:image\/[a-z0-9.+-]+,/i.test(url)) {
    return url;
  }
  // http(s) only.
  if (/^https?:\/\//i.test(url)) {
    return url;
  }
  return '';
}

/**
 * Validate an accent color. Accepts #rgb or #rrggbb (case-insensitive) and
 * normalizes to lowercase #rrggbb. Returns the default accent when invalid.
 */
export function sanitizeAccentColor(raw: unknown): string {
  if (typeof raw !== 'string') return DEFAULT_BRANDING.accentColor;
  let v = raw.trim().toLowerCase();
  if (/^#[0-9a-f]{3}$/.test(v)) {
    // expand shorthand #abc -> #aabbcc
    v = '#' + v.slice(1).split('').map(c => c + c).join('');
  }
  if (/^#[0-9a-f]{6}$/.test(v)) return v;
  return DEFAULT_BRANDING.accentColor;
}

/** Clamp the display name to a sane length and strip control characters. */
export function sanitizeDisplayName(raw: unknown): string {
  if (typeof raw !== 'string') return '';
  // Strip control chars (0x00-0x1F and 0x7F); trim; cap length.
  return raw.replace(/[\x00-\x1f\x7f]/g, '').trim().slice(0, 120);
}

/** Normalize an arbitrary (possibly partial / untrusted) object into Branding. */
export function normalizeBranding(input: Partial<Branding> | null | undefined): Branding {
  const displayName = sanitizeDisplayName(input?.displayName);
  return {
    logoUrl: sanitizeLogoUrl(input?.logoUrl),
    displayName: displayName || DEFAULT_BRANDING.displayName,
    accentColor: sanitizeAccentColor(input?.accentColor),
  };
}

// ── localStorage (per-browser fallback) ────────────────────────────────────

export function readLocalBranding(): Branding | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    return normalizeBranding(JSON.parse(raw));
  } catch {
    return null;
  }
}

export function writeLocalBranding(b: Branding): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(normalizeBranding(b)));
  } catch {
    /* quota / disabled storage — ignore, server copy is authoritative */
  }
}

// ── Server (shared) persistence via the settings API ────────────────────────

/**
 * Fetch shared branding from the backend. Returns null when no backend is
 * configured or the request fails — callers should then fall back to local.
 */
export async function fetchServerBranding(): Promise<Branding | null> {
  try {
    const res = await fetch('/api/settings/branding', { cache: 'no-store' });
    if (!res.ok) return null;
    const data = await res.json();
    if (!data || !data.branding) return null;
    return normalizeBranding(data.branding);
  } catch {
    return null;
  }
}

/**
 * Persist branding to the backend. Returns true on success. A false result
 * means there is no usable backend; the caller still has localStorage.
 */
export async function saveServerBranding(b: Branding): Promise<boolean> {
  try {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    // When the deployment sets BRANDING_ADMIN_TOKEN, the server requires this
    // token to authorize the shared write. Stored per-browser in localStorage.
    if (typeof window !== 'undefined') {
      const token = window.localStorage.getItem(ADMIN_TOKEN_KEY);
      if (token) headers['Authorization'] = `Bearer ${token}`;
    }
    const res = await fetch('/api/settings/branding', {
      method: 'PUT',
      headers,
      body: JSON.stringify(normalizeBranding(b)),
    });
    if (!res.ok) return false;
    const data = await res.json().catch(() => null);
    return Boolean(data?.ok);
  } catch {
    return false;
  }
}
