import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { CalendarClock, Lightbulb, ShieldCheck, ShieldX, Smile, Target, Trash } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useExecutiveDashboard } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { CHART_COLORS, tooltipStyle } from '@/lib/charts';
import { getErrorMessage } from '@/lib/api';
import type { CalendarItem, ClauseHeat, ExecKpi } from '@/types';

function covTone(pct: number): string {
  if (pct >= 90) return 'good';
  if (pct >= 60) return 'warn';
  return 'bad';
}

function fmtKpi(k: ExecKpi): string {
  const v = Number.isInteger(k.value) ? String(k.value) : k.value.toFixed(1);
  return k.unit ? `${v}${k.unit}` : v;
}

/** Compact USD formatting (e.g. $12.5k) for Cost of Quality. */
function fmtMoney(n: number): string {
  return n.toLocaleString('en-US', {
    style: 'currency',
    currency: 'USD',
    notation: 'compact',
    maximumFractionDigits: 1,
  });
}

function ExecKpiTile({ k }: { k: ExecKpi }) {
  const arrow = k.direction === 'higher_better' ? '≥' : '≤';
  return (
    <div className={`exec-kpi exec-kpi--${k.status}`}>
      <div className="exec-kpi__value">{fmtKpi(k)}</div>
      <div className="exec-kpi__label">{k.label}</div>
      {k.target != null && (
        <div className="exec-kpi__target">
          Target {arrow} {k.target}
          {k.unit}
        </div>
      )}
    </div>
  );
}

/** Tint intensity bucket for a finding count (0 = none … 4 = severe). */
function heatLevel(count: number): number {
  if (count <= 0) return 0;
  if (count <= 2) return 1;
  if (count <= 5) return 2;
  if (count <= 10) return 3;
  return 4;
}

function HeatCell({ count, sev }: { count: number; sev: string }) {
  return (
    <td className={`heat-cell heat-${sev} heat-l${heatLevel(count)}`}>{count || ''}</td>
  );
}

