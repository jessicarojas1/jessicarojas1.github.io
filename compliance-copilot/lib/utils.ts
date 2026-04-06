import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { ControlStatus, Priority, ComplianceSummary, Control, DomainSummary } from './types';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function statusLabel(status: ControlStatus): string {
  const map: Record<ControlStatus, string> = {
    implemented:           'Implemented',
    partially_implemented: 'Partial',
    not_implemented:       'Not Implemented',
    not_applicable:        'N/A',
    planned:               'Planned',
  };
  return map[status] ?? status;
}

export function statusColor(status: ControlStatus): string {
  const map: Record<ControlStatus, string> = {
    implemented:           'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    partially_implemented: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
    not_implemented:       'bg-red-500/15 text-red-400 border-red-500/30',
    not_applicable:        'bg-slate-500/15 text-slate-400 border-slate-500/30',
    planned:               'bg-blue-500/15 text-blue-400 border-blue-500/30',
  };
  return map[status] ?? '';
}

export function priorityColor(priority: Priority): string {
  const map: Record<Priority, string> = {
    critical: 'bg-red-500/20 text-red-400',
    high:     'bg-orange-500/20 text-orange-400',
    medium:   'bg-amber-500/20 text-amber-400',
    low:      'bg-slate-500/20 text-slate-400',
  };
  return map[priority] ?? '';
}

export function computeSummary(controls: Control[]): ComplianceSummary {
  const domainMap = new Map<string, DomainSummary>();

  for (const c of controls) {
    if (!domainMap.has(c.domain)) {
      domainMap.set(c.domain, {
        domain: c.domain,
        domain_name: c.domain_name,
        total: 0, implemented: 0, partially: 0,
        not_implemented: 0, not_applicable: 0, planned: 0, score: 0,
      });
    }
    const d = domainMap.get(c.domain)!;
    d.total++;
    if (c.status === 'implemented')           d.implemented++;
    else if (c.status === 'partially_implemented') d.partially++;
    else if (c.status === 'not_implemented')  d.not_implemented++;
    else if (c.status === 'not_applicable')   d.not_applicable++;
    else if (c.status === 'planned')          d.planned++;
  }

  const domains = Array.from(domainMap.values()).map(d => {
    const eligible = d.total - d.not_applicable;
    d.score = eligible > 0
      ? Math.round(((d.implemented + d.partially * 0.5) / eligible) * 100)
      : 100;
    return d;
  });

  const eligible = controls.filter(c => c.status !== 'not_applicable').length;
  const impl     = controls.filter(c => c.status === 'implemented').length;
  const partial  = controls.filter(c => c.status === 'partially_implemented').length;

  return {
    total_controls:        controls.length,
    implemented:           impl,
    partially_implemented: partial,
    not_implemented:       controls.filter(c => c.status === 'not_implemented').length,
    not_applicable:        controls.filter(c => c.status === 'not_applicable').length,
    planned:               controls.filter(c => c.status === 'planned').length,
    overall_score:         eligible > 0 ? Math.round(((impl + partial * 0.5) / eligible) * 100) : 0,
    domains,
  };
}

export function formatDate(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}
