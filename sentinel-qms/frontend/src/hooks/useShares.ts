import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { RecordShare } from '@/types';

const KEY = ['shares'] as const;

/** Map a shared record's entity_type to its in-app detail route. */
export const SHARE_ROUTES: Record<string, string> = {
  nonconformance: '/nonconformances',
  capa: '/capa',
  document: '/documents',
  audit: '/audits',
  complaint: '/complaints',
  supplier: '/suppliers',
  risk: '/risks',
  change_order: '/changes',
  inspection: '/inspections',
  management_review: '/mgmt-reviews',
  apqp_project: '/apqp',
};

export function shareLink(s: RecordShare): string | null {
  const base = SHARE_ROUTES[s.entity_type];
  return base ? `${base}/${s.entity_id}` : null;
}

export function useMyShares() {
  return useQuery<RecordShare[]>({
    queryKey: [...KEY, 'mine'],
    queryFn: async () => (await api.get<RecordShare[]>('/shares/mine')).data,
    staleTime: 30_000,
  });
}

export function useCreateShare() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: {
      entity_type: string;
      entity_id: string;
      label: string;
      shared_with_user_id: number;
      note?: string | null;
    }) => (await api.post<RecordShare>('/shares', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteShare() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/shares/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
