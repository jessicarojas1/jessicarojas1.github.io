import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { GitPullRequestArrow, Plus } from 'lucide-react';
import { changeHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { ChangeCreateModal } from './ChangeCreateModal';
import type { ChangeRequest } from '@/types';

export default function ChangeListPage() {
  const navigate = useNavigate();
  const ctl = useListController();
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = changeHooks.useList(ctl.params);

  const columns: Column<ChangeRequest>[] = [
    { key: 'change_number', header: 'Change #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.change_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'change_type', header: 'Type', render: (r) => <span className="pill">{r.change_type.toUpperCase()}</span> },
    { key: 'priority', header: 'Priority', render: (r) => <StatusBadge status={r.priority} /> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'target_date', header: 'Target', sortable: true, render: (r) => formatDate(r.target_date) },
    { key: 'created_at', header: 'Created', sortable: true, render: (r) => formatDate(r.created_at) },
  ];

  return (
    <>
      <PageHeader
        title="Change Control"
        icon={<GitPullRequestArrow size={22} />}
        subtitle="Engineering change notices/orders (ECN/ECO), deviations, and waivers."
        breadcrumbs={[{ label: 'Change Control' }]}
        actions={
          canEdit('changes') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Change
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
        onRowClick={(r) => navigate(`/changes/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search change # or title…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        exportFilename="change-control"
        filters={
          <>
            <div className="field">
              <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                <option value="">All statuses</option>
                {['draft', 'submitted', 'under_review', 'approved', 'rejected', 'implemented', 'closed'].map((s) => (
                  <option key={s} value={s}>{humanize(s)}</option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select aria-label="Filter by type" value={ctl.filters.type ?? ''} onChange={(e) => ctl.setFilter('type', e.target.value)}>
                <option value="">All types</option>
                {['ecn', 'eco', 'deviation', 'waiver'].map((s) => (
                  <option key={s} value={s}>{s.toUpperCase()}</option>
                ))}
              </Select>
            </div>
          </>
        }
      />
      <ChangeCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/changes/${id}`);
        }}
      />
    </>
  );
}
