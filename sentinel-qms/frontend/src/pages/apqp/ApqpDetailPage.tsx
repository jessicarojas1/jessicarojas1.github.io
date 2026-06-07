import { useParams } from 'react-router-dom';
import { useApqpProject, useUpdateApqp, useUpdatePpapElement } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataList, DetailState } from '@/components/detail';
import type { ApqpPhase, ApqpStatus, PpapElement, PpapElementStatus } from '@/types';
import { PpapBar } from './ApqpListPage';
import { PHASE_LABELS } from './constants';

const PHASES: ApqpPhase[] = [
  'planning',
  'product_design',
  'process_design',
  'validation',
  'production',
];
const PROJECT_STATUSES: ApqpStatus[] = ['active', 'on_hold', 'complete', 'cancelled'];
const ELEMENT_STATUSES: PpapElementStatus[] = [
  'not_started',
  'in_progress',
  'submitted',
  'approved',
  'rejected',
  'not_applicable',
];

const label = (s: string) => s.replace(/_/g, ' ');

function ElementRow({ el, writable }: { el: PpapElement; writable: boolean }) {
  const update = useUpdatePpapElement();
  const { notify } = useToast();
  const patch = (payload: Partial<PpapElement>) =>
    update.mutate({ id: el.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <tr>
      <td>{el.name}</td>
      <td>
        {writable ? (
          <select
            className="input input-sm"
            value={el.status}
            onChange={(e) => patch({ status: e.target.value as PpapElementStatus })}
          >
            {ELEMENT_STATUSES.map((s) => (
              <option key={s} value={s}>{label(s)}</option>
            ))}
          </select>
        ) : (
          <span className={`ppap-status ppap-status--${el.status}`}>{label(el.status)}</span>
        )}
      </td>
      <td>
        {writable ? (
          <input
            className="input input-sm"
            defaultValue={el.notes ?? ''}
            placeholder="notes"
            onBlur={(e) => {
              const v = e.target.value.trim() || null;
              if (v !== (el.notes ?? null)) patch({ notes: v });
            }}
          />
        ) : (
          el.notes ?? '—'
        )}
      </td>
    </tr>
  );
}

export default function ApqpDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: p, isLoading, error } = useApqpProject(id);
  const update = useUpdateApqp();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('inspections');

  const patchProject = (payload: Record<string, unknown>) => {
    if (!id) return;
    update.mutate(
      { id: Number(id), payload },
      { onError: (err) => notify(getErrorMessage(err), 'danger') },
    );
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !p}
    >
      {p && (
        <>
          <PageHeader
            title={<span className="mono">{p.project_number}</span>}
            subtitle={`${p.part_number} — ${p.part_name}`}
            breadcrumbs={[
              { label: 'Operations' },
              { label: 'APQP / PPAP', to: '/apqp' },
              { label: p.project_number },
            ]}
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">PPAP Submission Package</div>
                  <div className="card__subtitle">Level {p.submission_level} · {p.ppap.applicable} applicable elements</div>
                </div>
                <div className="card__body">
                  <PpapBar ppap={p.ppap} />
                </div>
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr><th>Element</th><th>Status</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                      {p.elements.map((el) => (
                        <ElementRow key={el.id} el={el} writable={writable} />
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Project</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Phase', value: PHASE_LABELS[p.current_phase] },
                      { label: 'Customer', value: p.customer ?? '—' },
                      { label: 'Target date', value: formatDate(p.target_date) },
                    ]}
                  />
                  {writable && (
                    <div className="stack" style={{ marginTop: 12, gap: 8 }}>
                      <label className="field-label" htmlFor="apqp-phase">Advance phase</label>
                      <select
                        id="apqp-phase"
                        className="input"
                        value={p.current_phase}
                        onChange={(e) => patchProject({ current_phase: e.target.value as ApqpPhase })}
                      >
                        {PHASES.map((ph) => (
                          <option key={ph} value={ph}>{PHASE_LABELS[ph]}</option>
                        ))}
                      </select>
                      <label className="field-label" htmlFor="apqp-status">Status</label>
                      <select
                        id="apqp-status"
                        className="input"
                        value={p.status}
                        onChange={(e) => patchProject({ status: e.target.value as ApqpStatus })}
                      >
                        {PROJECT_STATUSES.map((s) => (
                          <option key={s} value={s}>{label(s)}</option>
                        ))}
                      </select>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
