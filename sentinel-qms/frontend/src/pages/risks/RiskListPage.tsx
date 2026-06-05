import { ShieldAlert } from 'lucide-react';
import { riskHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { RiskHeatMap } from './RiskHeatMap';
import type { Risk } from '@/types';

function RpnBadge({ rpn }: { rpn: number }) {
  const tone = rpn >= 200 ? 'danger' : rpn >= 100 ? 'warning' : 'success';
  return <span className={`badge badge--${tone} badge--no-dot`}>{rpn}</span>;
}

export default function RiskListPage() {
  const ctl = useListController({ sort: 'rpn', order: 'desc', page_size: 100 });
  const { data, isLoading, error } = riskHooks.useList(ctl.params);
  const risks = data?.items ?? [];

  const columns: Column<Risk>[] = [
    { key: 'risk_number', header: 'Risk #', sortable: true, width: '110px', render: (r) => <span className="mono">{r.risk_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'category', header: 'Category' },
    { key: 'rpn', header: 'RPN', align: 'right', sortable: true, render: (r) => <RpnBadge rpn={r.rpn} /> },
    { key: 'residual_rpn', header: 'Residual RPN', align: 'right', sortable: true, render: (r) => r.residual_rpn ?? '—' },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
  ];

  return (
    <>
      <PageHeader
        title="Risk Register"
        icon={<ShieldAlert size={22} />}
        subtitle="FMEA-style risk priority number (RPN) tracking and mitigation."
        breadcrumbs={[{ label: 'Risk Register' }]}
      />

      <div className="stack">
        <div className="card">
          <div className="card__header">
            <div className="card__title">Severity × Occurrence Heat Map</div>
            <span className="text-sm muted">{risks.length} risks plotted</span>
          </div>
          <div className="card__body">
            {isLoading ? (
              <div className="loading-block"><span className="spinner" /></div>
            ) : (
              <RiskHeatMap risks={risks} />
            )}
          </div>
        </div>

        <DataTable
          columns={columns}
          rows={risks}
          rowKey={(r) => r.id}
          loading={isLoading}
          error={error ? getErrorMessage(error) : null}
          search={ctl.search}
          onSearchChange={ctl.setSearch}
          searchPlaceholder="Search risk # or title…"
          sort={ctl.sort}
          order={ctl.order}
          onSortChange={ctl.onSortChange}
          filters={
            <div className="field">
              <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                <option value="">All statuses</option>
                {['identified', 'assessed', 'treatment_planned', 'mitigating', 'monitoring', 'closed'].map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </div>
          }
        />
      </div>
    </>
  );
}
