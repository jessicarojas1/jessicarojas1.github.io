import { useState } from 'react';
import { Link } from 'react-router-dom';
import { FilePlus2, Trash2, Upload } from 'lucide-react';
import {
  useCounterfeitAlerts,
  useCreateAlert,
  useCreateSourcing,
  useDeleteAlert,
  useDeleteSourcing,
  useRaiseNcrForAlert,
  useRaiseNcrForSourcing,
  useSourcingRecords,
  useUpdateAlert,
  useUpdateSourcing,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar } from '@/components/FilterBar';
import { ImportModal } from '@/components/ImportModal';
import type {
  AlertSource,
  AlertStatus,
  CounterfeitAlert,
  RiskLevel,
  SourceType,
  SourcingRecord,
  VerificationStatus,
} from '@/types';

const SOURCE_TYPES: SourceType[] = ['ocm', 'franchised', 'independent', 'broker', 'other'];
const RISK_LEVELS: RiskLevel[] = ['low', 'medium', 'high', 'critical'];
const VERIFY_STATUSES: VerificationStatus[] = ['pending', 'verified', 'suspect', 'rejected'];
const ALERT_SOURCES: AlertSource[] = ['gidep', 'erai', 'internal', 'customer', 'supplier', 'other'];
const ALERT_STATUSES: AlertStatus[] = ['open', 'under_assessment', 'closed'];

const label = (s: string) => s.replace(/_/g, ' ');

function NcrCell({
  ncrId,
  writable,
  pending,
  onRaise,
}: {
  ncrId: number | null;
  writable: boolean;
  pending: boolean;
  onRaise: () => void;
}) {
  if (ncrId) {
    return (
      <Link to={`/nonconformances/${ncrId}`} className="link-btn">
        View NCR
      </Link>
    );
  }
  if (!writable) return <>—</>;
  return (
    <button type="button" className="btn btn-sm btn-secondary" onClick={onRaise} disabled={pending}>
      <FilePlus2 size={13} /> Raise NCR
    </button>
  );
}

