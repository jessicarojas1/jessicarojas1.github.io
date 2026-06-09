import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import {
  useConcessions,
  useCreateConcession,
  useDeleteConcession,
  useUpdateConcession,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { Concession, ConcessionStatus, ConcessionType } from '@/types';

const TYPES: ConcessionType[] = ['deviation', 'waiver', 'concession'];
const STATUSES: ConcessionStatus[] = [
  'draft',
  'submitted',
  'under_review',
  'approved',
  'rejected',
  'expired',
  'closed',
];
const label = (s: string) => s.replace(/_/g, ' ');

export default function ConcessionsPage() {
  const { data, isLoading, error } = useConcessions();
  const create = useCreateConcession();
  const update = useUpdateConcession();
  const remove = useDeleteConcession();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('nonconformances');

  const [type, setType] = useState<ConcessionType>('deviation');
  const [title, setTitle] = useState('');
  const [part, setPart] = useState('');
  const [description, setDescription] = useState('');
  const [fType, setFType] = useState('');
  const [fStatus, setFStatus] = useState('');

  const list = data ?? [];
  const filtered = list.filter(
    (c) => (!fType || c.concession_type === fType) && (!fStatus || c.status === fStatus),
  );
  const activeFilters = (fType ? 1 : 0) + (fStatus ? 1 : 0);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !description.trim()) return;
    create.mutate(
      {
        title: title.trim(),
        description: description.trim(),
        concession_type: type,
        part_number: part.trim() || null,
      },
      {
        onSuccess: () => {
          setTitle('');
          setPart('');
          setDescription('');
          notify('Concession created', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (c: Concession, payload: Partial<Concession>) =>
    update.mutate({ id: c.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <>
      <PageHeader
        title="Concessions & Deviations"
        subtitle="Authorized, bounded permits to depart from requirements (AS9100 8.7 + deviation/waiver permits)."
        breadcrumbs={[{ label: 'Quality' }, { label: 'Concessions' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={add}>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as ConcessionType)} aria-label="Type">
            {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
          </select>
          <input className="input" placeholder="Part number" value={part} onChange={(e) => setPart(e.target.value)} aria-label="Part number" />
          <input className="input" placeholder="Title" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Title" />
          <input className="input" placeholder="Deviation requested" value={description} onChange={(e) => setDescription(e.target.value)} aria-label="Description" />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load concessions" description={getErrorMessage(error)} /></div></div>
      ) : (
        <div className="card">
          <div className="card__header">
            <div className="card__title">Concessions</div>
          </div>
          <FilterBar active={activeFilters}>
            <select className="input field" value={fType} onChange={(e) => setFType(e.target.value)} aria-label="Filter by type">
              <option value="">All types</option>
              {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
            </select>
            <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
              <option value="">All statuses</option>
              {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Type</th>
                  <th>Part</th>
                  <th>Title</th>
                  <th>Qty</th>
                  <th>Expiry</th>
                  <th>Cust.</th>
                  <th>Status</th>
                  {writable && <th aria-label="actions" />}
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr><td colSpan={9}><span className="spinner" /> Loading…</td></tr>
                ) : filtered.length ? (
                  filtered.map((c) => (
                    <tr key={c.id}>
                      <td className="mono">{c.concession_number}</td>
                      <td style={{ textTransform: 'capitalize' }}>{c.concession_type}</td>
                      <td className="mono">{c.part_number ?? '—'}</td>
                      <td>{c.title}</td>
                      <td>{c.quantity ?? '—'}</td>
                      <td>{c.expiry_date ? formatDate(c.expiry_date) : '—'}</td>
                      <td>
                        {writable ? (
                          <input
                            type="checkbox"
                            className="checkbox"
                            checked={c.customer_approved}
                            onChange={(e) => patch(c, { customer_approved: e.target.checked })}
                            aria-label="Customer approved"
                          />
                        ) : c.customer_approved ? (
                          '✓'
                        ) : (
                          '—'
                        )}
                      </td>
                      <td>
                        {writable ? (
                          <select
                            className="input input-sm"
                            value={c.status}
                            onChange={(e) => patch(c, { status: e.target.value as ConcessionStatus })}
                          >
                            {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                          </select>
                        ) : (
                          <span className={`con-status con-status--${c.status}`}>{label(c.status)}</span>
                        )}
                      </td>
                      {writable && (
                        <td>
                          <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(c.id)}>
                            <Trash2 size={15} />
                          </button>
                        </td>
                      )}
                    </tr>
                  ))
                ) : (
                  <tr className="empty-row"><td colSpan={9}><div className="empty-state-sm">{list.length ? 'No concessions match the selected filters.' : 'No concessions.'}</div></td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