function ClauseHeatmap({ rows }: { rows: ClauseHeat[] }) {
  if (rows.length === 0) {
    return <EmptyState title="No open findings" description="No open audit findings to map by clause." />;
  }
  return (
    <div className="table-wrap">
      <table className="heat-table">
        <thead>
          <tr>
            <th>Clause</th>
            <th>Major</th>
            <th>Minor</th>
            <th>Obs.</th>
            <th>OFI</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.clause}>
              <th scope="row">
                <span className="heat-clause__num">{r.clause}</span> {r.title}
              </th>
              <HeatCell count={r.major} sev="major" />
              <HeatCell count={r.minor} sev="minor" />
              <HeatCell count={r.observation} sev="obs" />
              <HeatCell count={r.ofi} sev="ofi" />
              <td className="heat-total">{r.total}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ComplianceCalendar({ items }: { items: CalendarItem[] }) {
  if (items.length === 0) {
    return <EmptyState title="Nothing due" description="No certifications, calibrations, audits or CAPAs due in the next 90 days." />;
  }
  return (
    <div className="cal-list">
      {items.map((it, i) => (
        <div key={`${it.type}-${i}`} className="cal-row">
          <span className={`cal-badge cal-${it.status}`}>
            {it.status === 'overdue'
              ? `${Math.abs(it.days_remaining)}d overdue`
              : it.days_remaining === 0
                ? 'Today'
                : `${it.days_remaining}d`}
          </span>
          <span className="cal-type">{it.type}</span>
          <span className="cal-label">{it.label}</span>
          <span className="cal-date">{it.date}</span>
        </div>
      ))}
    </div>
  );
}

export default function ExecutiveDashboardPage() {
  const { data, isLoading, error } = useExecutiveDashboard();

  return (
    <>
      <PageHeader
        title="Executive Dashboard"
        subtitle="Quality posture, Cost of Quality, AS9100 findings, and what's coming due."
        breadcrumbs={[{ label: 'Executive' }]}
      />

      {error ? (
        <div className="card">
          <div className="card__body">
            <EmptyState title="Unable to load dashboard" description={getErrorMessage(error)} />
          </div>
        </div>
      ) : isLoading || !data ? (
        <div className="card">
          <div className="card__body">
            <span className="spinner" /> Loading…
          </div>
        </div>
      ) : (
        <>
          <div className="exec-kpi-grid">
            {data.kpis.map((k) => (
              <ExecKpiTile key={k.key} k={k} />
            ))}
          </div>

          <div className="chart-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Cost of Quality</div>
                <div className="card__subtitle">
                  This month: {fmtMoney(data.coq_current.total_cost)} · unit costs configurable in
                  Settings
                </div>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={data.coq_trend} margin={{ left: 6, right: 8, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis
                      tick={{ fontSize: 11 }}
                      stroke="var(--text-faint)"
                      tickFormatter={(v) => fmtMoney(Number(v))}
                      width={64}
                    />
                    <Tooltip contentStyle={tooltipStyle} formatter={(v) => fmtMoney(Number(v))} />
                    <Legend wrapperStyle={{ fontSize: 11 }} />
                    <Bar dataKey="prevention_cost" stackId="coq" name="Prevention" fill={CHART_COLORS.success} />
                    <Bar dataKey="appraisal_cost" stackId="coq" name="Appraisal" fill={CHART_COLORS.info} />
                    <Bar dataKey="internal_failure_cost" stackId="coq" name="Internal failure" fill={CHART_COLORS.warning} />
                    <Bar dataKey="external_failure_cost" stackId="coq" name="External failure" fill={CHART_COLORS.danger} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">AS9100 Findings by Clause</div>
                <div className="card__subtitle">Open findings mapped to clauses 4–10</div>
              </div>
              <div className="card__body">
                <ClauseHeatmap rows={data.clause_heatmap} />
              </div>
            </div>
          </div>

          <div className="chart-grid">
            <Link to="/counterfeit" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <ShieldX size={16} /> Counterfeit Prevention
                </div>
                <div className="card__subtitle">Suspect parts &amp; open GIDEP/ERAI alerts</div>
              </div>
              <div className="card__body">
                <div className="exec-stat-row">
                  <div className="exec-stat">
                    <div className={`exec-stat__value ${data.counterfeit.suspect_parts ? 'is-bad' : ''}`}>
                      {data.counterfeit.suspect_parts}
                    </div>
                    <div className="exec-stat__label">Suspect parts</div>
                  </div>
                  <div className="exec-stat">
                    <div className={`exec-stat__value ${data.counterfeit.open_alerts ? 'is-warn' : ''}`}>
                      {data.counterfeit.open_alerts}
                    </div>
                    <div className="exec-stat__label">Open alerts</div>
                  </div>
                </div>
              </div>
            </Link>

            <Link to="/standards" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <ShieldCheck size={16} /> Standards Coverage
                </div>
                <div className="card__subtitle">Audit readiness per framework</div>
              </div>
              <div className="card__body">
                {data.standards_coverage.length === 0 ? (
                  <div className="empty-state-sm">No standards configured.</div>
                ) : (
                  data.standards_coverage.map((s) => (
                    <div key={s.code} className="std-cov-row">
                      <span className="std-cov-row__code">{s.code}</span>
                      <span className="cov-bar">
                        <span
                          className={`cov-bar__fill cov-${covTone(s.coverage_pct)}`}
                          style={{ width: `${s.coverage_pct}%` }}
                        />
                      </span>
                      <span className="std-cov-row__pct">{s.coverage_pct}%</span>
                    </div>
                  ))
                )}
              </div>
            </Link>

            <Link to="/fod" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <Trash size={16} /> FOD Events
                </div>
                <div className="card__subtitle">Open events &amp; 6-month trend</div>
              </div>
              <div className="card__body">
                <div className="exec-stat" style={{ marginBottom: 8 }}>
                  <div className={`exec-stat__value ${data.fod.open_events ? 'is-warn' : ''}`}>
                    {data.fod.open_events}
                  </div>
                  <div className="exec-stat__label">Open events</div>
                </div>
                <ResponsiveContainer width="100%" height={70}>
                  <BarChart data={data.fod.trend} margin={{ top: 4, right: 4, left: 4, bottom: 0 }}>
                    <XAxis dataKey="month" hide />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Bar dataKey="count" name="FOD events" fill={CHART_COLORS.warning} radius={[2, 2, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Link>
          </div>

          <div className="chart-grid">
            <Link to="/quality-objectives" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <Target size={16} /> Quality Objectives
                </div>
                <div className="card__subtitle">KPI attainment vs target</div>
              </div>
              <div className="card__body">
                <div className="exec-stat-row">
                  <div className="exec-stat">
                    <div className="exec-stat__value">
                      {data.quality_objectives.avg_attainment != null
                        ? `${data.quality_objectives.avg_attainment}%`
                        : '—'}
                    </div>
                    <div className="exec-stat__label">Avg attainment</div>
                  </div>
                  <div className="exec-stat">
                    <div className="exec-stat__value">
                      {data.quality_objectives.met}/{data.quality_objectives.measured}
                    </div>
                    <div className="exec-stat__label">Meeting target</div>
                  </div>
                </div>
              </div>
            </Link>

            <Link to="/customer-satisfaction" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <Smile size={16} /> Customer Satisfaction
                </div>
                <div className="card__subtitle">Average survey score</div>
              </div>
              <div className="card__body">
                <div className="exec-stat-row">
                  <div className="exec-stat">
                    <div className="exec-stat__value">
                      {data.customer_satisfaction.average_overall != null
                        ? `${data.customer_satisfaction.average_overall}%`
                        : '—'}
                    </div>
                    <div className="exec-stat__label">Avg overall</div>
                  </div>
                  <div className="exec-stat">
                    <div className="exec-stat__value">{data.customer_satisfaction.count}</div>
                    <div className="exec-stat__label">Surveys</div>
                  </div>
                </div>
              </div>
            </Link>

            <Link to="/improvements" className="card exec-link-card">
              <div className="card__header">
                <div className="card__title">
                  <Lightbulb size={16} /> Continual Improvement
                </div>
                <div className="card__subtitle">Open items &amp; realized benefit</div>
              </div>
              <div className="card__body">
                <div className="exec-stat-row">
                  <div className="exec-stat">
                    <div className="exec-stat__value">{data.improvement.open}</div>
                    <div className="exec-stat__label">Open</div>
                  </div>
                  <div className="exec-stat">
                    <div className="exec-stat__value">
                      ${data.improvement.realized_benefit.toLocaleString()}
                    </div>
                    <div className="exec-stat__label">Realized $</div>
                  </div>
                </div>
              </div>
            </Link>
          </div>

          <div className="card">
            <div className="card__header">
              <div className="card__title">
                <CalendarClock size={16} /> Compliance Calendar
              </div>
              <div className="card__subtitle">Certifications, calibrations, audits & CAPAs — next 90 days</div>
            </div>
            <div className="card__body">
              <ComplianceCalendar items={data.compliance_calendar} />
            </div>
          </div>
        </>
      )}
    </>
  );
}
