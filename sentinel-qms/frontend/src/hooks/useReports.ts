import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  AuditSummaryReport,
  CapaSummaryReport,
  NcrSummaryReport,
  SupplierScorecardReport,
} from '@/types';

/** NCR summary aggregate (status / severity breakdowns + monthly trend). */
export function useNcrSummaryReport(months = 12) {
  return useQuery<NcrSummaryReport>({
    queryKey: ['reports', 'ncr-summary', months],
    queryFn: async () => {
      const { data } = await api.get<NcrSummaryReport>('/reports/ncr-summary', {
        params: { months },
      });
      return data;
    },
  });
}

/** CAPA summary aggregate (status breakdown, aging buckets, overdue stats). */
export function useCapaSummaryReport(months = 12) {
  return useQuery<CapaSummaryReport>({
    queryKey: ['reports', 'capa-summary', months],
    queryFn: async () => {
      const { data } = await api.get<CapaSummaryReport>('/reports/capa-summary', {
        params: { months },
      });
      return data;
    },
  });
}

/** Supplier scorecard (quality / OTD averages, open SCARs per supplier). */
export function useSupplierScorecardReport() {
  return useQuery<SupplierScorecardReport>({
    queryKey: ['reports', 'supplier-scorecard'],
    queryFn: async () => {
      const { data } = await api.get<SupplierScorecardReport>('/reports/supplier-scorecard');
      return data;
    },
  });
}

/** Audit summary aggregate (by type / status, findings by type). */
export function useAuditSummaryReport(months = 12) {
  return useQuery<AuditSummaryReport>({
    queryKey: ['reports', 'audit-summary', months],
    queryFn: async () => {
      const { data } = await api.get<AuditSummaryReport>('/reports/audit-summary', {
        params: { months },
      });
      return data;
    },
  });
}
