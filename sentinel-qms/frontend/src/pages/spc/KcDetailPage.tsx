import { useState } from 'react';
import { useParams } from 'react-router-dom';
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { Trash2 } from 'lucide-react';
import {
  useAddMeasurement,
  useDeleteMeasurement,
  useKeyCharacteristic,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DetailState } from '@/components/detail';
import { CHART_COLORS, tooltipStyle } from '@/lib/charts';
import { cpkTone } from './KcListPage';

function Stat({ label, value, tone }: { label: string; value: string | number | null; tone?: string }) {
  return (
    <div className="exec-kpi" style={{ borderLeftColor: tone ? `var(--${tone})` : undefined }}>
      <div className="exec-kpi__value">{value ?? '—'}</div>
      <div className="exec-kpi__label">{label}</div>
    </div>
  );
}

export default function KcDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: kc, isLoading, error } = useKeyCharacteristic(id);
  const add = useAddMeasurement(Number(id));
  const remove = useDeleteMeasurement();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('inspections');
  const [value, setValue] = useState('');

  const addMeas = (e: React.FormEvent) => {
    e.preventDefault();
    if (value.trim() === '') return;
    add.mutate(
      { value: Number(value) },
      {
        onSuccess: () => setValue(''),
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const cpkColor = (cpk: number | null) => {
    const t = cpkTone(cpk);
    return t === 'low' ? 'success' : t === 'medium' ? 'warning' : 'danger';
  };

  return (
    <DetailState loading={isLoading} error={error ? getErrorMessage(error) : null} notFound={!isLoading && !error && !kc}>
      {kc && (
        <>
          <PageHeader
            title={<span className="mono">{kc.kc_number}</span>}
            subtitle={`${kc.part_number} · ${kc.characteristic}${kc.unit ? ` (${kc.unit})` : ''}`}
            breadcrumbs={[{ label: 'Operations' }, { label: 'Key Characteristics', to: '/key-characteristics' }, { label: kc.kc_number }]}
          />

          <div className="exec-kpi-grid">
            <Stat label="Cpk" value={kc.capability.cpk} tone={cpkColor(kc.capability.cpk)} />
            <Stat label="Cp" value={kc.capability.cp} />
            <Stat label="Mean" value={kc.capability.mean} />
            <Stat label="Std Dev" value={kc.capability.std} />
            <Stat label="Samples" value={kc.capability.count} />
          </div>

          <div className="card">
            <div className="card__header">
              <div className="card__title">Control Chart (Individuals)</div>
              <div className="card__subtitle">Spec {kc.lsl ?? '—'} / {kc.usl ?? '—'} · control limits ±3σ</div>
            </div>
            <div className="card__body">
              {kc.measurements.length === 0 ? (
                <div className="empty-state-sm">No measurements yet.</div>
              ) : (
                <ResponsiveContainer width="100%" height={280}>
                  <LineChart data={kc.measurements.map((m, i) => ({ i: i + 1, value: m.value }))} margin={{ left: 4, right: 12, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="i" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" domain={['auto', 'auto']} />
                    <Tooltip contentStyle={tooltipStyle} />
                    {kc.usl != null && <ReferenceLine y={kc.usl} stroke={CHART_COLORS.danger} strokeDasharray="4 4" label={{ value: 'USL', fontSize: 10, fill: CHART_COLORS.danger }} />}
                    {kc.lsl != null && <ReferenceLine y={kc.lsl} stroke={CHART_COLORS.danger} strokeDasharray="4 4" label={{ value: 'LSL', fontSize: 10, fill: CHART_COLORS.danger }} />}
                    {kc.capability.ucl != null && <ReferenceLine y={kc.capability.ucl} stroke={CHART_COLORS.warning} strokeDasharray="2 2" label={{ value: 'UCL', fontSize: 10, fill: CHART_COLORS.warning }} />}
                    {kc.capability.lcl != null && <ReferenceLine y={kc.capability.lcl} stroke={CHART_COLORS.warning} strokeDasharray="2 2" label={{ value: 'LCL', fontSize: 10, fill: CHART_COLORS.warning }} />}
                    {kc.capability.mean != null && <ReferenceLine y={kc.capability.mean} stroke={CHART_COLORS.accent} label={{ value: 'x̄', fontSize: 10, fill: CHART_COLORS.accent }} />}
                    <Line type="monotone" dataKey="value" stroke={CHART_COLORS.primary} strokeWidth={2} dot={{ r: 3 }} name="Value" />
                  </LineChart>
                </ResponsiveContainer>
              )}
            </div>
            {writable && (
              <form className="std-new" onSubmit={addMeas}>
                <input className="input" type="number" step="any" placeholder="Measured value" value={value} onChange={(e) => setValue(e.target.value)} aria-label="Measured value" />
                <button type="submit" className="btn btn-primary btn-sm" disabled={add.isPending}>Add measurement</button>
              </form>
            )}
          </div>

          <div className="card">
            <div className="card__header"><div className="card__title">Measurements ({kc.measurements.length})</div></div>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr><th>#</th><th>Value</th><th>Date</th><th>Operator</th>{writable && <th aria-label="actions" />}</tr>
                </thead>
                <tbody>
                  {kc.measurements.length ? (
                    kc.measurements.map((m, i) => (
                      <tr key={m.id}>
                        <td>{i + 1}</td>
                        <td className="mono">{m.value}</td>
                        <td>{formatDate(m.measured_at)}</td>
                        <td>{m.operator ?? '—'}</td>
                        {writable && (
                          <td>
                            <button type="button" className="btn btn-icon btn-ghost" aria-label="Delete" onClick={() => remove.mutate(m.id)}>
                              <Trash2 size={15} />
                            </button>
                          </td>
                        )}
                      </tr>
                    ))
                  ) : (
                    <tr className="empty-row"><td colSpan={writable ? 5 : 4}><div className="empty-state-sm">No measurements.</div></td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
