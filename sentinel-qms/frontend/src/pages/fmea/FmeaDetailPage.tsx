import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import { useAddFmeaItem, useDeleteFmeaItem, useFmea } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { DetailState } from '@/components/detail';
import { rpnTone } from './FmeaListPage';

const RATINGS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

function AddItemForm({ fmeaId }: { fmeaId: number }) {
  const add = useAddFmeaItem(fmeaId);
  const { notify } = useToast();
  const [fn, setFn] = useState('');
  const [mode, setMode] = useState('');
  const [effect, setEffect] = useState('');
  const [sev, setSev] = useState(5);
  const [occ, setOcc] = useState(5);
  const [det, setDet] = useState(5);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!fn.trim() || !mode.trim()) return;
    add.mutate(
      {
        function: fn.trim(),
        failure_mode: mode.trim(),
        effect: effect.trim() || null,
        severity: sev,
        occurrence: occ,
        detection: det,
      },
      {
        onSuccess: () => {
          setFn('');
          setMode('');
          setEffect('');
          notify('Failure mode added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const ratingSelect = (
    value: number,
    onChange: (n: number) => void,
    aria: string,
  ) => (
    <select className="input input-sm" value={value} onChange={(e) => onChange(Number(e.target.value))} aria-label={aria} style={{ maxWidth: 64 }}>
      {RATINGS.map((n) => <option key={n} value={n}>{n}</option>)}
    </select>
  );

  return (
    <form className="std-new" onSubmit={submit}>
      <input className="input" placeholder="Function / requirement" value={fn} onChange={(e) => setFn(e.target.value)} aria-label="Function" />
      <input className="input" placeholder="Failure mode" value={mode} onChange={(e) => setMode(e.target.value)} aria-label="Failure mode" />
      <input className="input" placeholder="Effect" value={effect} onChange={(e) => setEffect(e.target.value)} aria-label="Effect" />
      <label className="row" style={{ gap: 4 }}>S {ratingSelect(sev, setSev, 'Severity')}</label>
      <label className="row" style={{ gap: 4 }}>O {ratingSelect(occ, setOcc, 'Occurrence')}</label>
      <label className="row" style={{ gap: 4 }}>D {ratingSelect(det, setDet, 'Detection')}</label>
      <button type="submit" className="btn btn-primary btn-sm" disabled={add.isPending}>Add mode</button>
    </form>
  );
}

export default function FmeaDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: fmea, isLoading, error } = useFmea(id);
  const remove = useDeleteFmeaItem();
  const { canEdit } = usePagePerms();
  const writable = canEdit('fmea');

  return (
    <DetailState loading={isLoading} error={error ? getErrorMessage(error) : null} notFound={!isLoading && !error && !fmea}>
      {fmea && (
        <>
          <PageHeader
            title={<span className="mono">{fmea.fmea_number}</span>}
            subtitle={`${fmea.fmea_type === 'process' ? 'PFMEA' : 'DFMEA'} · ${fmea.title}${fmea.part_number ? ` · ${fmea.part_number}` : ''}`}
            breadcrumbs={[{ label: 'Control' }, { label: 'FMEA', to: '/fmea' }, { label: fmea.fmea_number }]}
          />

          <div className="exec-kpi-grid">
            <div className="exec-kpi"><div className="exec-kpi__value">{fmea.item_count}</div><div className="exec-kpi__label">Failure modes</div></div>
            <div className="exec-kpi" style={{ borderLeftColor: fmea.max_rpn ? `var(--${rpnTone(fmea.max_rpn) === 'high' ? 'danger' : rpnTone(fmea.max_rpn) === 'medium' ? 'warning' : 'success'})` : undefined }}>
              <div className="exec-kpi__value">{fmea.max_rpn || '—'}</div><div className="exec-kpi__label">Highest RPN</div>
            </div>
          </div>

          <div className="card">
            <div className="card__header"><div className="card__title">Failure modes (S × O × D = RPN)</div></div>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Function</th><th>Failure mode</th><th>Effect</th>
                    <th>S</th><th>O</th><th>D</th><th>RPN</th><th>Priority</th>
                    {writable && <th aria-label="actions" />}
                  </tr>
                </thead>
                <tbody>
                  {fmea.items && fmea.items.length ? (
                    fmea.items.map((it) => (
                      <tr key={it.id}>
                        <td>{it.function}</td>
                        <td>{it.failure_mode}</td>
                        <td>{it.effect ?? '—'}</td>
                        <td className="mono">{it.severity}</td>
                        <td className="mono">{it.occurrence}</td>
                        <td className="mono">{it.detection}</td>
                        <td><span className={`cfp-risk cfp-risk--${rpnTone(it.rpn)}`}>{it.rpn}</span></td>
                        <td style={{ textTransform: 'capitalize' }}>{it.action_priority}</td>
                        {writable && (
                          <td>
                            <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete failure mode" onClick={() => remove.mutate(it.id)}>
                              <Trash2 size={15} />
                            </button>
                          </td>
                        )}
                      </tr>
                    ))
                  ) : (
                    <tr className="empty-row"><td colSpan={writable ? 9 : 8}><div className="empty-state-sm">No failure modes yet.</div></td></tr>
                  )}
                </tbody>
              </table>
            </div>
            {writable && <AddItemForm fmeaId={fmea.id} />}
          </div>
        </>
      )}
    </DetailState>
  );
}
