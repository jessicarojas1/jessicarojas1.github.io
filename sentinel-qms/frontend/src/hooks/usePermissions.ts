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

/** A user's page-permission breakdown from GET /permissions/users. */
export interface UserPermissionRow {
  id: number;
  full_name: string;
  email: string;
  roles: string[];
  role_default: Record<string, PermLevel>;
  explicit: Record<string, PermLevel>;
  effective: Record<string, PermLevel>;
}

/** Per-user effective/override matrix (admin only). */
export function useUserPermissions() {
  return useQuery<UserPermissionRow[]>({
    queryKey: ['permissions', 'users'],
    queryFn: async () => {
      const { data } = await api.get<UserPermissionRow[]>('/permissions/users');
      return data ?? [];
    },
  });
}

/** Save a user's explicit overrides ({page_key: level|"inherit"}). */
export function useSaveUserOverrides() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { userId: number; overrides: Record<string, string> }) => {
      const { data } = await api.put<UserPermissionRow>(
        `/permissions/users/${args.userId}`,
        { overrides: args.overrides },
      );
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['permissions', 'users'] });
      qc.invalidateQueries({ queryKey: ['permissions', 'me'] });
    },
  });
}
