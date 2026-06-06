import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FlaskConical, Plus } from 'lucide-react';
import { inspectionHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { InspectionCreateModal } from './InspectionCreateModal';
import type { Inspection } from '@/types';

export default function InspectionListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'inspection_date', order: 'desc' });
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = inspectionHooks.useList(ctl.params);

  const columns: Column<Inspection>[] = [
    { key: 'inspection_number', header: 'Inspection #', sortable: true, width: '150px', render: (r) => <span className="mono">{r.inspection_number}</span> },
    { key: 'part_number', header: 'Part #', sortable: true, render: (r) => <span className="mono">{r.part_number ?? '—'}</span> },
    { key: 'inspection_type', header: 'Type', render: (r) => humanize(r.inspection_type) },
    { key: 'result', header: 'Result', sortable: true, render: (r) => <StatusBadge status={r.result} /> },
    { key: 'inspection_date', header: 'Date', sortable: true, render: (r) => formatDate(r.inspection_date) },
  ];

  return (
    <>
      <PageHeader
        title="Inspections & First Article (AS9102)"
        icon={<FlaskConical size={22} />}
        subtitle="First article inspection (FAI), in-process, receiving, and final inspections."
        breadcrumbs={[{ label: 'Inspections' }]}
        actions={
          canEdit('inspections') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Inspection
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
        onRowClick={(r) => navigate(`/inspections/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search FAI # or part…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        exportFilename="inspections"
        filters={
          <>
            <div className="field">
              <Select aria-label="Filter by result" value={ctl.filters.result ?? ''} onChange={(e) => ctl.setFilter('result', e.target.value)}>
                <option value="">All results</option>
                {['pending', 'accept', 'reject', 'accept_with_deviation'].map((s) => (
                  <option key={s} value={s}>{humanize(s)}</option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select aria-label="Filter by type" value={ctl.filters.inspection_type ?? ''} onChange={(e) => ctl.setFilter('inspection_type', e.target.value)}>
                <option value="">All types</option>
                {['receiving', 'in_process', 'final', 'first_article', 'source'].map((s) => (
                  <option key={s} value={s}>{humanize(s)}</option>
                ))}
              </Select>
            </div>
          </>
        }
      />

      <InspectionCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/inspections/${id}`);
        }}
      />
    </>
  );
}
