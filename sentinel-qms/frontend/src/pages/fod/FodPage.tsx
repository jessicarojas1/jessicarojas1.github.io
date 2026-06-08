import { useState } from 'react';
import { Link } from 'react-router-dom';
import { FilePlus2, Trash2 } from 'lucide-react';
import {
  useCreateFodEvent,
  useCreateFodZone,
  useDeleteFodEvent,
  useDeleteFodZone,
  useFodEvents,
  useFodZones,
  useRaiseNcrForFodEvent,
  useUpdateFodEvent,
  useUpdateFodZone,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import type { FodEvent, FodRisk, FodSeverity, FodStatus, FodZone } from '@/types';

const RISKS: FodRisk[] = ['low', 'medium', 'high'];
const SEVERITIES: FodSeverity[] = ['low', 'medium', 'high', 'critical'];
const STATUSES: FodStatus[] = ['open', 'investigating', 'contained', 'closed'];
const label = (s: string) => s.replace(/_/g, ' ');

function ZonesSection({ writable }: { writable: boolean }) {
  const { data, isLoading } = useFodZones();
  const create = useCreateFodZone();
  const update = useUpdateFodZone();
  const remove = useDeleteFodZone();
  const { notify } = useToast();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [risk, setRisk] = useState<FodRisk>('medium');

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!code.trim() || !name.trim()) return;
    create.mutate(
      { code: code.trim(), name: name.trim(), risk_level: risk },
      {
        onSuccess: () => {
          setCode('');
          setName('');
          notify('FOD zone added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (z: FodZone, payload: Partial<FodZone>) =>
    update.mutate({ id: z.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">FOD Control Zones</div>
        <div className="card__subtitle">Defined FOD-critical areas and their risk level</div>
      </div>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Risk</th>
              {writable && <th aria-label="actions" />}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={4}><span className="spinner" /> Loading…</td></tr>
            ) : data && data.length ? (
              data.map((z) => (
                <tr key={z.id}>
                  <td className="mono">{z.code}</td>
                  <td>{z.name}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={z.risk_level}
                        onChange={(e) => patch(z, { risk_level: e.target.value as FodRisk })}
                      >
                        {RISKS.map((r) => <option key={r} value={r}>{label(r)}</option>)}
                      </select>
                    ) : (
                      <span className={`cfp-risk cfp-risk--${z.risk_level}`}>{z.risk_level}</span>
                    )}
                  </td>
                  {writable && (
                    <td>
                      <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(z.id)}>
                        <Trash2 size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={4}><div className="empty-state-sm">No FOD zones defined.</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Code (e.g. FA-1)" value={code} onChange={(e) => setCode(e.target.value)} aria-label="Zone code" />
          <input className="input" placeholder="Zone name" value={name} onChange={(e) => setName(e.target.value)} aria-label="Zone name" />
          <select className="input" value={risk} onChange={(e) => setRisk(e.target.value as FodRisk)} aria-label="Risk level">
            {RISKS.map((r) => <option key={r} value={r}>{label(r)}</option>)}
          </select>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add zone</button>
        </form>
      )}
    </div>
  );
}

function EventsSection({ writable }: { writable: boolean }) {
  const { data, isLoading } = useFodEvents();
  const create = useCreateFodEvent();
  const update = useUpdateFodEvent();
  const remove = useDeleteFodEvent();
  const raiseNcr = useRaiseNcrForFodEvent();
  const { notify } = useToast();
  const [title, setTitle] = useState('');
  const [object, setObject] = useState('');
  const [severity, setSeverity] = useState<FodSeverity>('medium');

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) return;
    create.mutate(
      { title: title.trim(), object_type: object.trim() || null, severity },
      {
        onSuccess: () => {
          setTitle('');
          setObject('');
          notify('FOD event logged', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (ev: FodEvent, payload: Partial<FodEvent>) =>
    update.mutate({ id: ev.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  const onRaise = (id: number) =>
    raiseNcr.mutate(id, {
      onSuccess: (res) => notify(`Raised ${res.ncr_number}`, 'success'),
      onError: (err) => notify(getErrorMessage(err), 'danger'),
    });

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">FOD Events</div>
        <div className="card__subtitle">Detected foreign objects, investigation &amp; disposition</div>
      </div>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Event</th>
              <th>Title</th>
              <th>Object</th>
              <th>Severity</th>
              <th>Status</th>
              <th>NCR</th>
              {writable && <th aria-label="actions" />}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={7}><span className="spinner" /> Loading…</td></tr>
            ) : data && data.length ? (
              data.map((ev) => (
                <tr key={ev.id}>
                  <td className="mono">{ev.event_number}</td>
                  <td>{ev.title}</td>
                  <td>{ev.object_type ?? '—'}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={ev.severity}
                        onChange={(e) => patch(ev, { severity: e.target.value as FodSeverity })}
                      >
                        {SEVERITIES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                      </select>
                    ) : (
                      <span className={`cfp-risk cfp-risk--${ev.severity}`}>{ev.severity}</span>
                    )}
                  </td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={ev.status}
                        onChange={(e) => patch(ev, { status: e.target.value as FodStatus })}
                      >
                        {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                      </select>
                    ) : (
                      <span className={`cfp-status cfp-status--${ev.status}`}>{label(ev.status)}</span>
                    )}
                  </td>
                  <td>
                    {ev.ncr_id ? (
                      <Link to={`/nonconformances/${ev.ncr_id}`} className="link-btn">View NCR</Link>
                    ) : writable ? (
                      <button type="button" className="btn btn-sm btn-secondary" onClick={() => onRaise(ev.id)} disabled={raiseNcr.isPending}>
                        <FilePlus2 size={13} /> Raise NCR
                      </button>
                    ) : (
                      '—'
                    )}
                  </td>
                  {writable && (
                    <td>
                      <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(ev.id)}>
                        <Trash2 size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={7}><div className="empty-state-sm">No FOD events logged.</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="What was found / where" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Event title" />
          <input className="input" placeholder="Object type (e.g. lockwire)" value={object} onChange={(e) => setObject(e.target.value)} aria-label="Object type" />
          <select className="input" value={severity} onChange={(e) => setSeverity(e.target.value as FodSeverity)} aria-label="Severity">
            {SEVERITIES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Log event</button>
        </form>
      )}
    </div>
  );
}

export default function FodPage() {
  const { canEdit } = usePagePerms();
  const writable = canEdit('inspections');

  return (
    <>
      <PageHeader
        title="FOD Prevention"
        subtitle="AS9146 — Foreign Object Debris control zones and event log."
        breadcrumbs={[{ label: 'Operations' }, { label: 'FOD Prevention' }]}
      />
      <div className="stack">
        <EventsSection writable={writable} />
        <ZonesSection writable={writable} />
      </div>
    </>
  );
}
