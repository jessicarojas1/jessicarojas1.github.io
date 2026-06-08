import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { SearchResponse, SearchResult } from '@/types';

/** Debounce a value by the given delay (ms). */
export function useDebounced<T>(value: T, delay = 250): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

/** Global search: queries /search once the (debounced) term is >= 2 chars. */
export function useGlobalSearch(term: string, limit = 20) {
  const q = term.trim();
  const enabled = q.length >= 2;
  return useQuery<SearchResult[]>({
    queryKey: ['search', q, limit],
    queryFn: async () => {
      const { data } = await api.get<SearchResponse>('/search', {
        params: { q, limit },
      });
      return data.results ?? [];
    },
    enabled,
    staleTime: 15_000,
  });
}
