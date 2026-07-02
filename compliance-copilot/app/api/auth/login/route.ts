import { NextRequest, NextResponse } from 'next/server';
import {
  SESSION_COOKIE,
  createSessionToken,
  getSession,
  sessionAuthConfigured,
  sessionCookieOptions,
  verifyCredentials,
} from '@/lib/session';
import { requestId, withRequestId } from '@/lib/logger';

// Best-effort, in-memory rate limit for login attempts (per client IP) to slow
// credential brute-forcing. Per-process only; pair with a WAF in production.
const attempts = new Map<string, { count: number; reset: number }>();
const MAX_ATTEMPTS = 10;
const WINDOW_MS = 60_000;

function clientIp(req: NextRequest): string {
  const xff = req.headers.get('x-forwarded-for') || '';
  return xff.split(',')[0].trim() || 'unknown';
}

function tooManyAttempts(ip: string): boolean {
  const now = Date.now();
  const b = attempts.get(ip);
  if (!b || now > b.reset) {
    attempts.set(ip, { count: 1, reset: now + WINDOW_MS });
    return false;
  }
  b.count += 1;
  return b.count > MAX_ATTEMPTS;
}

// GET /api/auth/login — session status (used by the client to decide login UI).
export async function GET(req: NextRequest) {
  const session = getSession(req);
  return NextResponse.json({
    authenticated: Boolean(session),
    user: session?.sub ?? null,
    configured: sessionAuthConfigured(),
  });
}

// POST /api/auth/login — exchange username+password for a session cookie.
export async function POST(req: NextRequest) {
  const log = withRequestId(requestId(req.headers), { route: '/api/auth/login' });
  if (!sessionAuthConfigured()) {
    return NextResponse.json(
      { ok: false, error: 'Login is not configured on this server.' },
      { status: 503 },
    );
  }

  if (tooManyAttempts(clientIp(req))) {
    log.warn('login rate limited', { status: 429 });
    return NextResponse.json(
      { ok: false, error: 'Too many attempts. Try again shortly.' },
      { status: 429, headers: { 'Retry-After': String(Math.ceil(WINDOW_MS / 1000)) } },
    );
  }

  let body: { username?: unknown; password?: unknown };
  try {
    body = await req.json();
  } catch {
    return NextResponse.json({ ok: false, error: 'Invalid JSON' }, { status: 400 });
  }

  const username = typeof body.username === 'string' ? body.username : '';
  const password = typeof body.password === 'string' ? body.password : '';

  if (!verifyCredentials(username, password)) {
    log.warn('login failed', { status: 401 });
    return NextResponse.json({ ok: false, error: 'Invalid credentials' }, { status: 401 });
  }

  const token = createSessionToken(username);
  if (!token) {
    return NextResponse.json(
      { ok: false, error: 'Session is not configured on this server.' },
      { status: 503 },
    );
  }

  log.info('login ok', { user: username });
  const res = NextResponse.json({ ok: true, user: username });
  res.cookies.set(SESSION_COOKIE, token, sessionCookieOptions);
  return res;
}

// DELETE /api/auth/login — log out (clear the cookie).
export async function DELETE() {
  const res = NextResponse.json({ ok: true });
  res.cookies.set(SESSION_COOKIE, '', { ...sessionCookieOptions, maxAge: 0 });
  return res;
}

export const dynamic = 'force-dynamic';
