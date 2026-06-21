import { createHmac, randomBytes, timingSafeEqual } from 'crypto';
import type { NextRequest } from 'next/server';

// --- Minimal, self-contained cookie session -------------------------------
// This app has no user-auth framework. Rather than pull in a heavy dependency,
// we issue a stateless, HMAC-signed session token stored in an HttpOnly cookie.
// The token never carries any server secret (e.g. AI_PROXY_TOKEN / the upstream
// API key) — it only proves "this browser completed a server-side login", so
// server routes can safely inject those secrets on the caller's behalf.
//
// Secret comes from APP_SESSION_SECRET (env, never hardcoded). Credentials come
// from APP_AUTH_USERNAME / APP_AUTH_PASSWORD (env). See .env.local.example.

export const SESSION_COOKIE = 'cc_session';
const SESSION_TTL_MS = 8 * 60 * 60 * 1000; // 8 hours

export interface SessionPayload {
  sub: string; // subject (username)
  iat: number; // issued-at (ms epoch)
  exp: number; // expiry (ms epoch)
}

function secret(): string | null {
  const s = process.env.APP_SESSION_SECRET || '';
  // Require a non-trivial secret; a short/empty secret would make forgery easy.
  return s.length >= 16 ? s : null;
}

function b64url(buf: Buffer): string {
  return buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromB64url(s: string): Buffer {
  const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
  return Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/') + pad, 'base64');
}

function sign(data: string, key: string): string {
  return b64url(createHmac('sha256', key).update(data).digest());
}

function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  if (ab.length !== bb.length) return false;
  return timingSafeEqual(ab, bb);
}

// Build a signed token: base64url(payload).signature
export function createSessionToken(username: string): string | null {
  const key = secret();
  if (!key) return null;
  const now = Date.now();
  const payload: SessionPayload = { sub: username, iat: now, exp: now + SESSION_TTL_MS };
  const body = b64url(Buffer.from(JSON.stringify(payload)));
  return `${body}.${sign(body, key)}`;
}

// Verify a token's signature + expiry. Returns the payload or null.
export function verifySessionToken(token: string | undefined | null): SessionPayload | null {
  const key = secret();
  if (!key || !token) return null;
  const dot = token.indexOf('.');
  if (dot <= 0) return null;
  const body = token.slice(0, dot);
  const sig = token.slice(dot + 1);
  if (!safeEqual(sig, sign(body, key))) return null;
  try {
    const payload = JSON.parse(fromB64url(body).toString('utf8')) as SessionPayload;
    if (typeof payload.exp !== 'number' || Date.now() > payload.exp) return null;
    if (typeof payload.sub !== 'string' || !payload.sub) return null;
    return payload;
  } catch {
    return null;
  }
}

// Read + verify the session from the incoming request's cookies. Works in both
// route handlers and edge middleware (no `next/headers` dependency).
export function getSession(req: NextRequest): SessionPayload | null {
  return verifySessionToken(req.cookies.get(SESSION_COOKIE)?.value);
}

// True when the env is configured well enough for sessions to work at all.
export function sessionAuthConfigured(): boolean {
  return Boolean(secret() && process.env.APP_AUTH_USERNAME && process.env.APP_AUTH_PASSWORD);
}

// Constant-time credential check against env-configured credentials.
export function verifyCredentials(username: string, password: string): boolean {
  const u = process.env.APP_AUTH_USERNAME || '';
  const p = process.env.APP_AUTH_PASSWORD || '';
  if (!u || !p) return false;
  // Evaluate both to keep timing roughly independent of which field is wrong.
  const okUser = safeEqual(username, u);
  const okPass = safeEqual(password, p);
  return okUser && okPass;
}

export const sessionCookieOptions = {
  httpOnly: true,
  secure: process.env.NODE_ENV === 'production',
  sameSite: 'strict' as const,
  path: '/',
  maxAge: Math.floor(SESSION_TTL_MS / 1000),
};

// Random value helper (kept for potential future CSRF token issuance).
export function randomToken(bytes = 32): string {
  return b64url(randomBytes(bytes));
}
