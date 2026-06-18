import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface ApiToken {
  id: number;
  name: string;
  token_prefix: string;
  scopes: string[];
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  created_at: string | null;
  active: boolean;
}

/** Returned once on creation — carries the one-time plaintext secret. */
export interface ApiTokenCreated extends ApiToken {
  token: string;
}

export interface ApiTokenCreatePayload {
  name: string;
  scopes: string[];
  expires_in_days?: number | null;
}

const KEY = ['api-tokens'] as const;

export function useApiTokens() {
  return useQuery<ApiToken[]>({
    queryKey: KEY,
    queryFn: async () => (await api.get<ApiToken[]>('/tokens')).data,
  });
}

export function useCreateApiToken() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: ApiTokenCreatePayload) =>
      (await api.post<ApiTokenCreated>('/tokens', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRevokeApiToken() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/tokens/${id}`);
      return id;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
