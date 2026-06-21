import { NextRequest, NextResponse } from 'next/server';
import { timingSafeEqual } from 'crypto';
import { getSession } from '@/lib/session';

// --- Abuse / cost controls -------------------------------------------------
// This route relays to the Anthropic API using the server's ANTHROPIC_API_KEY.
// Without limits it is an open, unauthenticated AI relay — anyone could drive
// unbounded spend (OWASP LLM10: Unbounded Consumption / CWE-770). We apply:
//   1. Authorization — a caller must satisfy ONE of:
//        (a) a valid server-side session cookie (the in-app browser assistant),
//            verified by lib/session. The browser never holds AI_PROXY_TOKEN;
//            when the session is valid, THIS route injects it server-side when
//            calling the upstream provider, so the secret never reaches the
//            client. CSRF defense: the session cookie is SameSite=Strict AND we
//            require an `x-requested-with` header (a value cross-site forms /
//            navigations cannot set without a CORS preflight this route never
//            grants), so a forged cross-origin POST cannot ride the cookie.
//        (b) the shared AI_PROXY_TOKEN, presented via `Authorization: Bearer
//            <token>` or `x-api-key: <token>` (programmatic / external callers),
//            compared with a constant-time check.
//      FAILS CLOSED in production: if AI_PROXY_TOKEN is unset while
//      NODE_ENV=production AND there is no valid session, every request is
//      rejected (503) so a misconfigured deploy never exposes an open relay. In
//      non-production (local/dev) an unset token leaves the route open for
//      convenience.
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
// authenticated session user, then the shared token (one bucket per credential);
// otherwise fall back to the platform-forwarded client IP (best-effort only).
function identityKey(req: NextRequest, token: string, sessionUser: string | null): string {
  if (sessionUser) return `sess:${sessionUser}`;
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
    const expectedToken = process.env.AI_PROXY_TOKEN || '';
    const provided = extractToken(req);

    // --- Path (a): valid server-side session (in-app browser assistant) -----
    // CSRF defense for the cookie path: require a custom header. Combined with
    // the SameSite=Strict session cookie, a cross-site form/navigation cannot
    // both carry the cookie and set this header, so it cannot forge a request.
    const hasReqHeader = Boolean(req.headers.get('x-requested-with'));
    const session = hasReqHeader ? getSession(req) : null;
    const sessionUser = session?.sub ?? null;

    // --- Path (b): shared AI_PROXY_TOKEN (programmatic / external) -----------
    const tokenValid = expectedToken !== '' && tokenMatches(provided, expectedToken);

    if (!sessionUser && !tokenValid) {
      // Neither path satisfied. Decide between 503 (misconfigured/closed) vs 401.
      if (!expectedToken) {
        // No shared token configured. In production refuse to act as an open
        // relay (fail closed). In dev, allow through for local convenience.
        if (process.env.NODE_ENV === 'production') {
          return NextResponse.json(
            { error: 'AI relay is not configured for use.' },
            { status: 503 },
          );
        }
        // dev fall-through: treated as unauthenticated-but-allowed below.
      } else {
        // A shared token IS configured but the caller presented neither a valid
        // session nor the correct token.
        return NextResponse.json({ error: 'Unauthorized' }, { status: 401 });
      }
    }

    // 2. Rate limit (always on). Keyed per session user / credential.
    if (rateLimited(identityKey(req, tokenValid ? provided : '', sessionUser))) {
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
