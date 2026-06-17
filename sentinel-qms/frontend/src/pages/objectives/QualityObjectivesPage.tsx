import { useState } from 'react';
import {
  useCreateObjective,
  useQualityObjectives,
  useRecordMeasurement,
  useUserLookup,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { ObjectiveCadence, ObjectiveDirection, ObjectiveStatus, QualityObjective } from '@/types';

const DIRECTIONS: ObjectiveDirection[] = ['higher_better', 'lower_better'];
const CADENCES: ObjectiveCadence[] = ['monthly', 'quarterly', 'annual'];
const STATUSES: ObjectiveStatus[] = ['active', 'met', 'at_risk', 'missed', 'archived'];
const label = (s: string) => s.replace(/_/g, ' ');

/** RAG tone for an attainment percentage (>=100 good, >=85 watch, else poor). */
function attainmentTone(pct: number | null | undefined): string {
  if (pct == null) return 'medium';
  if (pct >= 100) return 'low';
  if (pct >= 85) return 'medium';
  return 'high';
}

function MeasureCell({ objective }: { objective: QualityObjective }) {
  const record = useRecordMeasurement(objective.id);
  const { notify } = useToast();
  const [value, setValue] = useState('');
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (value.trim() === '') return;
    record.mutate(
      { value: Number(value) },
      {
        onSuccess: () => {
          setValue('');
          notify('Measurement recorded', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };
  return (
    <form className="row" style={{ gap: 6 }} onSubmit={submit}>
      <input
        className="input input-sm"
        type="number"
        step="any"
        placeholder="Actual"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        aria-label={`Record actual for ${objective.objective_number}`}
        style={{ maxWidth: 90 }}
      />
      <button type="submit" className="btn btn-sm" disabled={record.isPending}>
        Record
      </button>
    </form>
  );
}

export default function QualityObjectivesPage() {
  const { data, isLoading, error } = useQualityObjectives();
  const create = useCreateObjective();
  const { list: users } = useUserLookup();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('quality_objectives');

  const [title, setTitle] = useState('');
  const [target, setTarget] = useState('');
  const [unit, setUnit] = useState('');
  const [direction, setDirection] = useState<ObjectiveDirection>('higher_better');
  const [cadence, setCadence] = useState<ObjectiveCadence>('quarterly');
  const [ownerId, setOwnerId] = useState('');
  const [clause, setClause] = useState('');
  const [fStatus, setFStatus] = useState('');

  const rows = data ?? [];
  const filtered = rows.filter((o) => !fStatus || o.status === fStatus);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim() || target.trim() === '') return;
    create.mutate(
      {
        title: title.trim(),
        target_value: Number(target),
        unit: unit.trim() || null,
        direction,
        cadence,
        owner_id: ownerId === '' ? null : Number(ownerId),
        clause_ref: clause.trim() || null,
      },
      {
        onSuccess: () => {
          setTitle('');
          setTarget('');
          setUnit('');
          setClause('');
          setOwnerId('');
          notify('Quality objective added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Quality Objectives & KPIs"
        subtitle="Measurable quality objectives with targets, owners, and attainment (AS9100/ISO 9001 clause 6.2)."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Quality Objectives' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Objective (e.g. On-time delivery)" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Objective title" />
          <input className="input" type="number" step="any" placeholder="Target" value={target} onChange={(e) => setTarget(e.target.value)} aria-label="Target value" style={{ maxWidth: 100 }} />
          <input className="input" placeholder="Unit" value={unit} onChange={(e) => setUnit(e.target.value)} aria-label="Unit" style={{ maxWidth: 80 }} />
          <select className="input" value={direction} onChange={(e) => setDirection(e.target.value as ObjectiveDirection)} aria-label="Direction">
            {DIRECTIONS.map((d) => <option key={d} value={d}>{label(d)}</option>)}
          </select>
          <select className="input" value={cadence} onChange={(e) => setCadence(e.target.value as ObjectiveCadence)} aria-label="Cadence">
            {CADENCES.map((c) => <option key={c} value={c}>{label(c)}</option>)}
          </select>
          <select className="input" value={ownerId} onChange={(e) => setOwnerId(e.target.value)} aria-label="Owner">
            <option value="">Owner (unassigned)</option>
            {users.filter((u) => u.is_active).map((u) => <option key={u.id} value={u.id}>{u.full_name || u.email}</option>)}
          </select>
          <input className="input" placeholder="Clause (e.g. 6.2)" value={clause} onChange={(e) => setClause(e.target.value)} aria-label="Clause reference" style={{ maxWidth: 110 }} />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add objective</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : rows.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No quality objectives" description="Define a measurable objective with a target to start tracking attainment." /></div></div>
      ) : (
        <div className="card">
          <FilterBar active={fStatus ? 1 : 0}>
            <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
              <option value="">All statuses</option>
              {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>ID</th><th>Objective</th><th>Owner</th><th>Target</th><th>Current</th>
                  <th>Attainment</th><th>Cadence</th><th>Clause</th>{writable && <th aria-label="record" />}
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row"><td colSpan={writable ? 9 : 8}><div className="empty-state-sm">No objectives match the selected filter.</div></td></tr>
                ) : filtered.map((o) => (
                  <tr key={o.id}>
                    <td className="mono">{o.objective_number}</td>
                    <td>{o.title}</td>
                    <td>{o.owner_name ?? '—'}</td>
                    <td className="mono">{o.target_value}{o.unit ?? ''}</td>
                    <td className="mono">{o.current_value != null ? `${o.current_value}${o.unit ?? ''}` : '—'}</td>
                    <td>
                      {o.attainment_pct != null ? (
                        <span className={`cfp-risk cfp-risk--${attainmentTone(o.attainment_pct)}`}>{o.attainment_pct}%</span>
                      ) : '—'}
                    </td>
                    <td>{label(o.cadence)}</td>
                    <td className="mono">{o.clause_ref ?? '—'}</td>
                    {writable && <td><MeasureCell objective={o} /></td>}
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
