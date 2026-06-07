import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { AlertTriangle, TrendingUp } from 'lucide-react';
import { useAnalyticsTrends } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { CHART_COLORS, PIE_COLORS, tooltipStyle } from '@/lib/charts';
import { getErrorMessage } from '@/lib/api';
import { humanize } from '@/lib/format';

/** Convert a Record<string,number> into recharts-friendly rows. */
function toBars(record: Record<string, number> | undefined): { name: string; value: number }[] {
  if (!record) return [];
  return Object.entries(record).map(([k, v]) => ({ name: humanize(k), value: v }));
}

export default function AnalyticsPage() {
  const { data, isLoading, error } = useAnalyticsTrends(6);

  const openByModule = toBars(data?.open_by_module);
  const ncBySeverity = toBars(data?.nc_by_severity);
  const findingsByType = toBars(data?.audit_findings_by_type);

  return (
    <>
      <PageHeader
        title="Analytics"
        icon={<TrendingUp size={22} />}
        subtitle="Trended quality metrics across nonconformances, CAPAs, and audits."
        breadcrumbs={[{ label: 'Analytics' }]}
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
              title="Unable to load analytics"
              description={getErrorMessage(error)}
            />
          </div>
        </div>
      ) : data ? (
        <div className="stack">
          <div className="chart-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">NCRs Opened vs. Closed</div>
                <span className="badge badge--neutral badge--no-dot">By month</span>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={280}>
                  <LineChart data={data.ncr_trend} margin={{ left: -18, right: 8, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Line
                      type="monotone"
                      dataKey="opened"
                      name="Opened"
                      stroke={CHART_COLORS.danger}
                      strokeWidth={2}
                      dot={false}
                    />
                    <Line
                      type="monotone"
                      dataKey="closed"
                      name="Closed"
                      stroke={CHART_COLORS.success}
                      strokeWidth={2}
                      dot={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">CAPAs Opened vs. Closed</div>
                <span className="badge badge--neutral badge--no-dot">By month</span>
              </div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={280}>
                  <LineChart data={data.capa_trend} margin={{ left: -18, right: 8, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Line
                      type="monotone"
                      dataKey="opened"
                      name="Opened"
                      stroke={CHART_COLORS.primary}
                      strokeWidth={2}
                      dot={false}
                    />
                    <Line
                      type="monotone"
                      dataKey="closed"
                      name="Closed"
                      stroke={CHART_COLORS.success}
                      strokeWidth={2}
                      dot={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">Open Items by Module</div>
              </div>
              <div className="card__body">
                {openByModule.length === 0 ? (
                  <EmptyState title="No data" description="Nothing open right now." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={openByModule} margin={{ left: -18, right: 8, top: 8 }}>
                      <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                      <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="value" name="Open" fill={CHART_COLORS.primary} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">Nonconformances by Severity</div>
              </div>
              <div className="card__body">
                {ncBySeverity.length === 0 ? (
                  <EmptyState title="No data" description="No nonconformances recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <PieChart>
                      <Pie
                        data={ncBySeverity}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={55}
                        outerRadius={90}
                        paddingAngle={2}
                      >
                        {ncBySeverity.map((entry, i) => (
                          <Cell key={entry.name} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip contentStyle={tooltipStyle} />
                      <Legend wrapperStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">Audit Findings by Type</div>
              </div>
              <div className="card__body">
                {findingsByType.length === 0 ? (
                  <EmptyState title="No data" description="No audit findings recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart
                      data={findingsByType}
                      layout="vertical"
                      margin={{ left: 8, right: 16, top: 8 }}
                    >
                      <CartesianGrid stroke={CHART_COLORS.grid} horizontal={false} />
                      <XAxis
                        type="number"
                        tick={{ fontSize: 11 }}
                        stroke="var(--text-faint)"
                        allowDecimals={false}
                      />
                      <YAxis
                        type="category"
                        dataKey="name"
                        tick={{ fontSize: 11 }}
                        stroke="var(--text-faint)"
                        width={90}
                      />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="value" name="Findings" fill={CHART_COLORS.warning} radius={[0, 4, 4, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}
