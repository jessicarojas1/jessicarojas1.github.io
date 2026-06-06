import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** Organization settings & branding (singleton). */
export interface OrgSettings {
  id: number;
  organization_name: string;
  logo_url: string | null;
  primary_color: string | null;
  support_email: string | null;
  default_review_cycle_days: number;
  calibration_default_interval_days: number;
  timezone: string;
}

/** Fields accepted by PUT /settings — all optional. */
export type OrgSettingsUpdate = Partial<Omit<OrgSettings, 'id'>>;

const KEY = ['settings'] as const;

/** Organization settings — used for branding everywhere, so cache it long. */
export function useOrgSettings() {
  return useQuery<OrgSettings>({
    queryKey: KEY,
    queryFn: async () => {
      const { data } = await api.get<OrgSettings>('/settings');
      return data;
    },
    staleTime: 10 * 60_000,
  });
}

/** Persist organization settings (admin only) and refresh the cache. */
export function useUpdateSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: OrgSettingsUpdate) => {
      const { data } = await api.put<OrgSettings>('/settings', payload);
      return data;
    },
    onSuccess: (data) => {
      qc.setQueryData(KEY, data);
      qc.invalidateQueries({ queryKey: KEY });
    },
  });
}
