/**
 * Returns a link target only when it uses a safe scheme (http/https) or is a
 * same-origin relative path; anything else (javascript:, data:, blob:, etc.)
 * collapses to '#'. Defense-in-depth for hrefs built from server-supplied
 * strings — a malicious attachment/record URL can't become a click-to-XSS.
 */
export function safeHref(url: string | null | undefined): string {
  if (!url) return '#';
  const u = url.trim();
  if (u.startsWith('/') || u.startsWith('./') || u.startsWith('../')) return u;
  try {
    const parsed = new URL(u, window.location.origin);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? u : '#';
  } catch {
    return '#';
  }
}
