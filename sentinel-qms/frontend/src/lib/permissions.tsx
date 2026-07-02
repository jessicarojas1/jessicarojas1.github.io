import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  type ReactNode,
} from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import { useAuth } from './auth';
import { can, type Capability } from './rbac';

/**
 * Per-page access level returned by the backend permission system.
 * LEVELS order: none < view < edit.
 */
export type PermLevel = 'none' | 'view' | 'edit';

/** Server response of GET /permissions/me — only pages with access appear. */
export type MyPermissions = Record<string, 'view' | 'edit'>;

/**
 * Static fallback capability mapping used whenever the dynamic page-permission
 * fetch is loading, errored, or simply does not mention a page key. This is the
 * lockout-prevention safety net: if the new system is unavailable we degrade to
 * the existing role/capability checks so admins (and everyone else) keep the
 * exact access they had before this feature shipped.
 *
 * `read`/`write` set to `null` means "no capability gate" — any authenticated
 * user may view/edit (e.g. the static Documentation page).
 */
// eslint-disable-next-line react-refresh/only-export-components
export const PAGE_FALLBACK_CAPS: Record<
  string,
  { read: Capability | null; write: Capability | null }
> = {
  dashboard: { read: 'ncr.read', write: null },
  analytics: { read: 'ncr.read', write: null },
  documentation: { read: null, write: null },
  nonconformances: { read: 'ncr.read', write: 'ncr.write' },
  capa: { read: 'capa.read', write: 'capa.write' },
  complaints: { read: 'complaints.read', write: 'complaints.write' },
  risks: { read: 'risks.read', write: 'risks.write' },
  documents: { read: 'documents.read', write: 'documents.write' },
  changes: { read: 'changes.read', write: 'changes.write' },
  audits: { read: 'audits.read', write: 'audits.write' },
  inspections: { read: 'inspections.read', write: 'inspections.write' },
  suppliers: { read: 'suppliers.read', write: 'suppliers.write' },
  calibration: { read: 'calibration.read', write: 'calibration.write' },
  training: { read: 'training.read', write: 'training.write' },
  mgmt_reviews: { read: 'mgmt_reviews.read', write: 'mgmt_reviews.write' },
  quality_objectives: { read: 'quality_objectives.read', write: 'quality_objectives.write' },
  improvements: { read: 'improvements.read', write: 'improvements.write' },
  lessons_learned: { read: 'lessons_learned.read', write: 'lessons_learned.write' },
  retention: { read: 'retention.read', write: 'retention.write' },
  customer_satisfaction: { read: 'csat.read', write: 'csat.write' },
  fmea: { read: 'fmea.read', write: 'fmea.write' },
  users: { read: 'admin.users', write: 'admin.users' },
  roles: { read: 'admin.roles', write: 'admin.roles' },
  permissions: { read: 'admin.roles', write: 'admin.roles' },
  audit_trail: { read: 'admin.users', write: 'admin.users' },
};

interface PagePermsState {
  canView: (page: string) => boolean;
  canEdit: (page: string) => boolean;
  /** True once the dynamic fetch has resolved successfully. */
  isLoaded: boolean;
}

const PagePermsContext = createContext<PagePermsState | undefined>(undefined);

export function PagePermsProvider({ children }: { children: ReactNode }) {
  const { user, isAuthenticated } = useAuth();

  const query = useQuery<MyPermissions>({
    queryKey: ['permissions', 'me'],
    queryFn: async () => {
      const { data } = await api.get<MyPermissions>('/permissions/me');
      return data ?? {};
    },
    enabled: isAuthenticated,
    staleTime: 5 * 60_000,
    retry: 1,
  });

  // The dynamic map is only trustworthy when the fetch succeeded.
  const dynamic = query.isSuccess ? query.data : undefined;
  const isLoaded = query.isSuccess;

  const fallbackView = useCallback(
    (page: string): boolean => {
      const entry = PAGE_FALLBACK_CAPS[page];
      // Unknown page or no capability gate: any authenticated user may view.
      if (!entry || entry.read == null) return isAuthenticated;
      return can(user?.roles, entry.read);
    },
    [user?.roles, isAuthenticated],
  );

  const fallbackEdit = useCallback(
    (page: string): boolean => {
      const entry = PAGE_FALLBACK_CAPS[page];
      if (!entry || entry.write == null) return isAuthenticated;
      return can(user?.roles, entry.write);
    },
    [user?.roles, isAuthenticated],
  );

  const canView = useCallback(
    (page: string): boolean => {
      const level = dynamic?.[page];
      if (level === 'view' || level === 'edit') return true;
      // Key present but explicitly absent from a loaded map => no dynamic grant,
      // but we still honor the static fallback so we never tighten beyond the
      // pre-existing behavior (no lockout).
      return fallbackView(page);
    },
    [dynamic, fallbackView],
  );

  const canEdit = useCallback(
    (page: string): boolean => {
      const level = dynamic?.[page];
      if (level === 'edit') return true;
      return fallbackEdit(page);
    },
    [dynamic, fallbackEdit],
  );

  const value = useMemo<PagePermsState>(
    () => ({ canView, canEdit, isLoaded }),
    [canView, canEdit, isLoaded],
  );

  return <PagePermsContext.Provider value={value}>{children}</PagePermsContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function usePagePerms(): PagePermsState {
  const ctx = useContext(PagePermsContext);
  if (!ctx) throw new Error('usePagePerms must be used within a PagePermsProvider');
  return ctx;
}
