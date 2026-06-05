import { useNavigate } from 'react-router-dom';
import { Truck } from 'lucide-react';
import { supplierHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatPercent } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Supplier } from '@/types';

function RatingStars({ rating }: { rating: number }) {
  const pct = (rating / 5) * 100;
  return (
    <div className="rating-bar" title={`${rating.toFixed(1)} / 5`}>
      <div className="progress" style={{ width: 70 }}>
        <div className="progress__bar" style={{ width: `${pct}%` }} />
      </div>
      <span className="text-sm mono">{rating.toFixed(1)}</span>
    </div>
  );
}

export default function SupplierListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'name', order: 'asc' });
  const { data, isLoading, error } = supplierHooks.useList(ctl.params);

  const columns: Column<Supplier>[] = [
    { key: 'code', header: 'Code', sortable: true, width: '100px', render: (r) => <span className="mono">{r.code}</span> },
    { key: 'name', header: 'Supplier', sortable: true, render: (r) => <strong>{r.name}</strong> },
    { key: 'status', header: 'ASL Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'rating', header: 'Rating', sortable: true, render: (r) => <RatingStars rating={r.rating} /> },
    { key: 'on_time_delivery', header: 'OTD', align: 'right', render: (r) => formatPercent(r.on_time_delivery) },
    { key: 'quality_ppm', header: 'PPM', align: 'right', render: (r) => r.quality_ppm.toLocaleString() },
    {
      key: 'open_scars',
      header: 'Open SCARs',
      align: 'right',
      render: (r) =>
        r.open_scars > 0 ? <span className="badge badge--danger badge--no-dot">{r.open_scars}</span> : '0',
    },
  ];

  return (
    <>
      <PageHeader
        title="Approved Supplier List"
        icon={<Truck size={22} />}
        subtitle="Supplier qualification, scorecards, and corrective action requests (SCAR)."
        breadcrumbs={[{ label: 'Suppliers' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/suppliers/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search supplier name or code…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        filters={
          <div className="field">
            <Select
              aria-label="Filter by status"
              value={ctl.filters.status ?? ''}
              onChange={(e) => ctl.setFilter('status', e.target.value)}
            >
              <option value="">All statuses</option>
              {['approved', 'conditional', 'probation', 'disqualified'].map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </Select>
          </div>
        }
      />
    </>
  );
}
