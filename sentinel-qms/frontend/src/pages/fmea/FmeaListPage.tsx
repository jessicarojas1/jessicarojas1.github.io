import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useCreateFmea, useFmeas, useUserLookup } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { FmeaType } from '@/types';

const TYPES: FmeaType[] = ['process', 'design'];
const label = (s: string) => s.replace(/_/g, ' ');

/** RAG tone for the worksheet's max RPN. */
export function rpnTone(rpn: number): string {
  if (rpn >= 200) return 'high';
  if (rpn >= 80) return 'medium';
  return 'low';
}

export default function FmeaListPage() {
  const { data, isLoading, error } = useFmeas();
  const create = useCreateFmea();
  const { list: users } = useUserLookup();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('fmea');

  const [title, setTitle] = useState('');
  const [fmeaType, setFmeaType] = useState<FmeaType>('process');
  const [part, setPart] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [fType, setFType] = useState('');

  const rows = data ?? [];
  const filtered = rows.filter((f) => !fType || f.fmea_type === fType);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) return;
    create.mutate(
      {
        title: title.trim(),
        fmea_type: fmeaType,
        part_number: part.trim() || null,
        owner_id: ownerId === '' ? null : Number(ownerId),
      },
      {
        onSuccess: () => {
          setTitle('');
          setPart('');
          setOwnerId('');
          notify('FMEA created', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="FMEA"
        subtitle="Process & Design Failure Mode and Effects Analysis with RPN (AS9145 / AIAG-VDA)."
        breadcrumbs={[{ label: 'Control' }, { label: 'FMEA' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="FMEA title" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="FMEA title" />
          <select className="input" value={fmeaType} onChange={(e) => setFmeaType(e.target.value as FmeaType)} aria-label="FMEA type">
            {TYPES.map((t) => <option key={t} value={t}>{t === 'process' ? 'PFMEA' : 'DFMEA'}</option>)}
          </select>
          <input className="input" placeholder="Part #" value={part} onChange={(e) => setPart(e.target.value)} aria-label="Part number" style={{ maxWidth: 120 }} />
          <select className="input" value={ownerId} onChange={(e) => setOwnerId(e.target.value)} aria-label="Owner">
            <option value="">Owner (unassigned)</option>
            {users.filter((u) => u.is_active).map((u) => <option key={u.id} value={u.id}>{u.full_name || u.email}</option>)}
          </select>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>New FMEA</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : rows.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No FMEAs yet" description="Create a PFMEA or DFMEA worksheet to start analyzing failure modes." /></div></div>
      ) : (
        <div className="card">
          <FilterBar active={fType ? 1 : 0}>
            <select className="input field" value={fType} onChange={(e) => setFType(e.target.value)} aria-label="Filter by type">
              <option value="">All types</option>
              {TYPES.map((t) => <option key={t} value={t}>{t === 'process' ? 'PFMEA' : 'DFMEA'}</option>)}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>FMEA</th><th>Title</th><th>Type</th><th>Part</th><th>Owner</th><th>Items</th><th>Max RPN</th><th>Status</th></tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row"><td colSpan={8}><div className="empty-state-sm">No FMEAs match the selected filter.</div></td></tr>
                ) : filtered.map((f) => (
                  <tr key={f.id}>
                    <td className="mono"><Link to={`/fmea/${f.id}`} className="link-btn">{f.fmea_number}</Link></td>
                    <td>{f.title}</td>
                    <td>{f.fmea_type === 'process' ? 'PFMEA' : 'DFMEA'}</td>
                    <td className="mono">{f.part_number ?? '—'}</td>
                    <td>{f.owner_name ?? '—'}</td>
                    <td>{f.item_count}</td>
                    <td>{f.max_rpn > 0 ? <span className={`cfp-risk cfp-risk--${rpnTone(f.max_rpn)}`}>{f.max_rpn}</span> : '—'}</td>
                    <td>{label(f.status)}</td>
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
