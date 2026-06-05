import { useNavigate } from 'react-router-dom';
import { FileText } from 'lucide-react';
import { documentHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { ControlledDocument } from '@/types';

export default function DocumentListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'document_number', order: 'asc' });
  const { data, isLoading, error } = documentHooks.useList(ctl.params);

  const columns: Column<ControlledDocument>[] = [
    { key: 'document_number', header: 'Doc #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.document_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'doc_type', header: 'Type', render: (r) => r.doc_type },
    { key: 'current_revision', header: 'Rev', align: 'center', render: (r) => <span className="mono">{r.current_revision ?? '—'}</span> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'effective_date', header: 'Effective', sortable: true, render: (r) => formatDate(r.effective_date) },
  ];

  return (
    <>
      <PageHeader
        title="Document Control"
        icon={<FileText size={22} />}
        subtitle="Controlled documents, revisions, and approval workflow."
        breadcrumbs={[{ label: 'Documents' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/documents/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search doc # or title…"
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
              {['draft', 'in_review', 'approved', 'effective', 'obsolete'].map((s) => (
                <option key={s} value={s}>
                  {s.replace(/_/g, ' ')}
                </option>
              ))}
            </Select>
          </div>
        }
      />
    </>
  );
}
