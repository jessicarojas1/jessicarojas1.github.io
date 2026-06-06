import { useState } from 'react';
import { History } from 'lucide-react';
import { useAuditLogs } from '@/hooks';
import { useDebounced } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDateTime, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { TextInput } from '@/components/FormField';
import type { AuditLogEntry } from '@/types';

export default function AuditTrailPage() {
  const [page, setPage] = useState(1);
  const [entityType, setEntityType] = useState('');
  const [action, setAction] = useState('');
  const [actorEmail, setActorEmail] = useState('');

  const dEntity = useDebounced(entityType, 300);
  const dAction = useDebounced(action, 300);
  const dActor = useDebounced(actorEmail, 300);

  const pageSize = 25;
  const { data, isLoading, error } = useAuditLogs({
    entity_type: dEntity || undefined,
    action: dAction || undefined,
    actor_email: dActor || undefined,
    page,
    size: pageSize,
  });

  const columns: Column<AuditLogEntry>[] = [
    {
      key: 'created_at',
      header: 'Timestamp',
      render: (r) => <span className="nowrap">{formatDateTime(r.created_at)}</span>,
      width: '180px',
    },
    {
      key: 'actor_email',
      header: 'Actor',
      render: (r) => r.actor_email ?? <span className="text-muted">system</span>,
    },
    {
      key: 'action',
      header: 'Action',
      render: (r) => <span className="pill">{humanize(r.action)}</span>,
    },
    { key: 'entity_type', header: 'Entity Type', render: (r) => humanize(r.entity_type) },
    {
      key: 'entity_id',
      header: 'Entity ID',
      render: (r) => (r.entity_id != null ? <span className="mono">{r.entity_id}</span> : '—'),
      width: '110px',
    },
  ];

  return (
    <>
      <PageHeader
        title="Audit Trail"
        icon={<History size={22} />}
        subtitle="System-wide record of changes for traceability and 21 CFR Part 11 compliance."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Audit Trail' }]}
      />

      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => String(r.id)}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        page={page}
        pageSize={data?.page_size ?? pageSize}
        total={data?.total}
        onPageChange={setPage}
        exportFilename="audit-trail"
        emptyTitle="No audit log entries"
        emptyDescription="Adjust the filters to broaden your search."
        filters={
          <>
            <div className="field">
              <TextInput
                aria-label="Filter by entity type"
                placeholder="Entity type (e.g. nonconformance)"
                value={entityType}
                onChange={(e) => {
                  setEntityType(e.target.value);
                  setPage(1);
                }}
              />
            </div>
            <div className="field">
              <TextInput
                aria-label="Filter by action"
                placeholder="Action (e.g. update)"
                value={action}
                onChange={(e) => {
                  setAction(e.target.value);
                  setPage(1);
                }}
              />
            </div>
            <div className="field">
              <TextInput
                aria-label="Filter by actor email"
                placeholder="Actor email"
                value={actorEmail}
                onChange={(e) => {
                  setActorEmail(e.target.value);
                  setPage(1);
                }}
              />
            </div>
          </>
        }
      />
    </>
  );
}
