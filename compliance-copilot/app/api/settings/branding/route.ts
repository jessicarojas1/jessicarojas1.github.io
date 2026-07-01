import { NextRequest, NextResponse } from 'next/server';
import { createClient } from '@supabase/supabase-js';
import { Branding, normalizeBranding } from '@/lib/branding';
import { requestId, withRequestId } from '@/lib/logger';

// Shared branding is stored as a single row in the `app_settings` table
// (key = 'branding', value = jsonb). Requires the Supabase service role key.
const SETTINGS_KEY = 'branding';

function serviceClient() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) return null;
  return createClient(url, key);
}

// GET /api/settings/branding — returns the shared branding, or 204 when no
// backend / no row exists so the client falls back to localStorage/defaults.
export async function GET() {
  const supabase = serviceClient();
  if (!supabase) {
    return new NextResponse(null, { status: 204 });
  }
  try {
    const { data, error } = await supabase
      .from('app_settings')
      .select('value')
      .eq('key', SETTINGS_KEY)
      .maybeSingle();

    if (error || !data) {
      return new NextResponse(null, { status: 204 });
    }
    return NextResponse.json({ branding: normalizeBranding(data.value as Partial<Branding>) });
  } catch {
    return new NextResponse(null, { status: 204 });
  }
}

// PUT /api/settings/branding — upserts the shared branding.
export async function PUT(req: NextRequest) {
  // This app ships without a user-auth layer, so the shared (org-wide) branding
  // write is gated by an optional admin token: when BRANDING_ADMIN_TOKEN is set,
  // callers must send it as `Authorization: Bearer <token>`. When unset, the route
  // stays open (single-user/demo default) — set the env var in any shared
  // deployment to prevent anonymous branding changes (defacement).
  const log = withRequestId(requestId(req.headers), { route: '/api/settings/branding' });
  const adminToken = process.env.BRANDING_ADMIN_TOKEN;
  if (adminToken) {
    const auth = req.headers.get('authorization') || '';
    const provided = /^bearer\s+/i.test(auth) ? auth.replace(/^bearer\s+/i, '').trim() : '';
    if (provided !== adminToken) {
      log.warn('branding write rejected: unauthorized', { status: 401 });
      return NextResponse.json({ ok: false, error: 'Unauthorized' }, { status: 401 });
    }
  }

  let body: Partial<Branding>;
  try {
    body = await req.json();
  } catch {
    return NextResponse.json({ ok: false, error: 'Invalid JSON' }, { status: 400 });
  }

  // Sanitize on the server too — never trust client input.
  const branding = normalizeBranding(body);

  const supabase = serviceClient();
  if (!supabase) {
    // No backend configured — signal to the client that only localStorage applies.
    return NextResponse.json({ ok: false, persisted: 'local', branding }, { status: 200 });
  }

  try {
    const { error } = await supabase
      .from('app_settings')
      .upsert({ key: SETTINGS_KEY, value: branding }, { onConflict: 'key' });

    if (error) {
      log.warn('branding write fell back to local (db error)');
      return NextResponse.json({ ok: false, persisted: 'local', branding }, { status: 200 });
    }
    log.info('branding write ok', { persisted: 'server' });
    return NextResponse.json({ ok: true, persisted: 'server', branding });
  } catch {
    return NextResponse.json({ ok: false, persisted: 'local', branding }, { status: 200 });
  }
}

export const dynamic = 'force-dynamic';
