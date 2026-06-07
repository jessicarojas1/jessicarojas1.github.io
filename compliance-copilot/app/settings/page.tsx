'use client';

import { useEffect, useRef, useState } from 'react';
import { Palette, Upload, Link2, Type, Check, AlertCircle, RotateCcw } from 'lucide-react';
import { useBranding } from '@/components/branding/BrandingProvider';
import {
  Branding, DEFAULT_BRANDING, BRAND_TAGLINE,
  sanitizeLogoUrl, sanitizeAccentColor, ADMIN_TOKEN_KEY,
} from '@/lib/branding';

const MAX_LOGO_BYTES = 512 * 1024; // 512 KB cap for inline data: URLs.

type SaveState =
  | { kind: 'idle' }
  | { kind: 'saving' }
  | { kind: 'saved'; where: 'server' | 'local' }
  | { kind: 'error'; message: string };

export default function SettingsPage() {
  const { branding, loaded, save } = useBranding();

  const [form, setForm] = useState<Branding>(branding);
  const [dirty, setDirty] = useState(false);
  const [saveState, setSaveState] = useState<SaveState>({ kind: 'idle' });
  const [logoError, setLogoError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement | null>(null);
  const [adminToken, setAdminToken] = useState('');

  // Load any saved admin token (only needed when the server sets BRANDING_ADMIN_TOKEN).
  useEffect(() => {
    if (typeof window === 'undefined') return;
    try { setAdminToken(window.localStorage.getItem(ADMIN_TOKEN_KEY) || ''); } catch { /* ignore */ }
  }, []);

  function onAdminTokenChange(value: string) {
    setAdminToken(value);
    if (typeof window === 'undefined') return;
    try {
      if (value) window.localStorage.setItem(ADMIN_TOKEN_KEY, value);
      else window.localStorage.removeItem(ADMIN_TOKEN_KEY);
    } catch { /* ignore */ }
  }

  // Keep the form in sync with loaded branding until the user edits it.
  useEffect(() => {
    if (!dirty) setForm(branding);
  }, [branding, dirty]);

  function update<K extends keyof Branding>(key: K, value: Branding[K]) {
    setForm(prev => ({ ...prev, [key]: value }));
    setDirty(true);
    setSaveState({ kind: 'idle' });
  }

  function onLogoUrlChange(e: React.ChangeEvent<HTMLInputElement>) {
    setLogoError(null);
    update('logoUrl', e.target.value);
  }

  function onLogoUrlBlur(e: React.FocusEvent<HTMLInputElement>) {
    const raw = e.target.value.trim();
    if (raw === '') return;
    const clean = sanitizeLogoUrl(raw);
    if (clean === '') {
      setLogoError('Only http(s):// or data:image/... URLs are allowed.');
    } else {
      setLogoError(null);
      update('logoUrl', clean);
    }
  }

  function onFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setLogoError(null);
    if (!file.type.startsWith('image/')) {
      setLogoError('Please choose an image file.');
      return;
    }
    if (file.size > MAX_LOGO_BYTES) {
      setLogoError('Image is too large (max 512 KB for inline storage).');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = typeof reader.result === 'string' ? reader.result : '';
      const clean = sanitizeLogoUrl(dataUrl);
      if (clean === '') {
        setLogoError('Unsupported image format.');
        return;
      }
      update('logoUrl', clean);
    };
    reader.onerror = () => setLogoError('Could not read the file.');
    reader.readAsDataURL(file);
  }

  function clearLogo() {
    setLogoError(null);
    update('logoUrl', '');
    if (fileRef.current) fileRef.current.value = '';
  }

  async function onSave() {
    setSaveState({ kind: 'saving' });
    const payload: Branding = {
      logoUrl: sanitizeLogoUrl(form.logoUrl),
      displayName: form.displayName,
      accentColor: sanitizeAccentColor(form.accentColor),
    };
    try {
      const res = await save(payload);
      setForm(payload);
      setDirty(false);
      setSaveState({ kind: 'saved', where: res.persisted });
    } catch {
      setSaveState({ kind: 'error', message: 'Failed to save branding.' });
    }
  }

  function onReset() {
    setForm(DEFAULT_BRANDING);
    setDirty(true);
    setLogoError(null);
    setSaveState({ kind: 'idle' });
    if (fileRef.current) fileRef.current.value = '';
  }

  const accentValue = sanitizeAccentColor(form.accentColor);

  return (
    <div className="space-y-6 max-w-3xl">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Settings</h1>
        <p className="text-sm text-slate-400 mt-1">Customize how Compliance Copilot looks and is named.</p>
      </div>

      {/* Branding card */}
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-slate-800 flex items-center gap-2">
          <Palette className="w-4 h-4 text-slate-400" />
          <span className="font-semibold text-slate-200">Branding</span>
        </div>

        <div className="p-5 space-y-6">
          {/* Live preview */}
          <div className="flex items-center gap-3 p-4 rounded-lg bg-slate-800/50 border border-slate-800">
            <BrandMarkPreview branding={form} />
            <div className="min-w-0">
              <div className="text-sm font-bold text-slate-100 truncate">
                {form.displayName.trim() || DEFAULT_BRANDING.displayName}
              </div>
              <div className="text-xs text-slate-500">{BRAND_TAGLINE}</div>
            </div>
            <span
              className="ml-auto text-xs font-medium px-2 py-1 rounded-md text-white"
              style={{ background: accentValue }}
            >
              Preview
            </span>
          </div>

          {/* Display name */}
          <div>
            <label htmlFor="brand-name" className="label flex items-center gap-1.5">
              <Type className="w-3 h-3" /> Organization / Product Name
            </label>
            <input
              id="brand-name"
              className="input"
              type="text"
              maxLength={120}
              placeholder={DEFAULT_BRANDING.displayName}
              value={form.displayName}
              onChange={(e) => update('displayName', e.target.value)}
            />
            <p className="text-xs text-slate-500 mt-1">
              Replaces the app name in the sidebar and the browser tab title.
            </p>
          </div>

          {/* Logo URL */}
          <div>
            <label htmlFor="brand-logo-url" className="label flex items-center gap-1.5">
              <Link2 className="w-3 h-3" /> Logo URL
            </label>
            <input
              id="brand-logo-url"
              className="input"
              type="text"
              placeholder="https://example.com/logo.png"
              value={form.logoUrl}
              onChange={onLogoUrlChange}
              onBlur={onLogoUrlBlur}
            />
            <p className="text-xs text-slate-500 mt-1">
              Paste an image URL (http/https) or upload a file below. A broken
              or empty logo falls back to the built-in mark.
            </p>
          </div>

          {/* Logo upload */}
          <div>
            <span className="label flex items-center gap-1.5">
              <Upload className="w-3 h-3" /> Or Upload a Logo
            </span>
            <div className="flex items-center gap-2 flex-wrap">
              <button type="button" className="btn-secondary flex items-center gap-2" onClick={() => fileRef.current?.click()}>
                <Upload className="w-4 h-4" /> Choose file
              </button>
              {form.logoUrl !== '' && (
                <button type="button" className="btn-ghost" onClick={clearLogo}>
                  Remove logo
                </button>
              )}
              <input
                ref={fileRef}
                id="brand-logo-file"
                type="file"
                accept="image/*"
                className="hidden"
                onChange={onFileChange}
              />
            </div>
            {/* Field reference key (per project rule: every upload section documents its fields) */}
            <p className="text-xs text-slate-500 mt-2">
              <span className="font-semibold text-slate-400">Field reference:</span>{' '}
              <code className="text-slate-400">brand-logo-file</code> — accepts image/* up to 512&nbsp;KB,
              stored inline as a <code className="text-slate-400">data:</code> URL so it works offline.
              Larger or shared logos should use the <code className="text-slate-400">brand-logo-url</code> field.
            </p>
            {logoError && (
              <p className="text-xs mt-2 flex items-center gap-1.5" style={{ color: 'var(--danger, #ef4444)' }}>
                <AlertCircle className="w-3.5 h-3.5" /> {logoError}
              </p>
            )}
          </div>

          {/* Accent color */}
          <div>
            <label htmlFor="brand-accent" className="label flex items-center gap-1.5">
              <Palette className="w-3 h-3" /> Primary Accent Color
            </label>
            <div className="flex items-center gap-3">
              <input
                id="brand-accent"
                type="color"
                className="h-10 w-14 rounded-lg bg-slate-800 border border-slate-700 cursor-pointer p-1"
                value={accentValue}
                onChange={(e) => update('accentColor', e.target.value)}
              />
              <input
                aria-label="Accent color hex value"
                className="input font-mono"
                style={{ maxWidth: '10rem' }}
                type="text"
                value={form.accentColor}
                onChange={(e) => update('accentColor', e.target.value)}
                onBlur={(e) => update('accentColor', sanitizeAccentColor(e.target.value))}
              />
            </div>
            <p className="text-xs text-slate-500 mt-1">
              Applied live via the <code className="text-slate-400">--brand-accent</code> CSS variable
              (primary buttons, active nav, logo mark).
            </p>
          </div>

          {/* Admin token — only required when the deployment sets BRANDING_ADMIN_TOKEN */}
          <div>
            <label htmlFor="brand-admin-token" className="label flex items-center gap-1.5">
              <Type className="w-3.5 h-3.5" /> Admin token <span className="text-slate-500 font-normal">(optional)</span>
            </label>
            <input
              id="brand-admin-token"
              type="password"
              autoComplete="off"
              className="input font-mono"
              value={adminToken}
              onChange={(e) => onAdminTokenChange(e.target.value)}
              placeholder="Only needed if this deployment requires it"
            />
            <p className="text-xs text-slate-500 mt-1">
              When the server sets <code className="text-slate-400">BRANDING_ADMIN_TOKEN</code>, this token
              authorizes saving the shared (org-wide) branding. Stored only in this browser and sent as a
              Bearer token. Leave blank for single-user / local use.
            </p>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-3 pt-2 border-t border-slate-800">
            <button
              type="button"
              className="btn-primary flex items-center gap-2"
              onClick={onSave}
              disabled={!loaded || saveState.kind === 'saving' || !!logoError}
            >
              <Check className="w-4 h-4" />
              {saveState.kind === 'saving' ? 'Saving…' : 'Save branding'}
            </button>
            <button type="button" className="btn-ghost flex items-center gap-2" onClick={onReset}>
              <RotateCcw className="w-4 h-4" /> Reset to defaults
            </button>

            {dirty && saveState.kind !== 'saving' && (
              <span className="text-xs text-amber-400">Unsaved changes</span>
            )}
            {saveState.kind === 'saved' && (
              <span className="text-xs text-emerald-400 flex items-center gap-1.5">
                <Check className="w-3.5 h-3.5" />
                Saved {saveState.where === 'server' ? '(shared)' : '(this browser)'}
              </span>
            )}
            {saveState.kind === 'error' && (
              <span className="text-xs flex items-center gap-1.5" style={{ color: 'var(--danger, #ef4444)' }}>
                <AlertCircle className="w-3.5 h-3.5" /> {saveState.message}
              </span>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Local preview of the brand mark that reflects the *in-progress* form values
 * (the global BrandMark reflects the saved/applied branding).
 */
function BrandMarkPreview({ branding }: { branding: Branding }) {
  const [broken, setBroken] = useState(false);
  const url = sanitizeLogoUrl(branding.logoUrl);
  const accent = sanitizeAccentColor(branding.accentColor);

  useEffect(() => { setBroken(false); }, [url]);

  if (url !== '' && !broken) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={url}
        alt="Logo preview"
        width={36}
        height={36}
        onError={() => setBroken(true)}
        className="rounded-lg object-contain bg-white/5"
        style={{ width: 36, height: 36 }}
      />
    );
  }
  return (
    <div className="rounded-lg flex items-center justify-center" style={{ width: 36, height: 36, background: accent }}>
      <span className="text-white text-sm font-bold">
        {(branding.displayName.trim() || DEFAULT_BRANDING.displayName).charAt(0).toUpperCase()}
      </span>
    </div>
  );
}
