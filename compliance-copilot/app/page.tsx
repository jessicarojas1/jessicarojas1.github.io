'use client';

import { useMemo } from 'react';
import { ShieldCheck, AlertTriangle, Clock, CheckCircle, TrendingUp, FileWarning } from 'lucide-react';
import { SEED_CONTROLS, SEED_EVIDENCE } from '@/lib/data';
import { computeSummary, statusColor, statusLabel } from '@/lib/utils';
import { StatusBadge } from '@/components/controls/StatusBadge';
import Link from 'next/link';

export default function DashboardPage() {
  const summary = useMemo(() => computeSummary(SEED_CONTROLS), []);
  const needsAttention = SEED_CONTROLS.filter(c =>
    c.status === 'not_implemented' || c.status === 'partially_implemented'
  ).sort((a, b) => {
    const order = { critical: 0, high: 1, medium: 2, low: 3 };
    return order[a.priority] - order[b.priority];
  }).slice(0, 5);

  const gaugeStroke = (val: number) => {
    const r = 52, circ = 2 * Math.PI * r;
    return { strokeDasharray: circ, strokeDashoffset: circ * (1 - val / 100) };
  };

  const scoreColor = (s: number) => s >= 80 ? '#10b981' : s >= 60 ? '#f59e0b' : '#ef4444';

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Compliance Dashboard</h1>
        <p className="text-sm text-slate-400 mt-1">CMMC Level 2 · NIST SP 800-171 · {new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</p>
      </div>

      {/* Score + stat cards */}
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
        {/* Gauge */}
        <div className="card p-6 flex flex-col items-center justify-center">
          <svg width="130" height="130" className="-rotate-90">
            <circle cx="65" cy="65" r="52" fill="none" stroke="#1e293b" strokeWidth="10"/>
            <circle cx="65" cy="65" r="52" fill="none"
              stroke={scoreColor(summary.overall_score)} strokeWidth="10"
              strokeLinecap="round"
              style={gaugeStroke(summary.overall_score)}
              className="transition-all duration-1000" />
          </svg>
          <div className="-mt-20 text-center">
            <div className="text-3xl font-bold" style={{color: scoreColor(summary.overall_score)}}>
              {summary.overall_score}%
            </div>
            <div className="text-xs text-slate-400 mt-0.5">Overall Score</div>
          </div>
          <div className="mt-6 text-center">
            <div className="text-sm font-semibold text-slate-200">Compliance Posture</div>
            <div className={`text-xs mt-1 font-medium ${summary.overall_score >= 80 ? 'text-emerald-400' : summary.overall_score >= 60 ? 'text-amber-400' : 'text-red-400'}`}>
              {summary.overall_score >= 80 ? 'Audit Ready' : summary.overall_score >= 60 ? 'Needs Improvement' : 'High Risk'}
            </div>
          </div>
        </div>

        {/* Stat cards */}
        {[
          { label: 'Implemented', value: summary.implemented, icon: CheckCircle, color: 'text-emerald-400', bg: 'bg-emerald-500/10' },
          { label: 'Partially Impl.', value: summary.partially_implemented, icon: Clock, color: 'text-amber-400', bg: 'bg-amber-500/10' },
          { label: 'Not Implemented', value: summary.not_implemented, icon: AlertTriangle, color: 'text-red-400', bg: 'bg-red-500/10' },
        ].map(s => (
          <div key={s.label} className="card p-6 flex items-start gap-4">
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${s.bg}`}>
              <s.icon className={`w-5 h-5 ${s.color}`} />
            </div>
            <div>
              <div className={`text-2xl font-bold ${s.color}`}>{s.value}</div>
              <div className="text-sm text-slate-400 mt-0.5">{s.label}</div>
              <div className="text-xs text-slate-500 mt-1">{summary.total_controls} total controls</div>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {/* Domain breakdown */}
        <div className="card p-5 xl:col-span-2">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold text-slate-200">Domain Compliance</h2>
            <Link href="/controls" className="text-xs text-blue-400 hover:text-blue-300">View all controls →</Link>
          </div>
          <div className="space-y-3">
            {summary.domains.map(d => (
              <div key={d.domain}>
                <div className="flex items-center justify-between text-sm mb-1">
                  <span className="text-slate-300">{d.domain} — {d.domain_name}</span>
                  <span className={`font-medium text-xs ${d.score >= 80 ? 'text-emerald-400' : d.score >= 60 ? 'text-amber-400' : 'text-red-400'}`}>
                    {d.score}% ({d.implemented}/{d.total - d.not_applicable})
                  </span>
                </div>
                <div className="h-2 bg-slate-800 rounded-full overflow-hidden">
                  <div className="h-full rounded-full transition-all duration-700"
                    style={{ width: `${d.score}%`, background: d.score >= 80 ? '#10b981' : d.score >= 60 ? '#f59e0b' : '#ef4444' }} />
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Needs attention */}
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-4">
            <FileWarning className="w-4 h-4 text-amber-400" />
            <h2 className="font-semibold text-slate-200">Needs Attention</h2>
          </div>
          <div className="space-y-3">
            {needsAttention.map(c => (
              <Link key={c.id} href={`/controls/${c.id}`}
                className="block p-3 bg-slate-800/60 rounded-lg hover:bg-slate-800 transition-colors">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="text-xs font-mono text-blue-400">{c.control_id}</div>
                    <div className="text-sm text-slate-200 mt-0.5 leading-tight">{c.title}</div>
                  </div>
                  <StatusBadge status={c.status} size="xs" />
                </div>
              </Link>
            ))}
          </div>
        </div>
      </div>

      {/* Evidence stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: 'Evidence Items', value: SEED_EVIDENCE.length, icon: '📁' },
          { label: 'Reviewed', value: SEED_EVIDENCE.filter(e => e.reviewed).length, icon: '✅' },
          { label: 'Pending Review', value: SEED_EVIDENCE.filter(e => !e.reviewed).length, icon: '⏳' },
          { label: 'Expiring Soon', value: SEED_EVIDENCE.filter(e => e.expiry_date && new Date(e.expiry_date) < new Date(Date.now() + 90*24*60*60*1000)).length, icon: '⚠️' },
        ].map(s => (
          <div key={s.label} className="card p-4 flex items-center gap-3">
            <span className="text-2xl">{s.icon}</span>
            <div>
              <div className="text-xl font-bold text-slate-100">{s.value}</div>
              <div className="text-xs text-slate-400">{s.label}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
