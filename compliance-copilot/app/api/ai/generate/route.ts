import { NextRequest, NextResponse } from 'next/server';
import { timingSafeEqual } from 'crypto';

// --- Abuse / cost controls -------------------------------------------------
// This route relays to the Anthropic API using the server's ANTHROPIC_API_KEY.
// Without limits it is an open, unauthenticated AI relay — anyone could drive
// unbounded spend (OWASP LLM10: Unbounded Consumption / CWE-770). We apply:
//   1. Shared-token auth (AI_PROXY_TOKEN) — callers must present it via
//      `Authorization: Bearer <token>` or `x-api-key: <token>`. Compared with a
//      constant-time check. FAILS CLOSED in production: if the token env var is
//      unset while NODE_ENV=production, every request is rejected (503) so a
//      misconfigured deploy never exposes an open relay. In non-production
//      (local/dev) an unset token leaves the route open for convenience.
//   2. Always-on per-identity fixed-window rate limit (best-effort, in-memory).
//   3. Hard input/output caps (prompt length + max_tokens).
const MAX_PROMPT_CHARS = 8000;
const MAX_TOKENS = 1024;
const RATE_LIMIT = 20; // requests
const RATE_WINDOW_MS = 60_000; // per minute, per identity

// NOTE: the in-memory limiter is per-process / best-effort only. Behind multiple
// instances (serverless, autoscaled) it does NOT enforce a global limit — also
// apply limits at the gateway/WAF or back this with a shared store (Redis) for
// production-grade protection.
const buckets = new Map<string, { count: number; reset: number }>();

// Constant-time string comparison to avoid leaking the token via timing.
function tokenMatches(provided: string, expected: string): boolean {
  if (!provided || !expected) return false;
  const a = Buffer.from(provided);
  const b = Buffer.from(expected);
  // timingSafeEqual throws on length mismatch; hash to fixed length first.
  if (a.length !== b.length) {
    // Still do a comparison to keep timing roughly constant, then fail.
    try {
      timingSafeEqual(b, b);
    } catch {
      /* noop */
    }
    return false;
  }
  return timingSafeEqual(a, b);
}

// Resolve a stable per-caller identity for rate limiting. Prefer the
// authenticated token (one bucket per credential); otherwise fall back to the
// platform-forwarded client IP (coarse, best-effort only).
function identityKey(req: NextRequest, token: string): string {
  if (token) return `tok:${token}`;
  const xff = req.headers.get('x-forwarded-for') || '';
  const ip = xff.split(',')[0].trim();
  return `ip:${ip || 'unknown'}`;
}

function rateLimited(key: string): boolean {
  const now = Date.now();
  const b = buckets.get(key);
  if (!b || now > b.reset) {
    buckets.set(key, { count: 1, reset: now + RATE_WINDOW_MS });
    return false;
  }
  b.count += 1;
  return b.count > RATE_LIMIT;
}

function extractToken(req: NextRequest): string {
  const auth = req.headers.get('authorization') || '';
  if (/^bearer\s+/i.test(auth)) {
    return auth.replace(/^bearer\s+/i, '').trim();
  }
  return (req.headers.get('x-api-key') || '').trim();
}

export async function POST(req: NextRequest) {
  try {
    // 1. Shared-token auth (fail closed in production).
    const expectedToken = process.env.AI_PROXY_TOKEN || '';
    const provided = extractToken(req);

    if (!expectedToken) {
      // No token configured. In production this is a misconfiguration; refuse to
      // act as an open relay. In dev, allow through for local convenience.
      if (process.env.NODE_ENV === 'production') {
        return NextResponse.json(
          { error: 'AI relay is not configured for use.' },
          { status: 503 },
        );
      }
    } else if (!tokenMatches(provided, expectedToken)) {
      return NextResponse.json({ error: 'Unauthorized' }, { status: 401 });
    }

    // 2. Rate limit (always on). Keyed per credential when authenticated.
    if (rateLimited(identityKey(req, expectedToken ? provided : ''))) {
      return NextResponse.json(
        { error: 'Rate limit exceeded. Try again shortly.' },
        { status: 429, headers: { 'Retry-After': String(Math.ceil(RATE_WINDOW_MS / 1000)) } },
      );
    }

    let body: unknown;
    try {
      body = await req.json();
    } catch {
      return NextResponse.json({ error: 'Invalid JSON' }, { status: 400 });
    }
    const prompt = (body as { prompt?: unknown } | null)?.prompt;

    // 3. Input validation / caps.
    if (typeof prompt !== 'string' || prompt.trim() === '') {
      return NextResponse.json({ error: 'Missing prompt' }, { status: 400 });
    }
    if (prompt.length > MAX_PROMPT_CHARS) {
      return NextResponse.json(
        { error: `Prompt too long (max ${MAX_PROMPT_CHARS} characters)` },
        { status: 400 },
      );
    }

    const apiKey = process.env.ANTHROPIC_API_KEY;
    if (!apiKey) {
      return NextResponse.json({ error: 'AI service unavailable' }, { status: 503 });
    }

    const response = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
      },
      body: JSON.stringify({
        model: 'claude-opus-4-6',
        max_tokens: MAX_TOKENS, // hard output ceiling — never client-controlled
        messages: [{ role: 'user', content: prompt }],
      }),
    });

    if (!response.ok) {
      // Do not leak upstream error bodies (may contain provider internals).
      return NextResponse.json(
        { error: 'AI request failed' },
        { status: response.status === 429 ? 429 : 502 },
      );
    }

    const data = await response.json();
    const text = data.content?.[0]?.text ?? '';
    return NextResponse.json({ text });
  } catch {
    // Never surface internals (incl. the upstream API key) to the client.
    return NextResponse.json({ error: 'Internal error' }, { status: 500 });
  }
}

export const dynamic = 'force-dynamic';
