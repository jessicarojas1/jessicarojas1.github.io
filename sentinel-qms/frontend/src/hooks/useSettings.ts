import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** How often the scheduled report digest is emailed. */
export type ReportFrequency = 'daily' | 'weekly' | 'monthly';

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
  notifications_email_enabled: boolean;
  teams_webhook_url: string | null;
  slack_webhook_url: string | null;
  // SLA escalation.
  sla_enabled: boolean;
  sla_capa_due_soon_days: number;
  sla_ncr_minor_days: number;
  sla_ncr_major_days: number;
  sla_ncr_critical_days: number;
  // Scheduled report digest.
  report_schedule_enabled: boolean;
  report_schedule_frequency: ReportFrequency;
  report_schedule_recipients: string | null;
  report_schedule_last_sent_at: string | null;
  // Executive dashboard KPI targets.
  kpi_target_open_ncrs: number;
  kpi_target_overdue_capas: number;
  kpi_target_open_findings: number;
  kpi_target_escapes: number;
  kpi_target_capa_on_time: number;
  kpi_target_supplier_quality: number;
  kpi_target_supplier_otd: number;
  // Cost of Quality per-event unit costs.
  coq_cost_ncr: number;
  coq_cost_complaint: number;
  coq_cost_inspection: number;
  coq_cost_audit: number;
  coq_cost_capa: number;
}

/** Notification channels that can be test-fired. */
export type NotificationChannel = 'email' | 'teams' | 'slack';

/** Result of a notification test send. */
export interface NotificationTestResult {
  ok: boolean;
  detail: string;
}

/** Summary returned by a manual SLA sweep. */
export interface SlaSweepResult {
  enabled: boolean;
  capa_overdue: number;
  capa_due_soon: number;
  capa_action_overdue: number;
  ncr_overdue: number;
}

/** Result of a manual report-digest send. */
export interface DigestSendResult {
  ok: boolean;
  sent: number;
  detail: string;
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

/** Fire a test notification on a channel (admin only). Never throws on send failure. */
export function useTestNotification() {
  return useMutation<NotificationTestResult, unknown, NotificationChannel>({
    mutationFn: async (channel) => {
      const { data } = await api.post<NotificationTestResult>(
        '/settings/notifications/test',
        { channel },
      );
      return data;
    },
  });
}

/** Run the SLA escalation sweep on demand (admin only). */
export function useRunSlaSweep() {
  const qc = useQueryClient();
  return useMutation<SlaSweepResult, unknown, void>({
    mutationFn: async () => {
      const { data } = await api.post<SlaSweepResult>('/settings/sla/run');
      return data;
    },
    onSuccess: () => {
      // New escalations create notifications — refresh the bell.
      qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
}

/** Send the report digest now (admin only). Never throws on send failure. */
export function useSendDigest() {
  const qc = useQueryClient();
  return useMutation<DigestSendResult, unknown, string[] | undefined>({
    mutationFn: async (recipients) => {
      const { data } = await api.post<DigestSendResult>('/settings/reports/send-digest', {
        recipients: recipients ?? null,
      });
      return data;
    },
    onSuccess: () => {
      // A successful send stamps report_schedule_last_sent_at.
      qc.invalidateQueries({ queryKey: KEY });
    },
  });
}
