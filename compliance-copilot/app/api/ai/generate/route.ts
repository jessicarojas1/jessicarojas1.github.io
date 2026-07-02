import { NextRequest, NextResponse } from 'next/server';
import { timingSafeEqual } from 'crypto';
import { getSession } from '@/lib/session';
import { requestId, withRequestId } from '@/lib/logger';

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

// --- Provider / model selection (env-driven) -------------------------------
// The upstream AI provider and model are configuration, never client input.
//   AI_PROVIDER   'anthropic' (default) | 'ollama' (self-hosted, air-gapped)
//   AI_MODEL      overrides the Anthropic model id (default kept for back-compat)
//   OLLAMA_BASE_URL / OLLAMA_MODEL   the self-hosted endpoint + model
// Air-gapped / CUI deployments set AI_PROVIDER=ollama so no traffic leaves the
// enclave; hosted deployments keep the default Anthropic path.
const AI_PROVIDER = (process.env.AI_PROVIDER || 'anthropic').toLowerCase();
const ANTHROPIC_MODEL = process.env.AI_MODEL || 'claude-opus-4-6';
const OLLAMA_BASE_URL = (process.env.OLLAMA_BASE_URL || 'http://127.0.0.1:11434').replace(/\/+$/, '');
const OLLAMA_MODEL = process.env.OLLAMA_MODEL || 'llama3.1';

// Result of an upstream call, normalized across providers. `configured` is false
// when the selected provider has no credentials/endpoint (→ 503 service down).
type UpstreamResult =
  | { configured: false }
  | { configured: true; ok: boolean; status: number; text: string };

async function callAnthropic(prompt: string): Promise<UpstreamResult> {
  const apiKey = process.env.ANTHROPIC_API_KEY;
  if (!apiKey) return { configured: false };

  const response = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': apiKey,
      'anthropic-version': '2023-06-01',
    },
    body: JSON.stringify({
      model: ANTHROPIC_MODEL,
      max_tokens: MAX_TOKENS, // hard output ceiling — never client-controlled
      messages: [{ role: 'user', content: prompt }],
    }),
  });

  if (!response.ok) return { configured: true, ok: false, status: response.status, text: '' };
  const data = await response.json();
  const text = data.content?.[0]?.text ?? '';
  return { configured: true, ok: true, status: 200, text };
}

async function callOllama(prompt: string): Promise<UpstreamResult> {
  // Self-hosted inference — no API key needed; the endpoint itself is the trust
  // boundary. `num_predict` mirrors the Anthropic max_tokens output ceiling.
  const response = await fetch(`${OLLAMA_BASE_URL}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: OLLAMA_MODEL,
      stream: false,
      options: { num_predict: MAX_TOKENS },
      messages: [{ role: 'user', content: prompt }],
    }),
  });

  if (!response.ok) return { configured: true, ok: false, status: response.status, text: '' };
  const data = await response.json();
  const text = data.message?.content ?? data.response ?? '';
  return { configured: true, ok: true, status: 200, text };
}

function callUpstream(prompt: string): Promise<UpstreamResult> {
  return AI_PROVIDER === 'ollama' ? callOllama(prompt) : callAnthropic(prompt);
}

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
  const rid = requestId(req.headers);
  const log = withRequestId(rid, { route: '/api/ai/generate', provider: AI_PROVIDER });
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
          log.warn('relay rejected: fail-closed (no token, no session)', { status: 503 });
          return NextResponse.json(
            { error: 'AI relay is not configured for use.' },
            { status: 503 },
          );
        }
        // dev fall-through: treated as unauthenticated-but-allowed below.
      } else {
        // A shared token IS configured but the caller presented neither a valid
        // session nor the correct token.
        log.warn('relay rejected: unauthorized', { status: 401 });
        return NextResponse.json({ error: 'Unauthorized' }, { status: 401 });
      }
    }

    // 2. Rate limit (always on). Keyed per session user / credential.
    if (rateLimited(identityKey(req, tokenValid ? provided : '', sessionUser))) {
      log.warn('relay rejected: rate limited', { status: 429, auth: sessionUser ? 'session' : 'token' });
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

    // 4. Relay to the selected upstream provider (Anthropic or self-hosted Ollama).
    const result = await callUpstream(prompt);

    if (!result.configured) {
      // The selected provider has no credentials/endpoint configured.
      log.error('relay upstream not configured', { status: 503 });
      return NextResponse.json({ error: 'AI service unavailable' }, { status: 503 });
    }

    if (!result.ok) {
      // Do not leak upstream error bodies (may contain provider internals).
      log.error('relay upstream error', { upstream_status: result.status });
      return NextResponse.json(
        { error: 'AI request failed' },
        { status: result.status === 429 ? 429 : 502 },
      );
    }

    log.info('relay ok', { auth: sessionUser ? 'session' : 'token', chars_out: result.text.length });
    return NextResponse.json({ text: result.text });
  } catch (err) {
    // Never surface internals (incl. the upstream API key) to the client.
    log.error('relay internal error', { error: err instanceof Error ? err.name : 'unknown' });
    return NextResponse.json({ error: 'Internal error' }, { status: 500 });
  }
}

export const dynamic = 'force-dynamic';
