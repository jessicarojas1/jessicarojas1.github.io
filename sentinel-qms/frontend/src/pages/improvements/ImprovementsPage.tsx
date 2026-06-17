import { useState } from 'react';
import {
  useCreateImprovement,
  useImprovements,
  useUpdateImprovement,
  useUserLookup,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type {
  Improvement,
  ImprovementCategory,
  ImprovementPriority,
  ImprovementStatus,
} from '@/types';

const CATEGORIES: ImprovementCategory[] = [
  'kaizen',
  'suggestion',
  'process',
  'cost_saving',
  'safety',
  'quality',
];
const STATUSES: ImprovementStatus[] = ['idea', 'evaluating', 'in_progress', 'done', 'rejected'];
const PRIORITIES: ImprovementPriority[] = ['low', 'medium', 'high'];
const label = (s: string) => s.replace(/_/g, ' ');
const priorityTone = (p: ImprovementPriority) =>
  p === 'high' ? 'high' : p === 'medium' ? 'medium' : 'low';

function StatusCell({ item }: { item: Improvement }) {
  const update = useUpdateImprovement(item.id);
  const { notify } = useToast();
  return (
    <select
      className="input input-sm"
      value={item.status}
      onChange={(e) =>
        update.mutate(
          { status: e.target.value as ImprovementStatus },
          { onError: (err) => notify(getErrorMessage(err), 'danger') },
        )
      }
      aria-label={`Status for ${item.improvement_number}`}
    >
      {STATUSES.map((s) => (
        <option key={s} value={s}>
          {label(s)}
        </option>
      ))}
    </select>
  );
}

export default function ImprovementsPage() {
  const { data, isLoading, error } = useImprovements();
  const create = useCreateImprovement();
  const { list: users } = useUserLookup();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('improvements');

  const [title, setTitle] = useState('');
  const [category, setCategory] = useState<ImprovementCategory>('kaizen');
  const [priority, setPriority] = useState<ImprovementPriority>('medium');
  const [ownerId, setOwnerId] = useState('');
  const [benefit, setBenefit] = useState('');
  const [fStatus, setFStatus] = useState('');
  const [fCategory, setFCategory] = useState('');

  const rows = data ?? [];
  const filtered = rows.filter(
    (i) => (!fStatus || i.status === fStatus) && (!fCategory || i.category === fCategory),
  );
  const activeFilters = (fStatus ? 1 : 0) + (fCategory ? 1 : 0);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) return;
    create.mutate(
      {
        title: title.trim(),
        category,
        priority,
        owner_id: ownerId === '' ? null : Number(ownerId),
        estimated_benefit: benefit.trim() === '' ? null : Number(benefit),
      },
      {
        onSuccess: () => {
          setTitle('');
          setBenefit('');
          setOwnerId('');
          notify('Improvement logged', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Continual Improvement"
        subtitle="Kaizen, suggestions and improvement opportunities with benefit tracking (AS9100/ISO 9001 clause 10.3)."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Continual Improvement' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Improvement idea" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Improvement title" />
          <select className="input" value={category} onChange={(e) => setCategory(e.target.value as ImprovementCategory)} aria-label="Category">
            {CATEGORIES.map((c) => <option key={c} value={c}>{label(c)}</option>)}
          </select>
          <select className="input" value={priority} onChange={(e) => setPriority(e.target.value as ImprovementPriority)} aria-label="Priority">
            {PRIORITIES.map((p) => <option key={p} value={p}>{label(p)}</option>)}
          </select>
          <select className="input" value={ownerId} onChange={(e) => setOwnerId(e.target.value)} aria-label="Owner">
            <option value="">Owner (unassigned)</option>
            {users.filter((u) => u.is_active).map((u) => <option key={u.id} value={u.id}>{u.full_name || u.email}</option>)}
          </select>
          <input className="input" type="number" step="any" placeholder="Est. benefit $" value={benefit} onChange={(e) => setBenefit(e.target.value)} aria-label="Estimated benefit" style={{ maxWidth: 130 }} />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : rows.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No improvements yet" description="Capture a kaizen, suggestion or improvement opportunity to get started." /></div></div>
      ) : (
        <div className="card">
          <FilterBar active={activeFilters}>
            <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
              <option value="">All statuses</option>
              {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
            </select>
            <select className="input field" value={fCategory} onChange={(e) => setFCategory(e.target.value)} aria-label="Filter by category">
              <option value="">All categories</option>
              {CATEGORIES.map((c) => <option key={c} value={c}>{label(c)}</option>)}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>ID</th><th>Improvement</th><th>Category</th><th>Owner</th><th>Priority</th>
                  <th>Est. $</th><th>Realized $</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row"><td colSpan={8}><div className="empty-state-sm">No improvements match the selected filters.</div></td></tr>
                ) : filtered.map((i) => (
                  <tr key={i.id}>
                    <td className="mono">{i.improvement_number}</td>
                    <td>{i.title}</td>
                    <td>{label(i.category)}</td>
                    <td>{i.owner_name ?? '—'}</td>
                    <td><span className={`cfp-risk cfp-risk--${priorityTone(i.priority)}`}>{label(i.priority)}</span></td>
                    <td className="mono">{i.estimated_benefit != null ? `$${i.estimated_benefit.toLocaleString()}` : '—'}</td>
                    <td className="mono">{i.realized_benefit != null ? `$${i.realized_benefit.toLocaleString()}` : '—'}</td>
                    <td>{writable ? <StatusCell item={i} /> : <span className={`con-status con-status--${i.status}`}>{label(i.status)}</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
