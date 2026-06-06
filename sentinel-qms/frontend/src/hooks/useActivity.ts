import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AuditLogRecord } from '@/types';

/**
 * Record-scoped activity/history for a single entity, sourced from the
 * immutable audit log via GET /audit-logs/record. Any authenticated user who
 * can view the record may read its history.
 */
export function useActivity(entityType: string, entityId?: string | number | null) {
  const id = entityId == null ? '' : String(entityId);
  return useQuery<AuditLogRecord[]>({
    queryKey: ['activity', entityType, id],
    enabled: Boolean(entityType) && id !== '',
    queryFn: async () => {
      const { data } = await api.get<AuditLogRecord[]>('/audit-logs/record', {
        params: { entity_type: entityType, entity_id: id, limit: 100 },
      });
      return data ?? [];
    },
  });
}
