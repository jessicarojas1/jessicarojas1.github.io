import { useNavigate } from 'react-router-dom';
import { FlaskConical } from 'lucide-react';
import { inspectionHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Inspection } from '@/types';

export default function InspectionListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'performed_at', order: 'desc' });
  const { data, isLoading, error } = inspectionHooks.useList(ctl.params);

  const columns: Column<Inspection>[] = [
    { key: 'fai_number', header: 'FAI #', sortable: true, width: '130px', render: (r) => <span className="mono">{r.fai_number}</span> },
    { key: 'part_number', header: 'Part #', sortable: true, render: (r) => <span className="mono">{r.part_number}</span> },
    { key: 'part_name', header: 'Part Name', render: (r) => r.part_name ?? '—' },
    { key: 'revision', header: 'Rev', align: 'center', render: (r) => r.revision ?? '—' },
    { key: 'type', header: 'Type', render: (r) => humanize(r.type) },
    { key: 'result', header: 'Result', sortable: true, render: (r) => <StatusBadge status={r.result} /> },
    { key: 'inspector', header: 'Inspector' },
    { key: 'performed_at', header: 'Date', sortable: true, render: (r) => formatDate(r.performed_at) },
  ];

  return (
    <>
      <PageHeader
        title="Inspections & First Article (AS9102)"
        icon={<FlaskConical size={22} />}
        subtitle="First article inspection (FAI), in-process, receiving, and final inspections."
        breadcrumbs={[{ label: 'Inspections' }]}
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
        filters={
          <>
            <div className="field">
              <Select aria-label="Filter by result" value={ctl.filters.result ?? ''} onChange={(e) => ctl.setFilter('result', e.target.value)}>
                <option value="">All results</option>
                {['pass', 'fail', 'conditional', 'pending'].map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select aria-label="Filter by type" value={ctl.filters.type ?? ''} onChange={(e) => ctl.setFilter('type', e.target.value)}>
                <option value="">All types</option>
                {['fai', 'in_process', 'final', 'receiving'].map((s) => (
                  <option key={s} value={s}>{humanize(s)}</option>
                ))}
              </Select>
            </div>
          </>
        }
      />
    </>
  );
}
