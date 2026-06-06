import { useUserLookup, type UserId } from '@/hooks/useUserLookup';

export interface UserNameProps {
  /** The user id to resolve into a display name (numeric or string-encoded). */
  id: UserId;
}

/**
 * Renders a user's full name (resolved via the cached user directory) instead
 * of a raw numeric id. Falls back to email, then `User #<id>`, then '—'.
 * The email is surfaced as a tooltip when available.
 */
export function UserName({ id }: UserNameProps) {
  const { map, toUserKey } = useUserLookup();

  const key = toUserKey(id);
  if (key == null) return <>—</>;

  const entry = map[key];
  if (!entry) return <>{`User #${key}`}</>;

  const label = entry.full_name || entry.email || `User #${key}`;
  return <span title={entry.email || undefined}>{label}</span>;
}
