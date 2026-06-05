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

  return {
    params,
    page,
    pageSize,
    search,
    sort,
    order,
    filters,
    setPage,
    setSearch,
    onSortChange,
    setFilter,
  };
}
