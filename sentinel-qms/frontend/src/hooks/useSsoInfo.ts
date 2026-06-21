import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface SsoInfo {
  enabled: boolean;
  oidc: boolean;
  saml: boolean;
  cac: boolean;
  label: string;
}

/** Public: whether federated SSO is available (drives the login-page button). */
export function useSsoInfo() {
  return useQuery<SsoInfo>({
    queryKey: ['sso-info'],
    queryFn: async () => (await api.get<SsoInfo>('/auth/sso/info')).data,
    staleTime: 5 * 60_000,
    retry: false,
  });
}
