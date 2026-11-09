// Shared display helpers so the same data reads the same way on every page.

export const ROLE_LABELS: Record<string, string> = {
  READER: 'Reader',
  AUTHOR: 'Author',
  PUBLISHER: 'Publisher',
  PAL_ADMINISTRATOR: 'PAL Administrator',
  SYSTEM_ADMINISTRATOR: 'System Administrator',
  OWNER: 'Owner',
}

export function roleLabel(role: string): string {
  return ROLE_LABELS[role] ?? role
}

/** Formats an ISO timestamp in the user's PAL timezone (not the browser's), labelled. */
export function formatDateTime(iso: string | null | undefined, timeZone: string): string {
  const date = new Date(iso ?? '')
  // Intl throws a RangeError on an invalid date, which would break the whole page render.
  if (Number.isNaN(date.getTime())) return '—'
  // dateStyle/timeStyle cannot be combined with timeZoneName, so list the fields explicitly.
  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZone,
    timeZoneName: 'short',
  }).format(date)
}
