import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** A directory entry from GET /users/lookup. */
export interface UserLookupEntry {
  id: number;
  full_name: string;
  email: string;
  is_active: boolean;
}

type UserLookupMap = Record<number, { full_name: string; email: string; is_active: boolean }>;

/** Accepts the various shapes user-id fields take across the app. */
export type UserId = number | string | null | undefined;

/** Coerce a user-id field (which may be string-encoded) into a numeric key. */
function toUserKey(id: UserId): number | null {
  if (id == null || id === '') return null;
  const n = typeof id === 'number' ? id : Number(id);
  return Number.isFinite(n) ? n : null;
}

/**
 * Loads the lightweight user directory once (cached for 5 minutes) and exposes
 * it as a map keyed by user id. Available to any authenticated user, so it can
 * resolve owner/assignee/approver names on non-admin pages.
 */
export function useUserLookup() {
  const query = useQuery<UserLookupEntry[]>({
    queryKey: ['users', 'lookup'],
    queryFn: async () => {
      const { data } = await api.get<UserLookupEntry[]>('/users/lookup');
      return data ?? [];
    },
    staleTime: 5 * 60_000,
  });

  const map = useMemo<UserLookupMap>(() => {
    const result: UserLookupMap = {};
    for (const u of query.data ?? []) {
      result[u.id] = { full_name: u.full_name, email: u.email, is_active: u.is_active };
    }
    return result;
  }, [query.data]);

  return { map, isLoading: query.isLoading, toUserKey };
}

/**
 * Returns a resolver `(id) => string` that yields a user's display name.
 * Falls back to email, then `User #<id>`, then '—' for null/undefined ids.
 */
export function useUserName(): (id: UserId) => string {
  const { map } = useUserLookup();
  return (id: UserId): string => {
    const key = toUserKey(id);
    if (key == null) return '—';
    const entry = map[key];
    if (!entry) return `User #${key}`;
    return entry.full_name || entry.email || `User #${key}`;
  };
}
