import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface IamAction {
  key: string;
  label: string;
}
export interface IamModule {
  key: string;
  label: string;
  icon: string;
  actions: IamAction[];
}
export interface IamUser {
  id: number;
  full_name: string;
  email: string;
  roles: string[];
  role_default: string[];
  explicit: string[];
  effective: string[];
}

export function useIamCatalog() {
  return useQuery<IamModule[]>({
    queryKey: ['iam', 'catalog'],
    queryFn: async () => {
      const { data } = await api.get<{ modules: IamModule[] }>('/iam/catalog');
      return data?.modules ?? [];
    },
    staleTime: 10 * 60_000,
  });
}

export function useIamUsers() {
  return useQuery<IamUser[]>({
    queryKey: ['iam', 'users'],
    queryFn: async () => {
      const { data } = await api.get<IamUser[]>('/iam/users');
      return data ?? [];
    },
  });
}

export function useSaveUserGrants() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { userId: number; granted: string[] }) => {
      const { data } = await api.put<IamUser>(`/iam/users/${args.userId}`, {
        granted: args.granted,
      });
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['iam', 'users'] });
      qc.invalidateQueries({ queryKey: ['iam', 'me'] });
    },
  });
}
