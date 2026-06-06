import { useState } from 'react';
import { GraduationCap } from 'lucide-react';
import { trainingHooks, useCompetencyMatrix } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { CompetencyMatrixView } from './CompetencyMatrixView';
import type { TrainingRecord } from '@/types';

export default function TrainingListPage() {
  const [tab, setTab] = useState<'records' | 'matrix'>('records');
  const ctl = useListController({ sort: 'due_date', order: 'asc' });
  const { data, isLoading, error } = trainingHooks.useList(ctl.params, { enabled: tab === 'records' });

  const columns: Column<TrainingRecord>[] = [
    { key: 'employee_name', header: 'Employee', sortable: true, render: (r) => <strong>{r.employee_name}</strong> },
    { key: 'course', header: 'Course', sortable: true },
    { key: 'course_code', header: 'Code', render: (r) => r.course_code ? <span className="mono">{r.course_code}</span> : '—' },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'score', header: 'Score', align: 'right', render: (r) => (r.score != null ? `${r.score}%` : '—') },
    { key: 'assigned_at', header: 'Assigned', render: (r) => formatDate(r.assigned_at) },
    { key: 'due_date', header: 'Due', sortable: true, render: (r) => formatDate(r.due_date) },
    { key: 'completed_at', header: 'Completed', render: (r) => formatDate(r.completed_at) },
  ];

  return (
    <>
      <PageHeader
        title="Training & Competency"
        icon={<GraduationCap size={22} />}
        subtitle="Training records, certifications, and the competency matrix."
        breadcrumbs={[{ label: 'Training' }]}
      />

      <div className="tabs">
        <button type="button" className={`tab ${tab === 'records' ? 'active' : ''}`} onClick={() => setTab('records')}>
          Training Records
        </button>
        <button type="button" className={`tab ${tab === 'matrix' ? 'active' : ''}`} onClick={() => setTab('matrix')}>
          Competency Matrix
        </button>
      </div>

      {tab === 'records' ? (
        <DataTable
          columns={columns}
          rows={data?.items ?? []}
          rowKey={(r) => r.id}
          loading={isLoading}
          error={error ? getErrorMessage(error) : null}
          search={ctl.search}
          onSearchChange={ctl.setSearch}
          searchPlaceholder="Search employee or course…"
          sort={ctl.sort}
          order={ctl.order}
          onSortChange={ctl.onSortChange}
          page={ctl.page}
          pageSize={ctl.pageSize}
          total={data?.total}
          onPageChange={ctl.setPage}
        exportFilename="training"
          filters={
            <div className="field">
              <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                <option value="">All statuses</option>
                {['assigned', 'in_progress', 'completed', 'overdue'].map((s) => (
                  <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                ))}
              </Select>
            </div>
          }
        />
      ) : (
        <MatrixTab />
      )}
    </>
  );
}

function MatrixTab() {
  const { data, isLoading, error } = useCompetencyMatrix();
  if (isLoading) {
    return <div className="card"><div className="loading-block"><span className="spinner" /></div></div>;
  }
  if (error) {
    return (
      <div className="card"><div className="card__body">
        <div className="empty-state-sm" style={{ color: 'var(--danger)' }}>{getErrorMessage(error)}</div>
      </div></div>
    );
  }
  return <CompetencyMatrixView matrix={data} />;
}
