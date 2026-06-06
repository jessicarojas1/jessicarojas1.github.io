import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PermLevel } from '@/lib/permissions';

/** A single page definition from GET /pages. */
export interface PageDef {
  key: string;
  label: string;
  group: string;
  admin_only: boolean;
}

/** role_name -> page_key -> level. Shape of GET/PUT /permissions/roles. */
export type RolePermissionMatrix = Record<string, Record<string, PermLevel>>;

/** Catalog of gated pages, grouped on the server. */
export function usePages() {
  return useQuery<PageDef[]>({
    queryKey: ['pages'],
    queryFn: async () => {
      const { data } = await api.get<PageDef[]>('/pages');
      return data ?? [];
    },
    staleTime: 10 * 60_000,
  });
}

/** Full role × page permission matrix (admin only). */
export function useRolePermissions() {
  return useQuery<RolePermissionMatrix>({
    queryKey: ['permissions', 'roles'],
    queryFn: async () => {
      const { data } = await api.get<RolePermissionMatrix>('/permissions/roles');
      return data ?? {};
    },
  });
}

/** Persist the full matrix back to the server. */
export function useSaveRolePermissions() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (matrix: RolePermissionMatrix) => {
      const { data } = await api.put<RolePermissionMatrix>('/permissions/roles', matrix);
      return data;
    },
    onSuccess: (data) => {
      qc.setQueryData(['permissions', 'roles'], data);
      // The current user's effective permissions may have changed.
      qc.invalidateQueries({ queryKey: ['permissions', 'me'] });
    },
  });
}
