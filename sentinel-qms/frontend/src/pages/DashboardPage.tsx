import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  AlertTriangle,
  CalendarClock,
  ClipboardCheck,
  MessageSquareWarning,
  ScrollText,
  ShieldAlert,
  Star,
  Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { SlidersHorizontal } from 'lucide-react';
import { DASHBOARD_WIDGETS, useDashboard, useDashboardWidgets, useMyOpenItems } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { KpiCard } from '@/components/KpiCard';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { CHART_COLORS, PIE_COLORS, tooltipStyle } from '@/lib/charts';
import { getErrorMessage } from '@/lib/api';

function MyOpenItemsCard() {
  const { data, isLoading } = useMyOpenItems();
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">My Open Items</div>
      </div>
      <div className="card__body">
        {isLoading ? (
          <div className="loading-block"><span className="spinner" /></div>
        ) : !data || data.length === 0 ? (
          <div className="empty-state-sm">Nothing assigned to you right now — you're all caught up.</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Type</th><th>Record</th><th>Title</th><th>Status</th><th>Due</th>
              </tr>
            </thead>
            <tbody>
              {data.map((it) => (
                <tr key={`${it.type}-${it.id}`}>
                  <td className="text-sm muted">{it.type}</td>
                  <td><Link to={it.url}>{it.number}</Link></td>
                  <td className="text-truncate" style={{ maxWidth: 320 }}>{it.title}</td>
                  <td><StatusBadge status={it.status} /></td>
                  <td className={it.overdue ? 'cell-overdue' : ''}>
                    {it.due_date ?? '—'}{it.overdue ? ' · Overdue' : ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function CustomizeMenu({
  isVisible,
  toggle,
}: {
  isVisible: (k: string) => boolean;
  toggle: (k: string) => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <div className="saved-views">
      <button
        type="button"
        className="btn btn-sm no-print"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
      >
        <SlidersHorizontal size={14} /> Customize
      </button>
      {open && (
        <div className="saved-views__menu" role="menu">
          {DASHBOARD_WIDGETS.map((w) => (
            <label key={w.key} className="saved-views__apply" style={{ cursor: 'pointer' }}>
              <input
                type="checkbox"
                className="checkbox"
                checked={isVisible(w.key)}
                onChange={() => toggle(w.key)}
              />{' '}
              {w.label}
            </label>
          ))}
        </div>
      )}
    </div>
  );
}

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, isLoading, error } = useDashboard();
  const widgets = useDashboardWidgets();

  return (
    <>
      <PageHeader
        title="Quality Dashboard"
        subtitle={`Welcome back, ${user?.full_name?.split(' ')[0] ?? 'colleague'}. Enterprise quality posture at a glance.`}
        actions={<CustomizeMenu isVisible={widgets.isVisible} toggle={widgets.toggle} />}
      />

      {isLoading ? (
        <div className="loading-block">
          <span className="spinner spinner--lg" />
        </div>
      ) : error ? (
        <div className="card">
          <div className="card__body">
            <EmptyState
              icon={AlertTriangle}
              title="Unable to load dashboard"
              description={getErrorMessage(error)}
            />
          </div>
        </div>
      ) : data ? (
        <div className="stack">
          {widgets.isVisible('kpis') && (
          <div className="kpi-grid">
            <KpiCard icon={ShieldAlert} value={data.kpis.open_ncrs} label="Open Nonconformances" tone="danger" />
            <KpiCard icon={ClipboardCheck} value={data.kpis.open_capas} label="Open CAPAs" tone="primary" />
            <KpiCard
              icon={CalendarClock}
              value={data.kpis.overdue_capas}
              label="Overdue CAPAs"
              tone="warning"
            />
            <KpiCard
              icon={Wrench}
              value={data.kpis.calibration_overdue}
              label="Calibration Overdue"
              tone="danger"
            />
            <KpiCard icon={ScrollText} value={data.kpis.open_audits} label="Open Audits" tone="primary" />
            <KpiCard
              icon={Star}
              value={data.kpis.supplier_avg_rating.toFixed(1)}
              label="Avg Supplier Rating"
              tone="success"
            />
            <KpiCard
              icon={MessageSquareWarning}
              value={data.kpis.open_complaints}
              label="Open Complaints"
              tone="warning"
            />
            <KpiCard
              icon={CalendarClock}
              value={data.kpis.calibration_due}
              label="Calibration Due (30d)"
              tone="primary"
            />
          </div>
          )}

          {widgets.isVisible('my_open_items') && <MyOpenItemsCard />}

          <div className="chart-grid">
            {widgets.isVisible('ncr_trend') && (
            <div className="card">
              <div className="card__header">
                <div className="card__title">Open NCR Trend</div>
                <span className="badge badge--neutral badge--no-dot">12-month</span>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={260}>
                  <AreaChart data={data.ncr_trend} margin={{ left: -18, right: 8, top: 8 }}>
                    <defs>
                      <linearGradient id="ncrFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={CHART_COLORS.danger} stopOpacity={0.35} />
                        <stop offset="100%" stopColor={CHART_COLORS.danger} stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="period" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Area
                      type="monotone"
                      dataKey="value"
                      name="Open NCRs"
                      stroke={CHART_COLORS.danger}
                      strokeWidth={2}
                      fill="url(#ncrFill)"
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </div>
            )}

            {widgets.isVisible('capa_aging') && (
            <div className="card">
              <div className="card__header">
                <div className="card__title">CAPA Aging</div>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={data.capa_aging} margin={{ left: -18, right: 8, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="bucket" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                    <Bar dataKey="count" name="CAPAs" radius={[4, 4, 0, 0]}>
                      {data.capa_aging.map((entry, i) => (
                        <Cell
                          key={entry.bucket}
                          fill={i >= data.capa_aging.length - 2 ? CHART_COLORS.danger : CHART_COLORS.primary}
                        />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
            )}

            {widgets.isVisible('calibration_status') && (
            <div className="card">
              <div className="card__header">
                <div className="card__title">Calibration Status</div>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={260}>
                  <PieChart>
                    <Pie
                      data={data.calibration_status}
                      dataKey="value"
                      nameKey="name"
                      innerRadius={55}
                      outerRadius={90}
                      paddingAngle={2}
                    >
                      {data.calibration_status.map((entry, i) => (
                        <Cell key={entry.name} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip contentStyle={tooltipStyle} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
            </div>
            )}

            {widgets.isVisible('findings_by_clause') && (
            <div className="card">
              <div className="card__header">
                <div className="card__title">Audit Findings by Clause</div>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart
                    data={data.findings_by_clause}
                    layout="vertical"
                    margin={{ left: 8, right: 16, top: 8 }}
                  >
                    <CartesianGrid stroke={CHART_COLORS.grid} horizontal={false} />
                    <XAxis type="number" tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <YAxis
                      type="category"
                      dataKey="clause"
                      tick={{ fontSize: 11 }}
                      stroke="var(--text-faint)"
                      width={64}
                    />
                    <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                    <Bar dataKey="count" name="Findings" fill={CHART_COLORS.warning} radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
            )}
          </div>

          {widgets.isVisible('supplier_performance') && (
          <div className="card">
            <div className="card__header">
              <div className="card__title">Supplier Performance</div>
            </div>
            <div className="card__body">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={data.supplier_performance} margin={{ left: -10, right: 8, top: 8 }}>
                  <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                  <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="rating" name="Rating" fill={CHART_COLORS.primary} radius={[4, 4, 0, 0]} />
                  <Bar dataKey="otd" name="On-Time %" fill={CHART_COLORS.success} radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>
          )}
        </div>
      ) : null}
    </>
  );
}
