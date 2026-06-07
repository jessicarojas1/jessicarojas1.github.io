'use client';

import {
  createContext, useContext, useEffect, useState, useCallback, ReactNode,
} from 'react';
import {
  Branding, DEFAULT_BRANDING, BRAND_TAGLINE,
  readLocalBranding, writeLocalBranding,
  fetchServerBranding, saveServerBranding, normalizeBranding,
} from '@/lib/branding';

interface BrandingContextValue {
  branding: Branding;
  loaded: boolean;
  /** Persist new branding (server-first, localStorage fallback) and apply live. */
  save: (b: Branding) => Promise<{ ok: boolean; persisted: 'server' | 'local' }>;
  tagline: string;
}

const BrandingContext = createContext<BrandingContextValue | null>(null);

/** Apply the accent color to the document as a CSS custom property. */
function applyAccent(accent: string) {
  if (typeof document === 'undefined') return;
  document.documentElement.style.setProperty('--brand-accent', accent);
}

/** Apply the display name to the document <title>. */
function applyTitle(name: string) {
  if (typeof document === 'undefined') return;
  const base = name || DEFAULT_BRANDING.displayName;
  document.title = `${base} — CMMC & NIST 800-171`;
}

export function BrandingProvider({ children }: { children: ReactNode }) {
  const [branding, setBranding] = useState<Branding>(DEFAULT_BRANDING);
  const [loaded, setLoaded] = useState(false);

  // Initial load: backend wins, then localStorage, then defaults.
  useEffect(() => {
    let cancelled = false;

    // Apply the per-browser value immediately to avoid a flash, then reconcile
    // with the shared server value (which takes precedence when present).
    const local = readLocalBranding();
    if (local) {
      applyAccent(local.accentColor);
      applyTitle(local.displayName);
      setBranding(local);
    }

    (async () => {
      const server = await fetchServerBranding();
      if (cancelled) return;
      if (server) {
        applyAccent(server.accentColor);
        applyTitle(server.displayName);
        setBranding(server);
      } else if (!local) {
        applyAccent(DEFAULT_BRANDING.accentColor);
        applyTitle(DEFAULT_BRANDING.displayName);
      }
      setLoaded(true);
    })();

    return () => { cancelled = true; };
  }, []);

  const save = useCallback(async (next: Branding) => {
    const clean = normalizeBranding(next);
    // Apply live immediately.
    applyAccent(clean.accentColor);
    applyTitle(clean.displayName);
    setBranding(clean);
    // Always keep the per-browser copy in sync.
    writeLocalBranding(clean);
    // Try the shared backend; it wins when available.
    const serverOk = await saveServerBranding(clean);
    return { ok: true, persisted: serverOk ? 'server' as const : 'local' as const };
  }, []);

  return (
    <BrandingContext.Provider value={{ branding, loaded, save, tagline: BRAND_TAGLINE }}>
      {children}
    </BrandingContext.Provider>
  );
}

export function useBranding(): BrandingContextValue {
  const ctx = useContext(BrandingContext);
  if (!ctx) {
    // Safe fallback so components never crash outside the provider.
    return {
      branding: DEFAULT_BRANDING,
      loaded: true,
      save: async () => ({ ok: false, persisted: 'local' as const }),
      tagline: BRAND_TAGLINE,
    };
  }
  return ctx;
}
