import { useMemo, useState } from 'react';
import type { ListParams } from '@/types';

/** Manages search/sort/pagination/filters state for list pages. */
export function useListController(initial?: Partial<ListParams>) {
  const [page, setPage] = useState(initial?.page ?? 1);
  const [search, setSearchRaw] = useState(initial?.search ?? '');
  const [sort, setSort] = useState(initial?.sort ?? 'created_at');
  const [order, setOrder] = useState<'asc' | 'desc'>(initial?.order ?? 'desc');
  const [filters, setFilters] = useState<Record<string, string>>({});
  const pageSize = initial?.page_size ?? 20;

  const setSearch = (value: string) => {
    setSearchRaw(value);
    setPage(1);
  };

  const onSortChange = (nextSort: string, nextOrder: 'asc' | 'desc') => {
    setSort(nextSort);
    setOrder(nextOrder);
  };

  const setFilter = (key: string, value: string) => {
    setFilters((f) => {
      const next = { ...f };
      if (value) next[key] = value;
      else delete next[key];
      return next;
    });
    setPage(1);
  };

  const params: ListParams = useMemo(
    () => ({
      page,
      page_size: pageSize,
      search: search || undefined,
      sort,
      order,
      ...filters,
    }),
    [page, pageSize, search, sort, order, filters],
  );

  /** Serializable preset of the current view (for saving). */
  const viewParams = useMemo<Record<string, unknown>>(
    () => ({ search: search || undefined, sort, order, ...filters }),
    [search, sort, order, filters],
  );

  /** Apply a saved preset back onto the controller. */
  const applyView = (p: Record<string, unknown>) => {
    const { search: s, sort: so, order: o, page: _p, page_size: _ps, ...rest } = p;
    setSearchRaw(typeof s === 'string' ? s : '');
    setSort(typeof so === 'string' ? so : 'created_at');
    setOrder(o === 'asc' ? 'asc' : 'desc');
    const nextFilters: Record<string, string> = {};
    for (const [k, v] of Object.entries(rest)) {
      if (v != null && v !== '') nextFilters[k] = String(v);
    }
    setFilters(nextFilters);
    setPage(1);
  };

  return {
    params,
    page,
    pageSize,
    search,
    sort,
    order,
    filters,
    viewParams,
    applyView,
    setPage,
    setSearch,
    onSortChange,
    setFilter,
  };
}
