import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import {
  useAddContractRequirement,
  useContract,
  useDeleteContractRequirement,
  useUpdateContractRequirement,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataList, DetailState } from '@/components/detail';
import type { ContractRequirement, FlowDownStatus, FlowDownTo } from '@/types';

const FLOW_TO: FlowDownTo[] = ['internal', 'supplier', 'both'];
const FLOW_STATUS: FlowDownStatus[] = ['open', 'flowed_down', 'verified', 'not_applicable'];
const label = (s: string) => s.replace(/_/g, ' ');

function RequirementRow({ req, writable }: { req: ContractRequirement; writable: boolean }) {
  const update = useUpdateContractRequirement();
  const remove = useDeleteContractRequirement();
  const { notify } = useToast();
  const patch = (payload: Partial<ContractRequirement>) =>
    update.mutate({ id: req.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <tr>
      <td className="mono">{req.clause ?? '—'}</td>
      <td>{req.description}</td>
      <td>
        {writable ? (
          <select className="input input-sm" value={req.flow_down_to} onChange={(e) => patch({ flow_down_to: e.target.value as FlowDownTo })}>
            {FLOW_TO.map((f) => <option key={f} value={f}>{label(f)}</option>)}
          </select>
        ) : (
          label(req.flow_down_to)
        )}
      </td>
      <td>
        {writable ? (
          <select className="input input-sm" value={req.status} onChange={(e) => patch({ status: e.target.value as FlowDownStatus })}>
            {FLOW_STATUS.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
        ) : (
          <span className={`con-status con-status--${req.status === 'flowed_down' || req.status === 'verified' ? 'approved' : 'draft'}`}>{label(req.status)}</span>
        )}
      </td>
      {writable && (
        <td>
          <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(req.id)}>
            <Trash2 size={15} />
          </button>
        </td>
      )}
    </tr>
  );
}

export default function ContractDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: c, isLoading, error } = useContract(id);
  const add = useAddContractRequirement(Number(id));
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('suppliers');
  const [clause, setClause] = useState('');
  const [description, setDescription] = useState('');

  const addReq = (e: React.FormEvent) => {
    e.preventDefault();
    if (!description.trim()) return;
    add.mutate(
      { clause: clause.trim() || undefined, description: description.trim(), flow_down_to: 'internal' },
      {
        onSuccess: () => {
          setClause('');
          setDescription('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <DetailState loading={isLoading} error={error ? getErrorMessage(error) : null} notFound={!isLoading && !error && !c}>
      {c && (
        <>
          <PageHeader
            title={<span className="mono">{c.contract_number}</span>}
            subtitle={c.title}
            breadcrumbs={[
              { label: 'Operations' },
              { label: 'Customers & Contracts', to: '/customers' },
              { label: c.contract_number },
            ]}
          />
          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Flow-down Requirements ({c.requirements.length})</div>
                </div>
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr><th>Clause</th><th>Requirement</th><th>Flow to</th><th>Status</th>{writable && <th aria-label="actions" />}</tr>
                    </thead>
                    <tbody>
                      {c.requirements.length ? (
                        c.requirements.map((r) => <RequirementRow key={r.id} req={r} writable={writable} />)
                      ) : (
                        <tr className="empty-row"><td colSpan={writable ? 5 : 4}><div className="empty-state-sm">No flow-down requirements.</div></td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
                {writable && (
                  <form className="std-new" onSubmit={addReq}>
                    <input className="input" placeholder="Clause" value={clause} onChange={(e) => setClause(e.target.value)} aria-label="Clause" />
                    <input className="input" placeholder="Requirement" value={description} onChange={(e) => setDescription(e.target.value)} aria-label="Requirement" />
                    <button type="submit" className="btn btn-primary btn-sm" disabled={add.isPending}>Add requirement</button>
                  </form>
                )}
              </div>
            </div>
            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Contract</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Status', value: label(c.status) },
                      { label: 'DPAS Rating', value: c.dpas_rating ?? '—' },
                      { label: 'ITAR Controlled', value: c.itar_controlled ? 'Yes' : 'No' },
                      { label: 'DFARS Clauses', value: c.dfars_clauses ?? '—' },
                      { label: 'Value', value: c.value != null ? `$${c.value.toLocaleString()}` : '—' },
                      { label: 'Start', value: formatDate(c.start_date) },
                      { label: 'End', value: formatDate(c.end_date) },
                    ]}
                  />
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
