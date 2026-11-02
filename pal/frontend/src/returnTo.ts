/**
 * Returns a same-origin path to go to after sign-in, or '/'. The query value is attacker
 * controlled: browsers treat "\" as "/" and strip tabs and newlines, so "/\evil.example"
 * and "/<tab>/evil.example" would otherwise leave the site. Mirrors the server's
 * ReturnToValidator.
 */
export function safeReturnTo(value: unknown): string {
  return typeof value === 'string' && /^\/(?![/\\])[A-Za-z0-9\-._~/?=&%#+:@,]*$/.test(value) ? value : '/'
}
