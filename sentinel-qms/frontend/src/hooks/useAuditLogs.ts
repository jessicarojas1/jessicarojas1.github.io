import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AuditLogEntry, Paginated } from '@/types';

export interface AuditLogParams {
  entity_type?: string;
  action?: string;
  actor_email?: string;
  entity_id?: number;
  page?: number;
  size?: number;
}

/** Paginated audit-log query for the admin Audit Trail page. */
export function useAuditLogs(params: AuditLogParams = {}) {
  return useQuery<Paginated<AuditLogEntry>>({
    queryKey: ['audit-logs', params],
    queryFn: async () => {
      const clean: Record<string, string | number> = {};
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== '' && v !== null) clean[k] = v;
      }
      const { data } = await api.get<Paginated<AuditLogEntry>>('/audit-logs', {
        params: clean,
      });
      return data;
    },
  });
}
