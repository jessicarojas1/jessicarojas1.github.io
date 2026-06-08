import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import {
  useAddProgramItem,
  useAuditProgram,
  useDeleteProgramItem,
  useUpdateProgramItem,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { DetailState } from '@/components/detail';
import { UserName } from '@/components/UserName';
import type { AuditProgramItem, ProgramItemStatus } from '@/types';
import { ProgressBar } from './AuditProgramsPage';

const ITEM_STATUSES: ProgramItemStatus[] = ['planned', 'scheduled', 'completed', 'cancelled'];
const label = (s: string) => s.replace(/_/g, ' ');

function ItemRow({ item, writable }: { item: AuditProgramItem; writable: boolean }) {
  const update = useUpdateProgramItem();
  const remove = useDeleteProgramItem();
  const { notify } = useToast();
  const patch = (payload: Partial<AuditProgramItem>) =>
    update.mutate({ id: item.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <tr>
      <td>{item.area}</td>
      <td className="mono">{item.clause_reference ?? '—'}</td>
      <td className="mono">{item.planned_period ?? '—'}</td>
      <td>{item.lead_auditor_id == null ? '—' : <UserName id={item.lead_auditor_id} />}</td>
      <td>
        {writable ? (
          <select className="input input-sm" value={item.status} onChange={(e) => patch({ status: e.target.value as ProgramItemStatus })}>
            {ITEM_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
        ) : (
          <span className={`con-status con-status--${item.status === 'completed' ? 'approved' : item.status === 'cancelled' ? 'closed' : 'draft'}`}>{label(item.status)}</span>
        )}
      </td>
      <td>{item.audit_id ? <Link to={`/audits/${item.audit_id}`} className="link-btn">View audit</Link> : '—'}</td>
      {writable && (
        <td>
          <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(item.id)}>
            <Trash2 size={15} />
          </button>
        </td>
      )}
    </tr>
  );
}

export default function AuditProgramDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: p, isLoading, error } = useAuditProgram(id);
  const add = useAddProgramItem(Number(id));
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('audits');
  const [area, setArea] = useState('');
  const [clause, setClause] = useState('');
  const [period, setPeriod] = useState('');

  const addItem = (e: React.FormEvent) => {
    e.preventDefault();
    if (!area.trim()) return;
    add.mutate(
      { area: area.trim(), clause_reference: clause.trim() || undefined, planned_period: period.trim() || undefined },
      {
        onSuccess: () => {
          setArea('');
          setClause('');
          setPeriod('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <DetailState loading={isLoading} error={error ? getErrorMessage(error) : null} notFound={!isLoading && !error && !p}>
      {p && (
        <>
          <PageHeader
            title={<span>{p.name}</span>}
            subtitle={`FY ${p.year} · ${p.status}`}
            breadcrumbs={[{ label: 'Quality' }, { label: 'Audit Program', to: '/audit-programs' }, { label: String(p.year) }]}
          />
          <div className="card">
            <div className="card__body"><ProgressBar pct={p.progress.completed_pct} /></div>
          </div>
          <div className="card">
            <div className="card__header"><div className="card__title">Schedule ({p.items.length})</div></div>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr><th>Area / Process</th><th>Clause</th><th>Period</th><th>Lead</th><th>Status</th><th>Audit</th>{writable && <th aria-label="actions" />}</tr>
                </thead>
                <tbody>
                  {p.items.length ? (
                    p.items.map((it) => <ItemRow key={it.id} item={it} writable={writable} />)
                  ) : (
                    <tr className="empty-row"><td colSpan={writable ? 7 : 6}><div className="empty-state-sm">No scheduled audits.</div></td></tr>
                  )}
                </tbody>
              </table>
            </div>
            {writable && (
              <form className="std-new" onSubmit={addItem}>
                <input className="input" placeholder="Area / process" value={area} onChange={(e) => setArea(e.target.value)} aria-label="Area" />
                <input className="input" placeholder="Clause" value={clause} onChange={(e) => setClause(e.target.value)} aria-label="Clause" />
                <input className="input" placeholder="Period (e.g. 2026-Q1)" value={period} onChange={(e) => setPeriod(e.target.value)} aria-label="Period" />
                <button type="submit" className="btn btn-primary btn-sm" disabled={add.isPending}>Add to schedule</button>
              </form>
            )}
          </div>
        </>
      )}
    </DetailState>
  );
}
