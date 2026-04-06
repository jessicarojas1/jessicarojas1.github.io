'use client';

import { useMemo, useState } from 'react';
import { Download, BarChart3, CheckSquare, AlertTriangle } from 'lucide-react';
import { SEED_CONTROLS, SEED_EVIDENCE } from '@/lib/data';
import { computeSummary, statusLabel, statusColor, formatDate } from '@/lib/utils';
import { StatusBadge } from '@/components/controls/StatusBadge';
import { PriorityBadge } from '@/components/controls/PriorityBadge';

export default function ReportsPage() {
  const summary = useMemo(() => computeSummary(SEED_CONTROLS), []);
  const poamItems = useMemo(() => SEED_CONTROLS.filter(c =>
    c.notes && (c.status === 'not_implemented' || c.status === 'partially_implemented')
  ), []);

  function exportCSV() {
    const headers = ['Control ID','Title','Domain','CMMC Level','Status','Priority','Last Reviewed','Next Review','Notes'];
    const rows = SEED_CONTROLS.map(c => [
      c.control_id, c.title, c.domain, c.cmmc_level, c.status, c.priority,
      c.last_reviewed ?? '', c.next_review ?? '', (c.notes ?? '').replace(/,/g,'')
    ]);
    const csv = [headers, ...rows].map(r => r.map(v => `"${v}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url;
    a.download = `compliance-report-${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(url);
  }

  function exportJSON() {
    const report = {
      generated: new Date().toISOString(),
      summary,
      controls: SEED_CONTROLS,
      evidence: SEED_EVIDENCE,
    };
    const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url;
    a.download = `compliance-report-${new Date().toISOString().slice(0,10)}.json`;
    a.click(); URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Reports & Export</h1>
          <p className="text-sm text-slate-400 mt-1">Assessment summary as of {new Date().toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'})}</p>
        </div>
        <div className="flex gap-2">
          <button className="btn-secondary flex items-center gap-2" onClick={exportCSV}>
            <Download className="w-4 h-4" /> Export CSV
          </button>
          <button className="btn-primary flex items-center gap-2" onClick={exportJSON}>
            <Download className="w-4 h-4" /> Export JSON
          </button>
        </div>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        {[
          { label: 'Overall Score',     value: summary.overall_score + '%', cls: summary.overall_score >= 80 ? 'text-emerald-400' : summary.overall_score >= 60 ? 'text-amber-400' : 'text-red-400' },
          { label: 'Implemented',       value: summary.implemented,           cls: 'text-emerald-400' },
          { label: 'Partially Impl.',   value: summary.partially_implemented, cls: 'text-amber-400'   },
          { label: 'Not Implemented',   value: summary.not_implemented,       cls: 'text-red-400'     },
          { label: 'Planned',           value: summary.planned,               cls: 'text-blue-400'    },
        ].map(s => (
          <div key={s.label} className="card p-4 text-center">
            <div className={`text-2xl font-bold ${s.cls}`}>{s.value}</div>
            <div className="text-xs text-slate-400 mt-1">{s.label}</div>
          </div>
        ))}
      </div>

      {/* Domain table */}
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-slate-800 flex items-center gap-2">
          <BarChart3 className="w-4 h-4 text-slate-400" />
          <span className="font-semibold text-slate-200">Domain Breakdown</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-slate-800">
              {['Domain','Name','Score','Implemented','Partial','Not Impl.','N/A','Total'].map(h => (
                <th key={h} className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{h}</th>
              ))}
            </tr></thead>
            <tbody>
              {summary.domains.map(d => (
                <tr key={d.domain} className="table-row">
                  <td className="px-4 py-3 font-mono text-xs text-blue-400 font-semibold">{d.domain}</td>
                  <td className="px-4 py-3 text-slate-300">{d.domain_name}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <div className="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                        <div className="h-full rounded-full" style={{width:`${d.score}%`,background:d.score>=80?'#10b981':d.score>=60?'#f59e0b':'#ef4444'}} />
                      </div>
                      <span className={`text-xs font-medium ${d.score>=80?'text-emerald-400':d.score>=60?'text-amber-400':'text-red-400'}`}>{d.score}%</span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-emerald-400">{d.implemented}</td>
                  <td className="px-4 py-3 text-amber-400">{d.partially}</td>
                  <td className="px-4 py-3 text-red-400">{d.not_implemented}</td>
                  <td className="px-4 py-3 text-slate-500">{d.not_applicable}</td>
                  <td className="px-4 py-3 text-slate-300">{d.total}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* POA&M table */}
      {poamItems.length > 0 && (
        <div className="card overflow-hidden">
          <div className="px-5 py-4 border-b border-slate-800 flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-amber-400" />
            <span className="font-semibold text-slate-200">POA&amp;M Items ({poamItems.length})</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-800">
                {['Control','Title','Status','Priority','Notes / Remediation'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{h}</th>
                ))}
              </tr></thead>
              <tbody>
                {poamItems.map(c => (
                  <tr key={c.id} className="table-row">
                    <td className="px-4 py-3 font-mono text-xs text-blue-400">{c.control_id}</td>
                    <td className="px-4 py-3 text-slate-200 max-w-xs">{c.title}</td>
                    <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                    <td className="px-4 py-3"><PriorityBadge priority={c.priority} /></td>
                    <td className="px-4 py-3 text-xs text-slate-400 max-w-sm">{c.notes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
