import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Wrench } from 'lucide-react';
import { calibrationHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { daysUntil, formatDate } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { CalibrationCreateModal } from './CalibrationCreateModal';
import type { Equipment } from '@/types';

function DueCell({ value }: { value?: string }) {
  const days = daysUntil(value);
  if (days === null) return <span className="muted">—</span>;
  if (days < 0)
    return <span className="badge badge--danger badge--no-dot">Overdue {Math.abs(days)}d</span>;
  if (days <= 30)
    return <span className="badge badge--warning badge--no-dot">Due in {days}d</span>;
  return <span>{formatDate(value)}</span>;
}

export default function CalibrationListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'next_due_date', order: 'asc' });
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = calibrationHooks.useList(ctl.params);

  const columns: Column<Equipment>[] = [
    { key: 'asset_tag', header: 'Asset Tag', sortable: true, width: '120px', render: (r) => <span className="mono">{r.asset_tag}</span> },
    { key: 'name', header: 'Equipment', sortable: true, render: (r) => <strong>{r.name}</strong> },
    { key: 'location', header: 'Location', render: (r) => r.location ?? '—' },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'next_due_date', header: 'Next Due', sortable: true, render: (r) => <DueCell value={r.next_due_date} /> },
  ];

  return (
    <>
      <PageHeader
        title="Calibration Register"
        icon={<Wrench size={22} />}
        subtitle="Measurement & test equipment calibration status and due dates."
        breadcrumbs={[{ label: 'Calibration' }]}
        actions={
          canEdit('calibration') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Equipment
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
        onRowClick={(r) => navigate(`/calibration/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search asset tag or equipment…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        exportFilename="calibration"
        filters={
          <>
            <div className="field">
              <Select
                aria-label="Filter by status"
                value={ctl.filters.status ?? ''}
                onChange={(e) => ctl.setFilter('status', e.target.value)}
              >
                <option value="">All statuses</option>
                {['active', 'out_of_service', 'lost', 'retired'].map((s) => (
                  <option key={s} value={s}>
                    {s.replace(/_/g, ' ')}
                  </option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select
                aria-label="Filter by due window"
                value={ctl.filters.due ?? ''}
                onChange={(e) => ctl.setFilter('due', e.target.value)}
              >
                <option value="">All due dates</option>
                <option value="overdue">Overdue</option>
                <option value="30">Due within 30 days</option>
                <option value="90">Due within 90 days</option>
              </Select>
            </div>
          </>
        }
      />

      <CalibrationCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/calibration/${id}`);
        }}
      />
    </>
  );
}
