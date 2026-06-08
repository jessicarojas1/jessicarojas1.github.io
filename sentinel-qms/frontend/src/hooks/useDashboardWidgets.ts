import { useCallback, useEffect, useState } from 'react';

export interface DashboardWidget {
  key: string;
  label: string;
}

/** The toggleable dashboard widgets, in display order. */
export const DASHBOARD_WIDGETS: DashboardWidget[] = [
  { key: 'kpis', label: 'KPI tiles' },
  { key: 'my_open_items', label: 'My open items' },
  { key: 'ncr_trend', label: 'Open NCR trend' },
  { key: 'capa_aging', label: 'CAPA aging' },
  { key: 'calibration_status', label: 'Calibration status' },
  { key: 'findings_by_clause', label: 'Audit findings by clause' },
  { key: 'supplier_performance', label: 'Supplier performance' },
];

const STORAGE_KEY = 'qms.dashboard.hidden';

function loadHidden(): Set<string> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return new Set(raw ? (JSON.parse(raw) as string[]) : []);
  } catch {
    return new Set();
  }
}

/** Per-user (per-browser) dashboard widget visibility, persisted locally. */
export function useDashboardWidgets() {
  const [hidden, setHidden] = useState<Set<string>>(loadHidden);

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify([...hidden]));
    } catch {
      /* ignore quota / privacy-mode errors */
    }
  }, [hidden]);

  const isVisible = useCallback((key: string) => !hidden.has(key), [hidden]);

  const toggle = useCallback((key: string) => {
    setHidden((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }, []);

  return { isVisible, toggle, hidden };
}
