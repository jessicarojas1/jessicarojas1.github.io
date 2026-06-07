import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import {
  useAddRequirement,
  useDeleteRequirement,
  useStandard,
  useUpdateRequirement,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { DetailState } from '@/components/detail';
import type { CoverageStatus, StandardRequirement } from '@/types';
import { CoverageBar } from './StandardsPage';

const STATUS_OPTIONS: { value: CoverageStatus; label: string }[] = [
  { value: 'covered', label: 'Covered' },
  { value: 'partial', label: 'Partial' },
  { value: 'gap', label: 'Gap' },
  { value: 'not_applicable', label: 'N/A' },
];

function RequirementRow({
  req,
  writable,
}: {
  req: StandardRequirement;
  writable: boolean;
}) {
  const update = useUpdateRequirement();
  const remove = useDeleteRequirement();
  const { notify } = useToast();

  const patch = (payload: Partial<StandardRequirement>) =>
    update.mutate(
      { id: req.id, payload },
      { onError: (err) => notify(getErrorMessage(err), 'danger') },
    );

  return (
    <tr>
      <td className="mono">{req.clause}</td>
      <td>{req.title}</td>
      <td>
        {writable ? (
          <input
            className="input input-sm"
            defaultValue={req.module_key ?? ''}
            placeholder="module"
            onBlur={(e) => {
              const v = e.target.value.trim() || null;
              if (v !== (req.module_key ?? null)) patch({ module_key: v });
            }}
          />
        ) : (
          req.module_key ?? '—'
        )}
      </td>
      <td>
        {writable ? (
          <select
            className="input input-sm"
            value={req.coverage_status}
            onChange={(e) => patch({ coverage_status: e.target.value as CoverageStatus })}
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        ) : (
          <span className={`cov-pill cov-pill--${req.coverage_status}`}>{req.coverage_status}</span>
        )}
      </td>
      <td>
        {writable ? (
          <input
            className="input input-sm"
            defaultValue={req.evidence_note ?? ''}
            placeholder="evidence note"
            onBlur={(e) => {
              const v = e.target.value.trim() || null;
              if (v !== (req.evidence_note ?? null)) patch({ evidence_note: v });
            }}
          />
        ) : (
          req.evidence_note ?? '—'
        )}
      </td>
      {writable && (
        <td>
          <button
            type="button"
            className="btn btn-icon btn-ghost"
            aria-label="Delete requirement"
            onClick={() => remove.mutate(req.id)}
          >
            <Trash2 size={15} />
          </button>
        </td>
      )}
    </tr>
  );
}

export default function StandardDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: std, isLoading, error } = useStandard(id);
  const add = useAddRequirement(Number(id));
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('users');
  const [clause, setClause] = useState('');
  const [title, setTitle] = useState('');

  const addReq = (e: React.FormEvent) => {
    e.preventDefault();
    if (!clause.trim() || !title.trim()) return;
    add.mutate(
      { clause: clause.trim(), title: title.trim(), coverage_status: 'gap' },
      {
        onSuccess: () => {
          setClause('');
          setTitle('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !std}
    >
      {std && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <span className="mono">{std.code}</span>
              </span>
            }
            subtitle={std.name}
            breadcrumbs={[
              { label: 'Administration' },
              { label: 'Standards', to: '/standards' },
              { label: std.code },
            ]}
          />

          <div className="card">
            <div className="card__body">
              <CoverageBar coverage={std.coverage} />
              {std.description && <p className="muted" style={{ marginTop: 12 }}>{std.description}</p>}
            </div>
          </div>

          <div className="card">
            <div className="card__header">
              <div className="card__title">Requirements ({std.requirements.length})</div>
            </div>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Clause</th>
                    <th>Title</th>
                    <th>Module</th>
                    <th>Coverage</th>
                    <th>Evidence</th>
                    {writable && <th aria-label="actions" />}
                  </tr>
                </thead>
                <tbody>
                  {std.requirements.length ? (
                    std.requirements.map((r) => (
                      <RequirementRow key={r.id} req={r} writable={writable} />
                    ))
                  ) : (
                    <tr className="empty-row">
                      <td colSpan={writable ? 6 : 5}>
                        <div className="empty-state-sm">No requirements yet.</div>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            {writable && (
              <form className="std-new" onSubmit={addReq}>
                <input
                  className="input"
                  placeholder="Clause (e.g. 8.4)"
                  value={clause}
                  onChange={(e) => setClause(e.target.value)}
                  aria-label="Clause"
                />
                <input
                  className="input"
                  placeholder="Requirement title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  aria-label="Requirement title"
                />
                <button type="submit" className="btn btn-primary btn-sm" disabled={add.isPending}>
                  Add requirement
                </button>
              </form>
            )}
          </div>
        </>
      )}
    </DetailState>
  );
}
