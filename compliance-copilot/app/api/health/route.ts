import { NextRequest, NextResponse } from 'next/server';
import { createClient } from '@supabase/supabase-js';
import { requestId, withRequestId } from '@/lib/logger';
import pkg from '@/package.json';

const APP_VERSION = pkg.version;

// GET /api/health — liveness + readiness probe.
//
// Returns 200 JSON without auth so container / platform health checks (Docker
// HEALTHCHECK, Render healthCheckPath, k8s probes) can hit it. When Supabase is
// configured it performs a lightweight, read-only reachability ping and reports
// the result; a Supabase outage degrades the payload to `status: "degraded"`
// (still HTTP 200 so the app process is not killed for a downstream dependency)
// unless the process itself is unhealthy.
//
// Shape: { status, version, uptime_s, supabase: <"ok"|"degraded"|"not_configured">, req_id }

type SupabaseHealth = 'ok' | 'degraded' | 'not_configured';

async function pingSupabase(): Promise<SupabaseHealth> {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  // Prefer the service-role key (server-only); fall back to the anon key so the
  // probe works even where only the public key is present.
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY || process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;
  if (!url || !key) return 'not_configured';

  try {
    const supabase = createClient(url, key, { auth: { persistSession: false } });
    // HEAD count against a known table — cheap, read-only, and covered by RLS.
    const { error } = await supabase
      .from('controls')
      .select('id', { count: 'exact', head: true })
      .limit(1);
    return error ? 'degraded' : 'ok';
  } catch {
    return 'degraded';
  }
}

export async function GET(req: NextRequest) {
  const rid = requestId(req.headers);
  const log = withRequestId(rid, { route: '/api/health' });
  const started = Date.now();

  const supabase = await pingSupabase();
  const status = supabase === 'degraded' ? 'degraded' : 'ok';

  log.info('health check', { supabase, status, latency_ms: Date.now() - started });

  return NextResponse.json(
    {
      status,
      version: APP_VERSION,
      uptime_s: Math.round(process.uptime()),
      supabase,
      req_id: rid,
    },
    {
      status: 200,
      headers: { 'Cache-Control': 'no-store', 'x-request-id': rid },
    },
  );
}

export const dynamic = 'force-dynamic';
