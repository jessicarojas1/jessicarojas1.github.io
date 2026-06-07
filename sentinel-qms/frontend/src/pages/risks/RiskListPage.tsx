import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ShieldAlert,
  Plus,
  Upload,
  AlertOctagon,
  AlertTriangle,
  AlertCircle,
  Info,
} from 'lucide-react';
import { riskHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { useUserName } from '@/hooks/useUserLookup';
import { getErrorMessage } from '@/lib/api';
import { humanize } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { ImportModal } from '@/components/ImportModal';
import { RiskHeatMap } from './RiskHeatMap';
import { rpnLevel, toBand, type MatrixLevel } from './riskMatrix';
import { RiskCreateModal } from './RiskCreateModal';
import type { Risk } from '@/types';

const LEVEL_LABEL: Record<MatrixLevel, string> = {
  critical: 'Critical',
  high: 'High',
  medium: 'Medium',
  low: 'Low',
};

function RpnBadge({ rpn }: { rpn: number }) {
  const level = rpnLevel(rpn);
  const tone = level === 'critical' || level === 'high' ? 'danger' : level === 'medium' ? 'warning' : 'success';
  return <span className={`badge badge--${tone} badge--no-dot`}>{rpn}</span>;
}

function LevelBadge({ rpn }: { rpn: number }) {
  const level = rpnLevel(rpn);
  return <span className={`risk-level-badge risk-level--${level}`}>{LEVEL_LABEL[level]}</span>;
}

export default function RiskListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'rpn', order: 'desc', page_size: 100 });
  const userName = useUserName();
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [activeCell, setActiveCell] = useState<string | null>(null);
  const { data, isLoading, error } = riskHooks.useList(ctl.params);
  const risks = useMemo(() => data?.items ?? [], [data]);

  // AEGIS-style severity-level summary (by inherent RPN level) + status tallies.
  const summary = useMemo(() => {
    const s = { critical: 0, high: 0, medium: 0, low: 0, open: 0, monitoring: 0, closed: 0 };
    for (const r of risks) {
      s[rpnLevel(r.rpn)] += 1;
      if (r.status === 'monitoring') s.monitoring += 1;
      else if (r.status === 'closed') s.closed += 1;
      else s.open += 1;
    }
    return s;
  }, [risks]);

  // Filter table rows by the selected heat-map cell (likelihoodBand-impactBand).
  const tableRows = useMemo(() => {
    if (!activeCell) return risks;
    const [lb, ib] = activeCell.split('-').map(Number);
    return risks.filter((r) => toBand(r.likelihood) === lb && toBand(r.severity) === ib);
  }, [risks, activeCell]);

  const columns: Column<Risk>[] = [
    { key: 'risk_number', header: 'Risk ID', sortable: true, width: '110px', render: (r) => <span className="mono">{r.risk_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'category', header: 'Category', render: (r) => humanize(r.category) },
    { key: 'likelihood', header: 'Likelihood', align: 'center', sortable: true, render: (r) => <span className="score-cell">{r.likelihood}</span> },
    { key: 'severity', header: 'Impact', align: 'center', sortable: true, render: (r) => <span className="score-cell">{r.severity}</span> },
    { key: 'rpn', header: 'Score (RPN)', align: 'right', sortable: true, render: (r) => <RpnBadge rpn={r.rpn} /> },
    { key: 'level', header: 'Level', render: (r) => <LevelBadge rpn={r.rpn} /> },
    { key: 'residual_rpn', header: 'Residual', align: 'right', sortable: true, render: (r) => (r.residual_rpn != null ? <RpnBadge rpn={r.residual_rpn} /> : '—') },
    { key: 'treatment_strategy', header: 'Treatment', render: (r) => (r.treatment_strategy ? <span className="pill">{humanize(r.treatment_strategy)}</span> : '—') },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'owner_id', header: 'Owner', render: (r) => userName(r.owner_id) },
  ];

  return (
    <>
      <PageHeader
        title="Risk Register"
        icon={<ShieldAlert size={22} />}
        subtitle="Track, assess, and treat organizational risk — FMEA-style RPN scoring."
        breadcrumbs={[{ label: 'Risk Register' }]}
        actions={
          canEdit('risks') && (
            <>
              <button type="button" className="btn" onClick={() => setImportOpen(true)}>
                <Upload size={16} /> Import
              </button>
              <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
                <Plus size={16} /> Log Risk
              </button>
            </>
          )
        }
      />

      <div className="stack">
        {/* Summary KPIs */}
        <div className="risk-kpi-grid">
          <div className="risk-kpi risk-kpi--critical">
            <AlertOctagon size={20} />
            <span className="risk-kpi__num">{summary.critical}</span>
            <span className="risk-kpi__label">Critical</span>
          </div>
          <div className="risk-kpi risk-kpi--high">
            <AlertTriangle size={20} />
            <span className="risk-kpi__num">{summary.high}</span>
            <span className="risk-kpi__label">High</span>
          </div>
          <div className="risk-kpi risk-kpi--medium">
            <AlertCircle size={20} />
            <span className="risk-kpi__num">{summary.medium}</span>
            <span className="risk-kpi__label">Medium</span>
          </div>
          <div className="risk-kpi risk-kpi--low">
            <Info size={20} />
            <span className="risk-kpi__num">{summary.low}</span>
            <span className="risk-kpi__label">Low</span>
          </div>
          <div className="risk-kpi">
            <span className="risk-kpi__num">{summary.open}</span>
            <span className="risk-kpi__label">Open / Active</span>
          </div>
          <div className="risk-kpi">
            <span className="risk-kpi__num">{summary.monitoring}</span>
            <span className="risk-kpi__label">Monitoring</span>
          </div>
          <div className="risk-kpi">
            <span className="risk-kpi__num">{summary.closed}</span>
            <span className="risk-kpi__label">Closed</span>
          </div>
        </div>

        {/* Heat map / 5×5 matrix */}
        <div className="card">
          <div className="card__header">
            <div className="card__title">Risk Matrix — Likelihood × Impact</div>
            {activeCell && (
              <button type="button" className="btn btn-sm" onClick={() => setActiveCell(null)}>
                Clear cell filter
              </button>
            )}
          </div>
          <div className="card__body">
            {isLoading ? (
              <div className="loading-block"><span className="spinner" /></div>
            ) : (
              <RiskHeatMap risks={risks} activeCell={activeCell} onCellSelect={setActiveCell} />
            )}
          </div>
        </div>

        {/* Risk register table */}
        <DataTable
          columns={columns}
          rows={tableRows}
          rowKey={(r) => r.id}
          loading={isLoading}
          error={error ? getErrorMessage(error) : null}
          onRowClick={(r) => navigate(`/risks/${r.id}`)}
          search={ctl.search}
          onSearchChange={ctl.setSearch}
          searchPlaceholder="Search risk ID or title…"
          sort={ctl.sort}
          order={ctl.order}
          onSortChange={ctl.onSortChange}
          exportFilename="risk-register"
          emptyTitle={activeCell ? 'No risks in this cell' : 'No risks found'}
          filters={
            <>
              <div className="field">
                <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                  <option value="">All statuses</option>
                  {['identified', 'assessed', 'treatment_planned', 'mitigating', 'monitoring', 'closed'].map((s) => (
                    <option key={s} value={s}>{humanize(s)}</option>
                  ))}
                </Select>
              </div>
              <div className="field">
                <Select aria-label="Filter by category" value={ctl.filters.category ?? ''} onChange={(e) => ctl.setFilter('category', e.target.value)}>
                  <option value="">All categories</option>
                  {['quality', 'supply_chain', 'operational', 'compliance', 'safety', 'cybersecurity', 'program'].map((s) => (
                    <option key={s} value={s}>{humanize(s)}</option>
                  ))}
                </Select>
              </div>
              <div className="field">
                <Select aria-label="Filter by treatment strategy" value={ctl.filters.treatment_strategy ?? ''} onChange={(e) => ctl.setFilter('treatment_strategy', e.target.value)}>
                  <option value="">All strategies</option>
                  {['mitigate', 'accept', 'transfer', 'avoid'].map((s) => (
                    <option key={s} value={s}>{humanize(s)}</option>
                  ))}
                </Select>
              </div>
            </>
          }
        />
      </div>

      <RiskCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/risks/${id}`);
        }}
      />

      <ImportModal
        resource="risks"
        title="Import Risks"
        open={importOpen}
        onClose={() => setImportOpen(false)}
        listQueryKey={riskHooks.baseKey}
      />
    </>
  );
}
