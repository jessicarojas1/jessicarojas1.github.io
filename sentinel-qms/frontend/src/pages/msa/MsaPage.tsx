import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import { useCreateMsa, useDeleteMsa, useMsaStudies, useUpdateMsa } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import type { MsaResult, MsaStudy, MsaType } from '@/types';

const TYPES: MsaType[] = ['gage_rr', 'bias', 'linearity', 'stability'];
const RESULT_TONE: Record<MsaResult, string> = {
  acceptable: 'low',
  marginal: 'medium',
  unacceptable: 'high',
  pending: 'medium',
};
const label = (s: string) => s.replace(/_/g, ' ');

export default function MsaPage() {
  const { data, isLoading, error } = useMsaStudies();
  const create = useCreateMsa();
  const update = useUpdateMsa();
  const remove = useDeleteMsa();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('calibration');

  const [characteristic, setCharacteristic] = useState('');
  const [type, setType] = useState<MsaType>('gage_rr');
  const [grr, setGrr] = useState('');

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!characteristic.trim()) return;
    create.mutate(
      {
        characteristic: characteristic.trim(),
        study_type: type,
        grr_percent: grr.trim() === '' ? null : Number(grr),
      },
      {
        onSuccess: () => {
          setCharacteristic('');
          setGrr('');
          notify('MSA study added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const patch = (s: MsaStudy, payload: Partial<MsaStudy>) =>
    update.mutate({ id: s.id, payload }, { onError: (err) => notify(getErrorMessage(err), 'danger') });

  return (
    <>
      <PageHeader
        title="MSA / Gage R&R"
        subtitle="Measurement Systems Analysis — %GR&R and NDC with AIAG acceptability."
        breadcrumbs={[{ label: 'Operations' }, { label: 'MSA / Gage R&R' }]}
      />
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Characteristic" value={characteristic} onChange={(e) => setCharacteristic(e.target.value)} aria-label="Characteristic" />
          <select className="input" value={type} onChange={(e) => setType(e.target.value as MsaType)} aria-label="Study type">
            {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
          </select>
          <input className="input" type="number" step="0.1" placeholder="%GR&R" value={grr} onChange={(e) => setGrr(e.target.value)} aria-label="GRR percent" style={{ maxWidth: 120 }} />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add study</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : (
        <div className="card">
          <div className="card__header"><div className="card__title">MSA Studies</div></div>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>Study</th><th>Characteristic</th><th>Type</th><th>%GR&R</th><th>NDC</th><th>Result</th><th>Date</th>{writable && <th aria-label="actions" />}</tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr><td colSpan={8}><span className="spinner" /> Loading…</td></tr>
                ) : data && data.length ? (
                  data.map((s) => (
                    <tr key={s.id}>
                      <td className="mono">{s.study_number}</td>
                      <td>{s.characteristic}</td>
                      <td>{label(s.study_type)}</td>
                      <td>
                        {writable ? (
                          <input
                            className="input input-sm"
                            type="number"
                            step="0.1"
                            defaultValue={s.grr_percent ?? ''}
                            style={{ maxWidth: 90 }}
                            onBlur={(e) => {
                              const v = e.target.value.trim() === '' ? null : Number(e.target.value);
                              if (v !== (s.grr_percent ?? null)) patch(s, { grr_percent: v });
                            }}
                            aria-label="GRR percent"
                          />
                        ) : (
                          s.grr_percent != null ? `${s.grr_percent}%` : '—'
                        )}
                      </td>
                      <td>{s.ndc ?? '—'}</td>
                      <td><span className={`cfp-risk cfp-risk--${RESULT_TONE[s.result]}`}>{label(s.result)}</span></td>
                      <td>{formatDate(s.study_date)}</td>
                      {writable && (
                        <td>
                          <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(s.id)}>
                            <Trash2 size={15} />
                          </button>
                        </td>
                      )}
                    </tr>
                  ))
                ) : (
                  <tr className="empty-row"><td colSpan={8}><div className="empty-state-sm">No MSA studies.</div></td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
