import { useMemo, useState } from 'react';
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
import { AlertTriangle, Download, FileBarChart } from 'lucide-react';
import {
  useAuditSummaryReport,
  useCapaSummaryReport,
  useNcrSummaryReport,
  useSupplierScorecardReport,
} from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { PrintButton } from '@/components/PrintButton';
import { CHART_COLORS, PIE_COLORS, tooltipStyle } from '@/lib/charts';
import { getErrorMessage } from '@/lib/api';

type ReportKey = 'ncr' | 'capa' | 'supplier' | 'audit';

const REPORTS: { key: ReportKey; label: string; description: string }[] = [
  { key: 'ncr', label: 'NCR Summary', description: 'Nonconformances by status, severity & trend' },
  { key: 'capa', label: 'CAPA Summary', description: 'Status, aging & overdue corrective actions' },
  { key: 'supplier', label: 'Supplier Scorecard', description: 'Quality, on-time delivery & open SCARs' },
  { key: 'audit', label: 'Audit Summary', description: 'Audits & findings by type and status' },
];

/* ---- CSV export (UTF-8 BOM + RFC-4180 escaping) ---- */
function escapeCsv(value: unknown): string {
  const text = value == null ? '' : String(value);
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function downloadCsv(filename: string, headers: string[], rows: (string | number | null)[][]) {
  const headerLine = headers.map(escapeCsv).join(',');
  const body = rows.map((r) => r.map(escapeCsv).join(',')).join('\r\n');
  const csv = '﻿' + [headerLine, body].filter(Boolean).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function num(value: number | null | undefined): string {
  return value == null ? '—' : String(value);
}

function KpiTile({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="report-kpi">
      <div className="report-kpi__value">{value}</div>
      <div className="report-kpi__label">{label}</div>
    </div>
  );
}

function ReportShell({
  title,
  isLoading,
  error,
  hasData,
  exportCsv,
  children,
}: {
  title: string;
  isLoading: boolean;
  error: unknown;
  hasData: boolean;
  exportCsv: () => void;
  children: React.ReactNode;
}) {
  if (isLoading) {
    return (
      <div className="loading-block">
        <span className="spinner spinner--lg" />
      </div>
    );
  }
  if (error) {
    return (
      <div className="card">
        <div className="card__body">
          <EmptyState icon={AlertTriangle} title="Unable to load report" description={getErrorMessage(error)} />
        </div>
      </div>
    );
  }
  return (
    <div className="stack report-content">
      <div className="report-toolbar no-print">
        <button type="button" className="btn btn-sm" onClick={exportCsv} disabled={!hasData} title="Export this report's table to CSV">
          <Download size={14} /> Export CSV
        </button>
        <PrintButton label="Print / PDF" />
      </div>
      <h2 className="report-print-title">{title}</h2>
      {children}
    </div>
  );
}

/* ---------------------------- NCR report ---------------------------- */
function NcrReport() {
  const { data, isLoading, error } = useNcrSummaryReport(12);
  const statusBars = (data?.by_status ?? []).map((s) => ({ name: s.label, value: s.count }));
  const sevPie = (data?.by_severity ?? []).map((s) => ({ name: s.label, value: s.count }));
  const exportCsv = () =>
    downloadCsv(
      'ncr-summary',
      ['Month', 'Opened', 'Closed'],
      (data?.by_month ?? []).map((m) => [m.month, m.opened, m.closed]),
    );

  return (
    <ReportShell title="NCR Summary" isLoading={isLoading} error={error} hasData={!!data?.by_month.length} exportCsv={exportCsv}>
      {data && (
        <>
          <div className="report-kpis">
            <KpiTile label="Total NCRs" value={data.total} />
            <KpiTile label="Open NCRs" value={data.total_open} />
            <KpiTile label="Statuses tracked" value={data.by_status.length} />
          </div>
          <div className="chart-grid">
            <div className="card">
              <div className="card__header"><div className="card__title">Opened vs. Closed by Month</div></div>
              <div className="card__body">
                <ResponsiveContainer width="100%" height={280}>
                  <LineChart data={data.by_month} margin={{ left: -18, right: 8, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Line type="monotone" dataKey="opened" name="Opened" stroke={CHART_COLORS.danger} strokeWidth={2} dot={false} />
                    <Line type="monotone" dataKey="closed" name="Closed" stroke={CHART_COLORS.success} strokeWidth={2} dot={false} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>
            <div className="card">
              <div className="card__header"><div className="card__title">By Severity</div></div>
              <div className="card__body">
                {sevPie.length === 0 ? (
                  <EmptyState title="No data" description="No nonconformances recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <PieChart>
                      <Pie data={sevPie} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2}>
                        {sevPie.map((e, i) => <Cell key={e.name} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={tooltipStyle} />
                      <Legend wrapperStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
            <div className="card">
              <div className="card__header"><div className="card__title">By Status</div></div>
              <div className="card__body">
                {statusBars.length === 0 ? (
                  <EmptyState title="No data" description="No nonconformances recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={statusBars} margin={{ left: -18, right: 8, top: 8 }}>
                      <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                      <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="value" name="Count" fill={CHART_COLORS.primary} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
          </div>
          <div className="card">
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>Month</th><th className="num">Opened</th><th className="num">Closed</th></tr></thead>
                <tbody>
                  {data.by_month.length === 0 ? (
                    <tr className="empty-row"><td colSpan={3}><EmptyState title="No data" /></td></tr>
                  ) : (
                    data.by_month.map((m) => (
                      <tr key={m.month}><td>{m.month}</td><td className="num">{m.opened}</td><td className="num">{m.closed}</td></tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </ReportShell>
  );
}

/* ---------------------------- CAPA report --------------------------- */
function CapaReport() {
  const { data, isLoading, error } = useCapaSummaryReport(12);
  const statusBars = (data?.by_status ?? []).map((s) => ({ name: s.label, value: s.count }));
  const exportCsv = () =>
    downloadCsv('capa-summary', ['Aging Bucket', 'Count'], (data?.aging ?? []).map((a) => [a.bucket, a.count]));

  return (
    <ReportShell title="CAPA Summary" isLoading={isLoading} error={error} hasData={!!data?.aging.length} exportCsv={exportCsv}>
      {data && (
        <>
          <div className="report-kpis">
            <KpiTile label="Open CAPAs" value={data.total_open} />
            <KpiTile label="Overdue" value={data.overdue} />
            <KpiTile label="Avg Days Open" value={data.avg_days_open} />
          </div>
          <div className="chart-grid">
            <div className="card">
              <div className="card__header"><div className="card__title">Open CAPA Aging</div></div>
              <div className="card__body">
                {data.aging.length === 0 ? (
                  <EmptyState title="No data" description="No open CAPAs." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={data.aging} margin={{ left: -18, right: 8, top: 8 }}>
                      <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                      <XAxis dataKey="bucket" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                      <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="count" name="CAPAs" fill={CHART_COLORS.warning} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
            <div className="card">
              <div className="card__header"><div className="card__title">By Status</div></div>
              <div className="card__body">
                {statusBars.length === 0 ? (
                  <EmptyState title="No data" description="No CAPAs recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <PieChart>
                      <Pie data={statusBars} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2}>
                        {statusBars.map((e, i) => <Cell key={e.name} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={tooltipStyle} />
                      <Legend wrapperStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
          </div>
          <div className="card">
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>Aging Bucket (days)</th><th className="num">Open CAPAs</th></tr></thead>
                <tbody>
                  {data.aging.length === 0 ? (
                    <tr className="empty-row"><td colSpan={2}><EmptyState title="No data" /></td></tr>
                  ) : (
                    data.aging.map((a) => (
                      <tr key={a.bucket}><td>{a.bucket}</td><td className="num">{a.count}</td></tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </ReportShell>
  );
}

/* ----------------------- Supplier scorecard ------------------------ */
function SupplierReport() {
  const { data, isLoading, error } = useSupplierScorecardReport();
  const suppliers = data?.suppliers ?? [];
  const qualityBars = suppliers
    .filter((s) => s.quality_score != null)
    .map((s) => ({ name: s.name, value: s.quality_score as number }))
    .slice(0, 12);
  const exportCsv = () =>
    downloadCsv(
      'supplier-scorecard',
      ['Supplier', 'Status', 'Quality Score', 'On-Time Delivery', 'Open SCARs', 'Ratings'],
      suppliers.map((s) => [s.name, s.status, num(s.quality_score), num(s.on_time_delivery), s.open_scars, s.rating_count]),
    );

  return (
    <ReportShell title="Supplier Scorecard" isLoading={isLoading} error={error} hasData={suppliers.length > 0} exportCsv={exportCsv}>
      {data && (
        <>
          <div className="report-kpis">
            <KpiTile label="Suppliers" value={suppliers.length} />
            <KpiTile
              label="Open SCARs"
              value={suppliers.reduce((acc, s) => acc + s.open_scars, 0)}
            />
            <KpiTile label="Rated Suppliers" value={suppliers.filter((s) => s.rating_count > 0).length} />
          </div>
          <div className="card">
            <div className="card__header"><div className="card__title">Average Quality Score</div></div>
            <div className="card__body">
              {qualityBars.length === 0 ? (
                <EmptyState title="No data" description="No supplier ratings recorded." />
              ) : (
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart data={qualityBars} layout="vertical" margin={{ left: 8, right: 16, top: 8 }}>
                    <CartesianGrid stroke={CHART_COLORS.grid} horizontal={false} />
                    <XAxis type="number" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                    <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" width={120} />
                    <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                    <Bar dataKey="value" name="Quality" fill={CHART_COLORS.success} radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>
          <div className="card">
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Supplier</th><th>Status</th>
                    <th className="num">Quality</th><th className="num">On-Time Delivery</th>
                    <th className="num">Open SCARs</th><th className="num">Ratings</th>
                  </tr>
                </thead>
                <tbody>
                  {suppliers.length === 0 ? (
                    <tr className="empty-row"><td colSpan={6}><EmptyState title="No suppliers" /></td></tr>
                  ) : (
                    suppliers.map((s) => (
                      <tr key={s.name}>
                        <td>{s.name}</td>
                        <td>{s.status}</td>
                        <td className="num">{num(s.quality_score)}</td>
                        <td className="num">{num(s.on_time_delivery)}</td>
                        <td className="num">{s.open_scars}</td>
                        <td className="num">{s.rating_count}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </ReportShell>
  );
}

/* --------------------------- Audit report -------------------------- */
function AuditReport() {
  const { data, isLoading, error } = useAuditSummaryReport(12);
  const typeBars = (data?.by_type ?? []).map((s) => ({ name: s.label, value: s.count }));
  const findingBars = (data?.findings_by_type ?? []).map((s) => ({ name: s.label, value: s.count }));
  const statusPie = (data?.by_status ?? []).map((s) => ({ name: s.label, value: s.count }));
  const exportCsv = () =>
    downloadCsv(
      'audit-summary',
      ['Finding Type', 'Count'],
      (data?.findings_by_type ?? []).map((f) => [f.label, f.count]),
    );

  return (
    <ReportShell title="Audit Summary" isLoading={isLoading} error={error} hasData={!!data?.findings_by_type.length} exportCsv={exportCsv}>
      {data && (
        <>
          <div className="report-kpis">
            <KpiTile label="Total Audits" value={data.total} />
            <KpiTile label="Audit Types" value={data.by_type.length} />
            <KpiTile label="Finding Types" value={data.findings_by_type.length} />
          </div>
          <div className="chart-grid">
            <div className="card">
              <div className="card__header"><div className="card__title">Audits by Type</div></div>
              <div className="card__body">
                {typeBars.length === 0 ? (
                  <EmptyState title="No data" description="No audits recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={typeBars} margin={{ left: -18, right: 8, top: 8 }}>
                      <CartesianGrid stroke={CHART_COLORS.grid} vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" />
                      <YAxis tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="value" name="Audits" fill={CHART_COLORS.primary} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
            <div className="card">
              <div className="card__header"><div className="card__title">Audits by Status</div></div>
              <div className="card__body">
                {statusPie.length === 0 ? (
                  <EmptyState title="No data" description="No audits recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <PieChart>
                      <Pie data={statusPie} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2}>
                        {statusPie.map((e, i) => <Cell key={e.name} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={tooltipStyle} />
                      <Legend wrapperStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
            <div className="card">
              <div className="card__header"><div className="card__title">Findings by Type</div></div>
              <div className="card__body">
                {findingBars.length === 0 ? (
                  <EmptyState title="No data" description="No audit findings recorded." />
                ) : (
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={findingBars} layout="vertical" margin={{ left: 8, right: 16, top: 8 }}>
                      <CartesianGrid stroke={CHART_COLORS.grid} horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 11 }} stroke="var(--text-faint)" allowDecimals={false} />
                      <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} stroke="var(--text-faint)" width={100} />
                      <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="value" name="Findings" fill={CHART_COLORS.warning} radius={[0, 4, 4, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
          </div>
          <div className="card">
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>Finding Type</th><th className="num">Count</th></tr></thead>
                <tbody>
                  {data.findings_by_type.length === 0 ? (
                    <tr className="empty-row"><td colSpan={2}><EmptyState title="No findings" /></td></tr>
                  ) : (
                    data.findings_by_type.map((f) => (
                      <tr key={f.label}><td>{f.label}</td><td className="num">{f.count}</td></tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </ReportShell>
  );
}

export default function ReportsPage() {
  const [selected, setSelected] = useState<ReportKey>('ncr');

  const body = useMemo(() => {
    switch (selected) {
      case 'ncr':
        return <NcrReport />;
      case 'capa':
        return <CapaReport />;
      case 'supplier':
        return <SupplierReport />;
      case 'audit':
        return <AuditReport />;
      default:
        return null;
    }
  }, [selected]);

  return (
    <>
      <PageHeader
        title="Reports & Exports"
        icon={<FileBarChart size={22} />}
        subtitle="Generate quality reports, export to CSV, or print/save as PDF."
        breadcrumbs={[{ label: 'Reports' }]}
      />

      <div className="report-selector no-print">
        {REPORTS.map((r) => (
          <button
            key={r.key}
            type="button"
            className={`report-tab ${selected === r.key ? 'report-tab--active' : ''}`}
            onClick={() => setSelected(r.key)}
            aria-pressed={selected === r.key}
          >
            <span className="report-tab__label">{r.label}</span>
            <span className="report-tab__desc">{r.description}</span>
          </button>
        ))}
      </div>

      {body}
    </>
  );
}
