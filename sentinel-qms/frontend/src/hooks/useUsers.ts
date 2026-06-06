import { useMutation, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** An assignable role from GET /users/roles. */
export interface UserRoleOption {
  id: number;
  name: string;
  description?: string;
}

/** Assignable roles for the user create/edit forms. */
export function useUserRoles() {
  return useQuery<UserRoleOption[]>({
    queryKey: ['users', 'roles'],
    queryFn: async () => {
      const { data } = await api.get<UserRoleOption[]>('/users/roles');
      return data ?? [];
    },
    staleTime: 10 * 60_000,
  });
}

/** Admin password reset: POST /users/{id}/reset-password. */
export function useResetPassword() {
  return useMutation({
    mutationFn: async ({ id, password }: { id: string; password: string }) => {
      await api.post(`/users/${id}/reset-password`, { password });
    },
  });
}
