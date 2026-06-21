// Edge-runtime-safe session verification for middleware.ts.
//
// Next.js middleware runs in the Edge Runtime, where Node's `crypto` module is
// not available. lib/session.ts uses node:crypto (for route handlers / Node
// runtime); importing it into middleware triggers an "unsupported module"
// warning. This file re-implements ONLY signature + expiry verification using
// the Web Crypto API (globalThis.crypto.subtle), which the Edge Runtime
// provides. It must stay byte-for-byte compatible with lib/session.ts:
//   token = base64url(JSON payload) + "." + base64url(HMAC-SHA256(payload))
// and the same SESSION_COOKIE name / >=16-char secret rule.

export const SESSION_COOKIE = 'cc_session';

export interface SessionPayload {
  sub: string;
  iat: number;
  exp: number;
}

function fromB64url(s: string): Uint8Array {
  const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
  const b64 = s.replace(/-/g, '+').replace(/_/g, '/') + pad;
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function b64urlFromBytes(bytes: ArrayBuffer): string {
  const b = new Uint8Array(bytes);
  let bin = '';
  for (let i = 0; i < b.length; i++) bin += String.fromCharCode(b[i]);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function secret(): string | null {
  const s = process.env.APP_SESSION_SECRET || '';
  return s.length >= 16 ? s : null;
}

// Constant-time-ish comparison of two equal-length strings.
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

async function hmac(data: string, key: string): Promise<string> {
  const enc = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    enc.encode(key),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const sig = await crypto.subtle.sign('HMAC', cryptoKey, enc.encode(data));
  return b64urlFromBytes(sig);
}

export async function verifySessionTokenEdge(
  token: string | undefined | null,
): Promise<SessionPayload | null> {
  const key = secret();
  if (!key || !token) return null;
  const dot = token.indexOf('.');
  if (dot <= 0) return null;
  const body = token.slice(0, dot);
  const sig = token.slice(dot + 1);
  if (!safeEqual(sig, await hmac(body, key))) return null;
  try {
    const json = new TextDecoder().decode(fromB64url(body));
    const payload = JSON.parse(json) as SessionPayload;
    if (typeof payload.exp !== 'number' || Date.now() > payload.exp) return null;
    if (typeof payload.sub !== 'string' || !payload.sub) return null;
    return payload;
  } catch {
    return null;
  }
}

export function sessionAuthConfiguredEdge(): boolean {
  const s = process.env.APP_SESSION_SECRET || '';
  return Boolean(
    s.length >= 16 && process.env.APP_AUTH_USERNAME && process.env.APP_AUTH_PASSWORD,
  );
}
