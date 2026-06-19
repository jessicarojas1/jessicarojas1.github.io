import { NextRequest, NextResponse } from 'next/server';

// --- Abuse / cost controls -------------------------------------------------
// This route relays to the Anthropic API using the server's ANTHROPIC_API_KEY.
// Without limits it is an open, unauthenticated AI relay — anyone could drive
// unbounded spend (OWASP LLM10: Unbounded Consumption). We apply three guards:
//   1. Optional shared-token auth (AI_API_TOKEN) — mirrors the branding route's
//      Authorization: Bearer <token> convention. Set it in any shared deploy.
//   2. Always-on per-client fixed-window rate limit (best-effort, in-memory).
//   3. Hard input/output caps (prompt length + max_tokens).
const MAX_PROMPT_CHARS = 8000;
const MAX_TOKENS = 1024;
const RATE_LIMIT = 20; // requests
const RATE_WINDOW_MS = 60_000; // per minute, per client

// NOTE: in-memory limiter is per-process/best-effort only. Behind multiple
// instances, also enforce limits at the gateway/WAF or a shared store (Redis).
const buckets = new Map<string, { count: number; reset: number }>();

function clientKey(req: NextRequest): string {
  // Trust the platform-set forwarded header only as a coarse key; this is a
  // best-effort cost control, not an identity/security boundary.
  const xff = req.headers.get('x-forwarded-for') || '';
  return xff.split(',')[0].trim() || 'unknown';
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

export async function POST(req: NextRequest) {
  try {
    // 1. Optional shared-token auth.
    const authToken = process.env.AI_API_TOKEN;
    if (authToken) {
      const auth = req.headers.get('authorization') || '';
      const provided = /^bearer\s+/i.test(auth) ? auth.replace(/^bearer\s+/i, '').trim() : '';
      if (provided !== authToken) {
        return NextResponse.json({ error: 'Unauthorized' }, { status: 401 });
      }
    }

    // 2. Rate limit (always on).
    if (rateLimited(clientKey(req))) {
      return NextResponse.json(
        { error: 'Rate limit exceeded. Try again shortly.' },
        { status: 429, headers: { 'Retry-After': String(Math.ceil(RATE_WINDOW_MS / 1000)) } },
      );
    }

    const { prompt } = await req.json();

    // 3. Input validation / caps.
    if (typeof prompt !== 'string' || prompt.trim() === '') {
      return NextResponse.json({ error: 'Missing prompt' }, { status: 400 });
    }
    if (prompt.length > MAX_PROMPT_CHARS) {
      return NextResponse.json(
        { error: `Prompt too long (max ${MAX_PROMPT_CHARS} characters)` },
        { status: 413 },
      );
    }

    const apiKey = process.env.ANTHROPIC_API_KEY;
    if (!apiKey) {
      return NextResponse.json({ error: 'ANTHROPIC_API_KEY not configured' }, { status: 503 });
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
        max_tokens: MAX_TOKENS,
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
    return NextResponse.json({ error: 'Internal error' }, { status: 500 });
  }
}
