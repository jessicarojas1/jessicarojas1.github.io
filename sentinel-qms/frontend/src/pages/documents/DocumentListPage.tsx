import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, Plus } from 'lucide-react';
import { documentHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { DocumentFormModal } from './DocumentFormModal';
import { DEPARTMENT_OPTIONS, STATUS_OPTIONS, departmentLabel, docTypeLabel } from './documentOptions';
import type { ControlledDocument } from '@/types';

export default function DocumentListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'document_number', order: 'asc' });
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = documentHooks.useList(ctl.params);

  const columns: Column<ControlledDocument>[] = [
    { key: 'document_number', header: 'Doc #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.document_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'doc_type', header: 'Type', render: (r) => docTypeLabel(r.doc_type) },
    { key: 'department', header: 'Dept', render: (r) => <span className="pill">{departmentLabel(r.department)}</span> },
    { key: 'current_revision', header: 'Rev', align: 'center', render: (r) => <span className="mono">{r.current_revision ?? '—'}</span> },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'next_review_date', header: 'Next Review', sortable: true, render: (r) => formatDate(r.next_review_date) },
  ];

  return (
    <>
      <PageHeader
        title="Document Control"
        icon={<FileText size={22} />}
        subtitle="Controlled documents, revisions, and approval workflow."
        breadcrumbs={[{ label: 'Documents' }]}
        actions={
          canEdit('documents') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Document
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
        exportFilename="documents"
        filters={
          <>
            <div className="field">
              <Select
                aria-label="Filter by status"
                value={ctl.filters.status ?? ''}
                onChange={(e) => ctl.setFilter('status', e.target.value)}
              >
                <option value="">All statuses</option>
                {STATUS_OPTIONS.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select
                aria-label="Filter by department"
                value={ctl.filters.department ?? ''}
                onChange={(e) => ctl.setFilter('department', e.target.value)}
              >
                <option value="">All departments</option>
                {DEPARTMENT_OPTIONS.map((d) => (
                  <option key={d.value} value={d.value}>
                    {d.label}
                  </option>
                ))}
              </Select>
            </div>
          </>
        }
      />
      <DocumentFormModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onSaved={(id) => {
          setCreateOpen(false);
          navigate(`/documents/${id}`);
        }}
      />
    </>
  );
}
