import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface ESignature {
  id: number;
  entity_type: string;
  entity_id: string;
  signer_id: number;
  signer_name: string;
  meaning: string;
  reason: string | null;
  signed_hash: string | null;
  signed_at: string;
}

/** List 21 CFR Part 11 e-signatures captured against a single record. */
export function useSignatures(entityType: string, entityId?: string | number | null) {
  const id = entityId == null ? '' : String(entityId);
  return useQuery<ESignature[]>({
    queryKey: ['signatures', entityType, id],
    enabled: Boolean(entityType) && id !== '',
    queryFn: async () => {
      const { data } = await api.get<ESignature[]>('/signatures', {
        params: { entity_type: entityType, entity_id: id },
      });
      return data ?? [];
    },
  });
}
