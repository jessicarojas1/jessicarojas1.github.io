import { createResourceHooks } from './useResource';
import type {
  Audit,
  Capa,
  ChangeRequest,
  Complaint,
  ControlledDocument,
  Equipment,
  Inspection,
  MgmtReview,
  Nonconformance,
  Risk,
  Supplier,
  TrainingRecord,
  User,
} from '@/types';

/* Per-domain resource hooks. */
export const documentHooks = createResourceHooks<ControlledDocument>('documents');
export const ncrHooks = createResourceHooks<Nonconformance>('nonconformances');
export const capaHooks = createResourceHooks<Capa>('capa');
export const auditHooks = createResourceHooks<Audit>('audits');
export const supplierHooks = createResourceHooks<Supplier>('suppliers');
export const calibrationHooks = createResourceHooks<Equipment>('calibration/equipment');
export const trainingHooks = createResourceHooks<TrainingRecord>('training');
export const changeHooks = createResourceHooks<ChangeRequest>('changes');
export const riskHooks = createResourceHooks<Risk>('risks');
export const inspectionHooks = createResourceHooks<Inspection>('inspections');
export const mgmtReviewHooks = createResourceHooks<MgmtReview>('management-reviews');
export const complaintHooks = createResourceHooks<Complaint>('complaints');
export const userHooks = createResourceHooks<User>('users');

export * from './useDashboard';
export * from './useStandards';
export * from './useCounterfeit';
export * from './useApqp';
export * from './useFmea';
export * from './useFod';
export * from './useConcessions';
export * from './useSignatures';
export * from './useCustomers';
export * from './useCustomerSatisfaction';
export * from './useAuditPrograms';
export * from './useImprovements';
export * from './useMsa';
export * from './useQualityObjectives';
export * from './useSpc';
export * from './useSavedViews';
export * from './useDashboardWidgets';
export * from './useShares';
export * from './useTraining';
export * from './useSearch';
export * from './useNotifications';
export * from './useApiTokens';
export * from './useAnalytics';
export * from './useReports';
export * from './useAuditLogs';
export * from './useActivity';
export * from './useAttachments';
export * from './useUsers';
export * from './usePermissions';
export * from './useUserLookup';
export * from './useMyOpenItems';
export * from './useComments';
export * from './useSettings';
export * from './useBranding';
