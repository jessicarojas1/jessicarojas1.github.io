import { useEffect, useMemo } from 'react';
import { useOrgSettings, type OrgSettings } from './useSettings';

/**
 * Branding — derives the active logo / display name / accent color from
 * organization settings, applies them live (document title + accent CSS var),
 * and caches them in localStorage so the UI can render branded immediately on
 * the next load (including the pre-auth login screen). The backend value always
 * wins over the cache once it loads.
 */

/** Built-in defaults used when nothing is configured (or while loading). */
export const DEFAULT_BRANDING = {
  name: 'Sentinel QMS',
  logoUrl: null as string | null,
  /** Matches the light-theme `--primary` design token in theme.css. */
  accent: '#1d4e89',
} as const;

const CACHE_KEY = 'sentinel.branding';

export interface Branding {
  /** Display name shown in the top bar, login screen, and document title. */
  name: string;
  /** Sanitized logo URL (http(s):// or data:image/...), or null for the mark. */
  logoUrl: string | null;
  /** Validated hex accent color, or null to keep the theme default. */
  accent: string | null;
}

const HTTP_OR_DATA_IMAGE = /^(https?:\/\/|data:image\/)/i;
const HEX_COLOR = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

/** Accept only http(s) or data:image logo URLs; anything else degrades to null. */
export function sanitizeLogoUrl(raw: string | null | undefined): string | null {
  if (!raw) return null;
  const value = raw.trim();
  return HTTP_OR_DATA_IMAGE.test(value) ? value : null;
}

/** Accept only valid hex colors; anything else degrades to null (theme default). */
export function sanitizeAccent(raw: string | null | undefined): string | null {
  if (!raw) return null;
  const value = raw.trim();
  return HEX_COLOR.test(value) ? value.toLowerCase() : null;
}

/** Build a sanitized Branding object from raw org settings. */
export function brandingFromSettings(settings: Partial<OrgSettings> | null | undefined): Branding {
  return {
    name: settings?.organization_name?.trim() || DEFAULT_BRANDING.name,
    logoUrl: sanitizeLogoUrl(settings?.logo_url),
    accent: sanitizeAccent(settings?.primary_color),
  };
}

function readCache(): Branding | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<Branding>;
    return {
      name: typeof parsed.name === 'string' && parsed.name ? parsed.name : DEFAULT_BRANDING.name,
      logoUrl: sanitizeLogoUrl(parsed.logoUrl),
      accent: sanitizeAccent(parsed.accent),
    };
  } catch {
    return null;
  }
}

function writeCache(branding: Branding): void {
  try {
    localStorage.setItem(CACHE_KEY, JSON.stringify(branding));
  } catch {
    /* storage may be unavailable (private mode / quota) — non-fatal */
  }
}

/**
 * Returns the active branding. Backend settings win; while they load (or fail —
 * e.g. on the unauthenticated login screen) the localStorage cache is used, then
 * the built-in defaults.
 */
export function useBranding(): Branding {
  const { data } = useOrgSettings();

  return useMemo<Branding>(() => {
    if (data) {
      const branding = brandingFromSettings(data);
      writeCache(branding);
      return branding;
    }
    return readCache() ?? { ...DEFAULT_BRANDING };
  }, [data]);
}

/**
 * Applies branding side effects globally: document.title and the `--primary`
 * (+ hover) accent CSS custom properties. Mount once near the app root.
 */
export function useApplyBranding(): Branding {
  const branding = useBranding();

  useEffect(() => {
    document.title = branding.name;
  }, [branding.name]);

  useEffect(() => {
    const root = document.documentElement;
    if (branding.accent) {
      root.style.setProperty('--primary', branding.accent);
      root.style.setProperty('--primary-hover', branding.accent);
    } else {
      // Fall back to the theme-defined token.
      root.style.removeProperty('--primary');
      root.style.removeProperty('--primary-hover');
    }
  }, [branding.accent]);

  return branding;
}
