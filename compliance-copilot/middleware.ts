import { NextRequest, NextResponse } from 'next/server';
import {
  SESSION_COOKIE,
  sessionAuthConfiguredEdge,
  verifySessionTokenEdge,
} from '@/lib/session-edge';

// Gate the app behind the session login WHEN session auth is configured
// (APP_SESSION_SECRET + APP_AUTH_USERNAME + APP_AUTH_PASSWORD all set). If it is
// not configured (e.g. local dev), the middleware stays out of the way so the
// app remains usable without credentials. The AI relay route enforces its own
// fail-closed auth regardless of this middleware.
//
// This is an Edge-runtime middleware, so it uses lib/session-edge (Web Crypto)
// to verify only the cookie's HMAC signature + expiry. It is a UX gate; the API
// routes remain the real security boundary.

export async function middleware(req: NextRequest) {
  if (!sessionAuthConfiguredEdge()) return NextResponse.next();

  const session = await verifySessionTokenEdge(req.cookies.get(SESSION_COOKIE)?.value);
  if (session) return NextResponse.next();

  const url = req.nextUrl.clone();
  url.pathname = '/login';
  // Preserve where the user was headed (relative path only — no open redirect).
  url.searchParams.set('next', req.nextUrl.pathname + req.nextUrl.search);
  return NextResponse.redirect(url);
}

// Run on app pages only. Exclude the login page, all API routes (they do their
// own auth), Next internals, and static assets.
export const config = {
  matcher: ['/((?!login|api|_next/static|_next/image|favicon.ico).*)'],
};
