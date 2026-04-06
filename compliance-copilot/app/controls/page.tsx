'use client';

import { useState, useMemo } from 'react';
import Link from 'next/link';
import { Search, Filter, ChevronRight, ShieldCheck } from 'lucide-react';
import { SEED_CONTROLS } from '@/lib/data';
import { StatusBadge } from '@/components/controls/StatusBadge';
import { PriorityBadge } from '@/components/controls/PriorityBadge';
import { ControlStatus, Priority } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const DOMAINS  = Array.from(new Set(SEED_CONTROLS.map(c => c.domain))).sort();
const STATUSES: ControlStatus[] = ['implemented','partially_implemented','not_implemented','planned','not_applicable'];
const PRIORITIES: Priority[]    = ['critical','high','medium','low'];

export default function ControlsPage() {
  const [search,   setSearch]   = useState('');
  const [domain,   setDomain]   = useState('');
  const [status,   setStatus]   = useState('');
  const [priority, setPriority] = useState('');
  const [level,    setLevel]    = useState('');

  const filtered = useMemo(() => SEED_CONTROLS.filter(c => {
    if (domain   && c.domain   !== domain)              return false;
    if (status   && c.status   !== status)              return false;
    if (priority && c.priority !== priority)            return false;
    if (level    && String(c.cmmc_level) !== level)     return false;
    if (search) {
      const q = search.toLowerCase();
      return c.control_id.includes(q) || c.title.toLowerCase().includes(q) || c.requirement.toLowerCase().includes(q);
    }
    return true;
  }), [search, domain, status, priority, level]);

  const counts = useMemo(() => ({
    implemented:           SEED_CONTROLS.filter(c => c.status === 'implemented').length,
    partially_implemented: SEED_CONTROLS.filter(c => c.status === 'partially_implemented').length,
    not_implemented:       SEED_CONTROLS.filter(c => c.status === 'not_implemented').length,
  }), []);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Controls Library</h1>
          <p className="text-sm text-slate-400 mt-1">NIST SP 800-171 Rev 2 · {SEED_CONTROLS.length} controls</p>
        </div>
        <div className="flex gap-2 text-xs text-slate-400">
          <span className="text-emerald-400 font-medium">{counts.implemented} ✓</span>
          <span className="text-amber-400 font-medium">{counts.partially_implemented} ~</span>
          <span className="text-red-400 font-medium">{counts.not_implemented} ✗</span>
        </div>
      </div>

      {/* Filters */}
      <div className="card p-4 flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-48">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input className="input pl-9" placeholder="Search by ID, title, or requirement…"
            value={search} onChange={e => setSearch(e.target.value)} />
        </div>
        <select className="select w-auto min-w-32" value={domain} onChange={e => setDomain(e.target.value)}>
          <option value="">All Domains</option>
          {DOMAINS.map(d => <option key={d} value={d}>{d}</option>)}
        </select>
        <select className="select w-auto min-w-40" value={status} onChange={e => setStatus(e.target.value)}>
          <option value="">All Statuses</option>
          {STATUSES.map(s => <option key={s} value={s}>{s.replace(/_/g,' ')}</option>)}
        </select>
        <select className="select w-auto min-w-32" value={priority} onChange={e => setPriority(e.target.value)}>
          <option value="">All Priorities</option>
          {PRIORITIES.map(p => <option key={p} value={p}>{p}</option>)}
        </select>
        <select className="select w-auto min-w-32" value={level} onChange={e => setLevel(e.target.value)}>
          <option value="">CMMC Level</option>
          <option value="1">Level 1</option>
          <option value="2">Level 2</option>
          <option value="3">Level 3</option>
        </select>
        {(search || domain || status || priority || level) && (
          <button className="btn-ghost text-xs" onClick={() => { setSearch(''); setDomain(''); setStatus(''); setPriority(''); setLevel(''); }}>
            Clear filters
          </button>
        )}
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-800">
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-24">Control</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Title / Requirement</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-28">Domain</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-20">Level</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-36">Status</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-28">Priority</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide w-28">Last Review</th>
                <th className="w-8"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr><td colSpan={8} className="text-center text-slate-500 py-12">No controls match the current filters.</td></tr>
              )}
              {filtered.map(c => (
                <tr key={c.id} className="table-row">
                  <td className="px-4 py-3">
                    <span className="font-mono text-blue-400 text-xs font-semibold">{c.control_id}</span>
                  </td>
                  <td className="px-4 py-3 max-w-xs">
                    <div className="font-medium text-slate-200">{c.title}</div>
                    <div className="text-xs text-slate-500 mt-0.5 line-clamp-1">{c.requirement}</div>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs bg-slate-800 text-slate-300 px-2 py-0.5 rounded font-mono">{c.domain}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs text-slate-400">L{c.cmmc_level}</span>
                  </td>
                  <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                  <td className="px-4 py-3"><PriorityBadge priority={c.priority} /></td>
                  <td className="px-4 py-3 text-xs text-slate-500">{formatDate(c.last_reviewed)}</td>
                  <td className="px-4 py-3">
                    <Link href={`/controls/${c.id}`} className="text-slate-500 hover:text-slate-200">
                      <ChevronRight className="w-4 h-4" />
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="px-4 py-3 border-t border-slate-800 text-xs text-slate-500">
          Showing {filtered.length} of {SEED_CONTROLS.length} controls
        </div>
      </div>
    </div>
  );
}
