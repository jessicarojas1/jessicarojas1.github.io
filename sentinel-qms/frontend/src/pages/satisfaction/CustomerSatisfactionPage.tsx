import { useState } from 'react';
import {
  useCreateSurvey,
  useCsatSummary,
  useCustomers,
  useCustomerSurveys,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { SurveyMethod } from '@/types';

const METHODS: SurveyMethod[] = ['survey', 'scorecard', 'portal', 'meeting'];
const label = (s: string) => s.replace(/_/g, ' ');

/** RAG tone for a 0–100 satisfaction score. */
function scoreTone(v: number | null | undefined): string {
  if (v == null) return 'medium';
  if (v >= 85) return 'low';
  if (v >= 70) return 'medium';
  return 'high';
}

export default function CustomerSatisfactionPage() {
  const { data, isLoading, error } = useCustomerSurveys();
  const summary = useCsatSummary();
  const { data: customers } = useCustomers();
  const create = useCreateSurvey();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('customer_satisfaction');

  const [customerId, setCustomerId] = useState('');
  const [period, setPeriod] = useState('');
  const [method, setMethod] = useState<SurveyMethod>('survey');
  const [quality, setQuality] = useState('');
  const [delivery, setDelivery] = useState('');
  const [communication, setCommunication] = useState('');
  const [fCustomer, setFCustomer] = useState('');

  const custList = customers ?? [];
  const rows = data ?? [];
  const filtered = rows.filter((s) => !fCustomer || s.customer_id === Number(fCustomer));

  const num = (s: string) => (s.trim() === '' ? null : Number(s));

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!customerId) return;
    create.mutate(
      {
        customer_id: Number(customerId),
        period: period.trim() || null,
        method,
        quality_score: num(quality),
        delivery_score: num(delivery),
        communication_score: num(communication),
      },
      {
        onSuccess: () => {
          setPeriod('');
          setQuality('');
          setDelivery('');
          setCommunication('');
          notify('Survey recorded', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Customer Satisfaction"
        subtitle="Periodic customer satisfaction surveys & scorecards with trend (AS9100/ISO 9001 clause 9.1.2)."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Customer Satisfaction' }]}
      />

      <div className="exec-kpi-grid">
        <div className="exec-kpi">
          <div className="exec-kpi__value">
            {summary.data?.average_overall != null ? `${summary.data.average_overall}%` : '—'}
          </div>
          <div className="exec-kpi__label">Average overall satisfaction</div>
        </div>
        <div className="exec-kpi">
          <div className="exec-kpi__value">{summary.data?.count ?? 0}</div>
          <div className="exec-kpi__label">Surveys recorded</div>
        </div>
      </div>

      {writable && (
        <form className="std-new" onSubmit={add}>
          <select className="input" value={customerId} onChange={(e) => setCustomerId(e.target.value)} aria-label="Customer">
            <option value="">Customer…</option>
            {custList.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <input className="input" placeholder="Period (e.g. Q1 2026)" value={period} onChange={(e) => setPeriod(e.target.value)} aria-label="Period" style={{ maxWidth: 140 }} />
          <select className="input" value={method} onChange={(e) => setMethod(e.target.value as SurveyMethod)} aria-label="Method">
            {METHODS.map((m) => <option key={m} value={m}>{label(m)}</option>)}
          </select>
          <input className="input" type="number" min="0" max="100" placeholder="Quality" value={quality} onChange={(e) => setQuality(e.target.value)} aria-label="Quality score" style={{ maxWidth: 90 }} />
          <input className="input" type="number" min="0" max="100" placeholder="Delivery" value={delivery} onChange={(e) => setDelivery(e.target.value)} aria-label="Delivery score" style={{ maxWidth: 90 }} />
          <input className="input" type="number" min="0" max="100" placeholder="Comms" value={communication} onChange={(e) => setCommunication(e.target.value)} aria-label="Communication score" style={{ maxWidth: 90 }} />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Record</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : rows.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No surveys yet" description="Record a customer satisfaction survey or scorecard to start trending." /></div></div>
      ) : (
        <div className="card">
          <FilterBar active={fCustomer ? 1 : 0}>
            <select className="input field" value={fCustomer} onChange={(e) => setFCustomer(e.target.value)} aria-label="Filter by customer">
              <option value="">All customers</option>
              {custList.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>ID</th><th>Customer</th><th>Period</th><th>Date</th>
                  <th>Quality</th><th>Delivery</th><th>Comms</th><th>Overall</th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row"><td colSpan={8}><div className="empty-state-sm">No surveys match the selected filter.</div></td></tr>
                ) : filtered.map((s) => (
                  <tr key={s.id}>
                    <td className="mono">{s.survey_number}</td>
                    <td>{s.customer_name ?? `#${s.customer_id}`}</td>
                    <td>{s.period ?? '—'}</td>
                    <td>{s.survey_date ? formatDate(s.survey_date) : '—'}</td>
                    <td className="mono">{s.quality_score ?? '—'}</td>
                    <td className="mono">{s.delivery_score ?? '—'}</td>
                    <td className="mono">{s.communication_score ?? '—'}</td>
                    <td>
                      {s.overall_score != null ? (
                        <span className={`cfp-risk cfp-risk--${scoreTone(s.overall_score)}`}>{s.overall_score}%</span>
                      ) : '—'}
                    </td>
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
