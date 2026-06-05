import { useNavigate } from 'react-router-dom';
import { GaugeCircle } from 'lucide-react';
import { mgmtReviewHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { MgmtReview } from '@/types';

export default function MgmtReviewListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'scheduled_date', order: 'desc' });
  const { data, isLoading, error } = mgmtReviewHooks.useList(ctl.params);

  const columns: Column<MgmtReview>[] = [
    { key: 'review_number', header: 'Review #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.review_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'chair', header: 'Chair' },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'scheduled_date', header: 'Scheduled', sortable: true, render: (r) => formatDate(r.scheduled_date) },
    { key: 'held_date', header: 'Held', render: (r) => formatDate(r.held_date) },
    { key: 'actions', header: 'Actions', align: 'right', render: (r) => r.actions?.length ?? 0 },
  ];

  return (
    <>
      <PageHeader
        title="Management Review"
        icon={<GaugeCircle size={22} />}
        subtitle="ISO 9001 / AS9100 management review meetings, inputs, outputs, and actions."
        breadcrumbs={[{ label: 'Management Review' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/mgmt-reviews/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search review # or title…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        filters={
          <div className="field">
            <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
              <option value="">All statuses</option>
              {['scheduled', 'in_progress', 'completed'].map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </Select>
          </div>
        }
      />
    </>
  );
}
