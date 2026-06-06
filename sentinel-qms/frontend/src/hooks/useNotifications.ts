import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { NotificationItem, Paginated } from '@/types';

const KEY = ['notifications'] as const;

/** Unread count, polled periodically for the bell badge. */
export function useUnreadCount() {
  return useQuery<number>({
    queryKey: [...KEY, 'unread-count'],
    queryFn: async () => {
      const { data } = await api.get<{ count: number }>('/notifications/unread-count');
      return data.count ?? 0;
    },
    refetchInterval: 60_000,
    staleTime: 30_000,
  });
}

/** Recent notifications list. */
export function useNotifications(params: { unread_only?: boolean; page?: number; size?: number } = {}) {
  return useQuery<Paginated<NotificationItem>>({
    queryKey: [...KEY, 'list', params],
    queryFn: async () => {
      const { data } = await api.get<Paginated<NotificationItem>>('/notifications', {
        params,
      });
      return data;
    },
  });
}

export function useMarkRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/notifications/${id}/read`);
      return id;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useMarkAllRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      await api.post('/notifications/read-all');
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
