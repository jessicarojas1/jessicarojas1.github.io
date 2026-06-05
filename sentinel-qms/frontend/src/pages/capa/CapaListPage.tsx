import { useNavigate } from 'react-router-dom';
import { ClipboardCheck } from 'lucide-react';
import { capaHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate, isOverdue } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Capa } from '@/types';

export default function CapaListPage() {
  const navigate = useNavigate();
  const ctl = useListController();
  const { data, isLoading, error } = capaHooks.useList(ctl.params);

  const columns: Column<Capa>[] = [
    {
      key: 'capa_number',
      header: 'CAPA #',
      sortable: true,
      width: '120px',
      render: (r) => <span className="mono">{r.capa_number}</span>,
    },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'capa_type', header: 'Type', render: (r) => <StatusBadge status={r.capa_type} noDot /> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'owner_id', header: 'Owner', render: (r) => r.owner_id ?? '—' },
    {
      key: 'due_date',
      header: 'Due',
      sortable: true,
      render: (r) => (
        <span style={isOverdue(r.due_date) && r.status !== 'closed' ? { color: 'var(--danger)', fontWeight: 600 } : undefined}>
          {formatDate(r.due_date)}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Corrective & Preventive Actions"
        icon={<ClipboardCheck size={22} />}
        subtitle="8D problem solving, root-cause analysis, and effectiveness verification."
        breadcrumbs={[{ label: 'CAPA' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/capa/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search CAPA # or title…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        filters={
          <>
            <div className="field">
              <Select
                aria-label="Filter by status"
                value={ctl.filters.status ?? ''}
                onChange={(e) => ctl.setFilter('status', e.target.value)}
              >
                <option value="">All statuses</option>
                {['open', 'containment', 'root_cause', 'action_plan', 'implementation', 'verification', 'closed', 'cancelled'].map((s) => (
                  <option key={s} value={s}>
                    {s.replace(/_/g, ' ')}
                  </option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select
                aria-label="Filter by type"
                value={ctl.filters.type ?? ''}
                onChange={(e) => ctl.setFilter('type', e.target.value)}
              >
                <option value="">All types</option>
                <option value="corrective">Corrective</option>
                <option value="preventive">Preventive</option>
              </Select>
            </div>
          </>
        }
      />
    </>
  );
}
