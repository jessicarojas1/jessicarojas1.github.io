import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface CommentRecord {
  id: number;
  entity_type: string;
  entity_id: string;
  author_id: number;
  body: string;
  parent_id: number | null;
  created_at: string | null;
}

export function useComments(entityType: string, entityId: string | number | undefined) {
  const eid = entityId != null ? String(entityId) : '';
  return useQuery<CommentRecord[]>({
    queryKey: ['comments', entityType, eid],
    queryFn: async () => {
      const { data } = await api.get<CommentRecord[]>('/comments', {
        params: { entity_type: entityType, entity_id: eid },
      });
      return data;
    },
    enabled: Boolean(eid),
  });
}

export function useAddComment(entityType: string, entityId: string | number | undefined) {
  const qc = useQueryClient();
  const eid = entityId != null ? String(entityId) : '';
  return useMutation({
    mutationFn: async (payload: { body: string; mentions?: number[]; parent_id?: number | null }) => {
      const { data } = await api.post<CommentRecord>('/comments', {
        entity_type: entityType,
        entity_id: eid,
        body: payload.body,
        mentions: payload.mentions ?? [],
        parent_id: payload.parent_id ?? null,
      });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['comments', entityType, eid] }),
  });
}

export function useDeleteComment(entityType: string, entityId: string | number | undefined) {
  const qc = useQueryClient();
  const eid = entityId != null ? String(entityId) : '';
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/comments/${id}`);
      return id;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['comments', entityType, eid] }),
  });
}
