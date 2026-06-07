import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { GaugeCircle, Plus } from 'lucide-react';
import { mgmtReviewHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { MgmtReviewCreateModal } from './MgmtReviewCreateModal';
import type { MgmtReview } from '@/types';

export default function MgmtReviewListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'meeting_date', order: 'desc' });
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = mgmtReviewHooks.useList(ctl.params);

  const columns: Column<MgmtReview>[] = [
    { key: 'review_number', header: 'Review #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.review_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'meeting_date', header: 'Meeting Date', sortable: true, render: (r) => formatDate(r.meeting_date) },
    { key: 'created_at', header: 'Created', sortable: true, render: (r) => formatDate(r.created_at) },
  ];

  return (
    <>
      <PageHeader
        title="Management Review"
        icon={<GaugeCircle size={22} />}
        subtitle="ISO 9001 / AS9100 management review meetings, inputs, outputs, and actions."
        breadcrumbs={[{ label: 'Management Review' }]}
        actions={
          canEdit('mgmt_reviews') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Management Review
            </button>
          )
        }
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
        exportFilename="management-reviews"
        filters={
          <div className="field">
            <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
              <option value="">All statuses</option>
              {['scheduled', 'in_progress', 'completed', 'closed'].map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </Select>
          </div>
        }
      />

      <MgmtReviewCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/mgmt-reviews/${id}`);
        }}
      />
    </>
  );
}