function SourcingSection({ writable }: { writable: boolean }) {
  const { data, isLoading } = useSourcingRecords();
  const create = useCreateSourcing();
  const update = useUpdateSourcing();
  const remove = useDeleteSourcing();
  const raiseNcr = useRaiseNcrForSourcing();
  const { notify } = useToast();
  const [part, setPart] = useState('');
  const [source, setSource] = useState<SourceType>('ocm');
  const [risk, setRisk] = useState<RiskLevel>('medium');
  const [fSource, setFSource] = useState('');
  const [fRisk, setFRisk] = useState('');
  const [fStatus, setFStatus] = useState('');

  const records = data ?? [];
  const filtered = records.filter(
    (r) =>
      (!fSource || r.source_type === fSource) &&
      (!fRisk || r.risk_level === fRisk) &&
      (!fStatus || r.status === fStatus),
  );
  const activeFilters = (fSource ? 1 : 0) + (fRisk ? 1 : 0) + (fStatus ? 1 : 0);

  const onRaise = (id: number) =>
    raiseNcr.mutate(id, {
      onSuccess: (res) => notify(`Raised ${res.ncr_number}`, 'success'),
      onError: (err) => notify(getErrorMessage(err), 'danger'),
    });

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!part.trim()) return;
    create.mutate(
      { part_number: part.trim(), source_type: source, risk_level: risk },
      {
        onSuccess: () => {
          setPart('');
          notify('Sourcing record added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (rec: SourcingRecord, payload: Partial<SourcingRecord>) =>
    update.mutate({ id: rec.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">Part Sourcing Verification</div>
        <div className="card__subtitle">Provenance, certificate of conformance & OEM traceability</div>
      </div>
      <FilterBar active={activeFilters}>
        <select className="input field" value={fSource} onChange={(e) => setFSource(e.target.value)} aria-label="Filter by source type">
          <option value="">All sources</option>
          {SOURCE_TYPES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select className="input field" value={fRisk} onChange={(e) => setFRisk(e.target.value)} aria-label="Filter by risk level">
          <option value="">All risk levels</option>
          {RISK_LEVELS.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
          <option value="">All statuses</option>
          {VERIFY_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
      </FilterBar>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Record</th>
              <th>Part #</th>
              <th>Source</th>
              <th>Risk</th>
              <th>CoC</th>
              <th>OEM trace</th>
              <th>Status</th>
              <th>NCR</th>
              {writable && <th aria-label="actions" />}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={9}><span className="spinner" /> Loading…</td></tr>
            ) : filtered.length ? (
              filtered.map((r) => (
                <tr key={r.id}>
                  <td className="mono">{r.record_number}</td>
                  <td className="mono">{r.part_number}</td>
                  <td style={{ textTransform: 'capitalize' }}>{r.source_type}</td>
                  <td><span className={`cfp-risk cfp-risk--${r.risk_level}`}>{r.risk_level}</span></td>
                  <td>{r.coc_received ? '✓' : '—'}</td>
                  <td>{r.traceability_to_oem ? '✓' : '—'}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={r.status}
                        onChange={(e) => patch(r, { status: e.target.value as VerificationStatus })}
                      >
                        {VERIFY_STATUSES.map((s) => (
                          <option key={s} value={s}>{label(s)}</option>
                        ))}
                      </select>
                    ) : (
                      <span className={`cfp-status cfp-status--${r.status}`}>{label(r.status)}</span>
                    )}
                  </td>
                  <td>
                    <NcrCell ncrId={r.ncr_id} writable={writable} pending={raiseNcr.isPending} onRaise={() => onRaise(r.id)} />
                  </td>
                  {writable && (
                    <td>
                      <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(r.id)}>
                        <Trash2 size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={9}><div className="empty-state-sm">{records.length ? 'No records match the selected filters.' : 'No sourcing records.'}</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Part number" value={part} onChange={(e) => setPart(e.target.value)} aria-label="Part number" />
          <select className="input" value={source} onChange={(e) => setSource(e.target.value as SourceType)} aria-label="Source type">
            {SOURCE_TYPES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
          <select className="input" value={risk} onChange={(e) => setRisk(e.target.value as RiskLevel)} aria-label="Risk level">
            {RISK_LEVELS.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add record</button>
        </form>
      )}
    </div>
  );
}

function AlertsSection({ writable }: { writable: boolean }) {
  const { data, isLoading } = useCounterfeitAlerts();
  const create = useCreateAlert();
  const update = useUpdateAlert();
  const remove = useDeleteAlert();
  const raiseNcr = useRaiseNcrForAlert();
  const { notify } = useToast();
  const [title, setTitle] = useState('');
  const [source, setSource] = useState<AlertSource>('gidep');
  const [ref, setRef] = useState('');
  const [importOpen, setImportOpen] = useState(false);
  const [fSource, setFSource] = useState('');
  const [fStatus, setFStatus] = useState('');

  const alerts = data ?? [];
  const filtered = alerts.filter(
    (a) => (!fSource || a.source === fSource) && (!fStatus || a.status === fStatus),
  );
  const activeFilters = (fSource ? 1 : 0) + (fStatus ? 1 : 0);

  const onRaise = (id: number) =>
    raiseNcr.mutate(id, {
      onSuccess: (res) => notify(`Raised ${res.ncr_number}`, 'success'),
      onError: (err) => notify(getErrorMessage(err), 'danger'),
    });

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) return;
    create.mutate(
      { title: title.trim(), source, external_ref: ref.trim() || null },
      {
        onSuccess: () => {
          setTitle('');
          setRef('');
          notify('Alert logged', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (a: CounterfeitAlert, payload: Partial<CounterfeitAlert>) =>
    update.mutate({ id: a.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <div className="card">
      <div className="card__header">
        <div>
          <div className="card__title">Counterfeit Alerts (GIDEP / ERAI)</div>
          <div className="card__subtitle">External & internal alerts with impact assessment</div>
        </div>
        {writable && (
          <button type="button" className="btn btn-sm" onClick={() => setImportOpen(true)}>
            <Upload size={14} /> Import CSV
          </button>
        )}
      </div>
      <ImportModal
        resource="counterfeit/alerts"
        title="Import counterfeit alerts"
        open={importOpen}
        onClose={() => setImportOpen(false)}
        listQueryKey={['counterfeit']}
      />
      <FilterBar active={activeFilters}>
        <select className="input field" value={fSource} onChange={(e) => setFSource(e.target.value)} aria-label="Filter by alert source">
          <option value="">All sources</option>
          {ALERT_SOURCES.map((s) => <option key={s} value={s}>{s.toUpperCase()}</option>)}
        </select>
        <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
          <option value="">All statuses</option>
          {ALERT_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
      </FilterBar>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Alert</th>
              <th>Source</th>
              <th>Ref</th>
              <th>Title</th>
              <th>Inv.</th>
              <th>Status</th>
              <th>NCR</th>
              {writable && <th aria-label="actions" />}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={8}><span className="spinner" /> Loading…</td></tr>
            ) : filtered.length ? (
              filtered.map((a) => (
                <tr key={a.id}>
                  <td className="mono">{a.alert_number}</td>
                  <td style={{ textTransform: 'uppercase' }}>{a.source}</td>
                  <td className="mono">{a.external_ref ?? '—'}</td>
                  <td>{a.title}</td>
                  <td>{a.affects_inventory ? '⚠' : '—'}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={a.status}
                        onChange={(e) => patch(a, { status: e.target.value as AlertStatus })}
                      >
                        {ALERT_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                      </select>
                    ) : (
                      <span className={`cfp-status cfp-status--${a.status}`}>{label(a.status)}</span>
                    )}
                  </td>
                  <td>
                    <NcrCell ncrId={a.ncr_id} writable={writable} pending={raiseNcr.isPending} onRaise={() => onRaise(a.id)} />
                  </td>
                  {writable && (
                    <td>
                      <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(a.id)}>
                        <Trash2 size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={8}><div className="empty-state-sm">{alerts.length ? 'No alerts match the selected filters.' : 'No alerts logged.'}</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Alert title" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Alert title" />
          <select className="input" value={source} onChange={(e) => setSource(e.target.value as AlertSource)} aria-label="Alert source">
            {ALERT_SOURCES.map((s) => <option key={s} value={s}>{s.toUpperCase()}</option>)}
          </select>
          <input className="input" placeholder="External ref (e.g. GIDEP #)" value={ref} onChange={(e) => setRef(e.target.value)} aria-label="External reference" />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Log alert</button>
        </form>
      )}
    </div>
  );
}

export default function CounterfeitPage() {
  const { canEdit } = usePagePerms();
  const writable = canEdit('suppliers');

  return (
    <>
      <PageHeader
        title="Counterfeit Parts Prevention"
        subtitle="AS5553 / AS6081 — source verification and GIDEP/ERAI alert management."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Counterfeit Prevention' }]}
      />
      <div className="stack">
        <SourcingSection writable={writable} />
        <AlertsSection writable={writable} />
      </div>
    </>
  );
}
