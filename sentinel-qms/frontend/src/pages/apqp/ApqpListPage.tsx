import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Workflow } from 'lucide-react';
import { useApqpProjects, useCreateApqp } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { ApqpPhase, ApqpStatus, PpapProgress } from '@/types';
import { PHASE_LABELS } from './constants';

const STATUSES: ApqpStatus[] = ['active', 'on_hold', 'complete', 'cancelled'];
const PHASES = Object.keys(PHASE_LABELS) as ApqpPhase[];
const statusLabel = (s: string) => s.replace(/_/g, ' ');

export function PpapBar({ ppap }: { ppap: PpapProgress }) {
  const tone = ppap.approved_pct >= 90 ? 'good' : ppap.approved_pct >= 50 ? 'warn' : 'bad';
  return (
    <div className="cov">
      <div className="cov-bar" role="img" aria-label={`${ppap.approved_pct}% PPAP approved`}>
        <span className={`cov-bar__fill cov-${tone}`} style={{ width: `${ppap.approved_pct}%` }} />
      </div>
      <div className="cov-meta">
        <strong>{ppap.approved_pct}%</strong>
        <span className="cov-counts">
          {ppap.approved}/{ppap.applicable} PPAP elements approved
        </span>
      </div>
    </div>
  );
}

export default function ApqpListPage() {
  const { data, isLoading, error } = useApqpProjects();
  const create = useCreateApqp();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('inspections');
  const [partNumber, setPartNumber] = useState('');
  const [partName, setPartName] = useState('');
  const [customer, setCustomer] = useState('');
  const [fPhase, setFPhase] = useState('');
  const [fStatus, setFStatus] = useState('');

  const projects = data ?? [];
  const filtered = projects.filter(
    (p) => (!fPhase || p.current_phase === fPhase) && (!fStatus || p.status === fStatus),
  );
  const activeFilters = (fPhase ? 1 : 0) + (fStatus ? 1 : 0);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!partNumber.trim() || !partName.trim()) return;
    create.mutate(
      { part_number: partNumber.trim(), part_name: partName.trim(), customer: customer.trim() || null },
      {
        onSuccess: () => {
          notify('APQP project created (PPAP package seeded)', 'success');
          setPartNumber('');
          setPartName('');
          setCustomer('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="APQP / PPAP"
        subtitle="AS9145 — Advanced Product Quality Planning and Production Part Approval."
        breadcrumbs={[{ label: 'Operations' }, { label: 'APQP / PPAP' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={submit}>
          <input className="input" placeholder="Part number" value={partNumber} onChange={(e) => setPartNumber(e.target.value)} aria-label="Part number" />
          <input className="input" placeholder="Part name" value={partName} onChange={(e) => setPartName(e.target.value)} aria-label="Part name" />
          <input className="input" placeholder="Customer (optional)" value={customer} onChange={(e) => setCustomer(e.target.value)} aria-label="Customer" />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>New project</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load APQP projects" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : data.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No APQP projects" description="Create a project to launch its PPAP submission package." /></div></div>
      ) : (
        <>
          <div className="card">
            <FilterBar active={activeFilters}>
              <select className="input field" value={fPhase} onChange={(e) => setFPhase(e.target.value)} aria-label="Filter by phase">
                <option value="">All phases</option>
                {PHASES.map((p) => <option key={p} value={p}>{PHASE_LABELS[p]}</option>)}
              </select>
              <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
                <option value="">All statuses</option>
                {STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
              </select>
            </FilterBar>
          </div>
          {filtered.length === 0 ? (
            <div className="card"><div className="card__body"><EmptyState title="No matching projects" description="No APQP projects match the selected filters." /></div></div>
          ) : (
            <div className="std-grid">
              {filtered.map((p) => (
            <Link key={p.id} to={`/apqp/${p.id}`} className="std-card card">
              <div className="std-card__head">
                <Workflow size={18} />
                <span className="std-card__code">{p.project_number}</span>
                <span className={`apqp-phase apqp-phase--${p.status}`}>{PHASE_LABELS[p.current_phase]}</span>
              </div>
              <div className="std-card__name">
                <span className="mono">{p.part_number}</span> — {p.part_name}
                {p.customer ? ` · ${p.customer}` : ''}
              </div>
              <PpapBar ppap={p.ppap} />
            </Link>
              ))}
            </div>
          )}
        </>
      )}
    </>
  );
}
