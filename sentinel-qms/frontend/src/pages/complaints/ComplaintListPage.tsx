import { useNavigate } from 'react-router-dom';
import { MessageSquareWarning } from 'lucide-react';
import { complaintHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Complaint } from '@/types';

export default function ComplaintListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'received_at', order: 'desc' });
  const { data, isLoading, error } = complaintHooks.useList(ctl.params);

  const columns: Column<Complaint>[] = [
    { key: 'complaint_number', header: 'Complaint #', sortable: true, width: '140px', render: (r) => <span className="mono">{r.complaint_number}</span> },
    { key: 'customer', header: 'Customer', sortable: true, render: (r) => <strong>{r.customer}</strong> },
    { key: 'product', header: 'Product', render: (r) => r.product ?? '—' },
    { key: 'severity', header: 'Severity', render: (r) => <StatusBadge status={r.severity} /> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'rma_number', header: 'RMA #', render: (r) => r.rma_number ? <span className="mono">{r.rma_number}</span> : '—' },
    { key: 'received_at', header: 'Received', sortable: true, render: (r) => formatDate(r.received_at) },
  ];

  return (
    <>
      <PageHeader
        title="Customer Complaints"
        icon={<MessageSquareWarning size={22} />}
        subtitle="Complaint handling, RMA processing, and resolution."
        breadcrumbs={[{ label: 'Complaints' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/complaints/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search complaint #, customer…"
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
              {['received', 'investigating', 'rma_issued', 'resolved', 'closed'].map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </Select>
          </div>
        }
      />
    </>
  );
}
