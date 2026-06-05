import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ListParams, Paginated } from '@/types';

/**
 * Generic CRUD hook factory bound to a REST resource under /api/v1.
 * Every domain re-exports thin wrappers around these.
 */
export function createResourceHooks<T extends { id: string }>(resource: string) {
  const baseKey = [resource] as const;

  function useList(
    params: ListParams = {},
    options?: Partial<UseQueryOptions<Paginated<T>>>,
  ) {
    return useQuery<Paginated<T>>({
      queryKey: [...baseKey, 'list', params],
      queryFn: async () => {
        // Translate frontend param names to the backend's query contract:
        // page_size -> size, sort -> sort_by. Everything else passes through.
        const { page_size, sort, ...rest } = params;
        const query: Record<string, string | number | undefined> = { ...rest };
        if (page_size != null) query.size = page_size;
        if (sort != null) query.sort_by = sort;
        const { data } = await api.get<Paginated<T> & { size?: number }>(
          `/${resource}`,
          { params: query },
        );
        // Backend envelope exposes `size`; surface it as `page_size` too.
        return { ...data, page_size: data.page_size ?? data.size ?? page_size ?? 25 };
      },
      ...options,
    });
  }

  function useDetail(id: string | undefined, options?: Partial<UseQueryOptions<T>>) {
    return useQuery<T>({
      queryKey: [...baseKey, 'detail', id],
      queryFn: async () => {
        const { data } = await api.get<T>(`/${resource}/${id}`);
        return data;
      },
      enabled: Boolean(id),
      ...options,
    });
  }

  function useCreate() {
    const qc = useQueryClient();
    return useMutation({
      mutationFn: async (payload: Partial<T>) => {
        const { data } = await api.post<T>(`/${resource}`, payload);
        return data;
      },
      onSuccess: () => qc.invalidateQueries({ queryKey: baseKey }),
    });
  }

  function useUpdate() {
    const qc = useQueryClient();
    return useMutation({
      mutationFn: async ({ id, ...payload }: Partial<T> & { id: string }) => {
        const { data } = await api.patch<T>(`/${resource}/${id}`, payload);
        return data;
      },
      onSuccess: (data) => {
        qc.invalidateQueries({ queryKey: baseKey });
        qc.setQueryData([...baseKey, 'detail', data.id], data);
      },
    });
  }

  function useRemove() {
    const qc = useQueryClient();
    return useMutation({
      mutationFn: async (id: string) => {
        await api.delete(`/${resource}/${id}`);
        return id;
      },
      onSuccess: () => qc.invalidateQueries({ queryKey: baseKey }),
    });
  }

  /** POST to a custom sub-action, e.g. /{resource}/{id}/{action}. */
  function useAction<P = unknown, R = T>(action: string) {
    const qc = useQueryClient();
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload?: P }) => {
        const { data } = await api.post<R>(`/${resource}/${id}/${action}`, payload ?? {});
        return data;
      },
      onSuccess: () => qc.invalidateQueries({ queryKey: baseKey }),
    });
  }

  return { baseKey, useList, useDetail, useCreate, useUpdate, useRemove, useAction };
}
