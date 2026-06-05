import { useParams } from 'react-router-dom';
import { FlaskConical } from 'lucide-react';
import { inspectionHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';

export default function InspectionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: insp, isLoading, error } = inspectionHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !insp}
    >
      {insp && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <FlaskConical size={22} />
                <span className="mono">{insp.fai_number}</span>
                <StatusBadge status={insp.result} />
              </span>
            }
            subtitle={`${insp.part_number}${insp.part_name ? ` — ${insp.part_name}` : ''}`}
            breadcrumbs={[{ label: 'Inspections', to: '/inspections' }, { label: insp.fai_number }]}
          />

          <div className="detail-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Characteristics (AS9102 Form 3)</div>
                <span className="text-sm muted">{insp.characteristics?.length ?? 0} features</span>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Char #</th>
                      <th>Requirement</th>
                      <th>Nominal</th>
                      <th>Tolerance</th>
                      <th>Actual</th>
                      <th>Result</th>
                    </tr>
                  </thead>
                  <tbody>
                    {insp.characteristics?.length ? (
                      insp.characteristics.map((c) => (
                        <tr key={c.id}>
                          <td className="mono">{c.number}</td>
                          <td>{c.requirement}</td>
                          <td className="mono">{c.nominal ?? '—'}</td>
                          <td className="mono">{c.tolerance ?? '—'}</td>
                          <td className="mono">{c.actual ?? '—'}</td>
                          <td><StatusBadge status={c.result} /></td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={6}><div className="empty-state-sm">No characteristics recorded.</div></td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="card">
              <div className="card__header"><div className="card__title">Inspection</div></div>
              <div className="card__body">
                <DataList
                  items={[
                    { label: 'Type', value: humanize(insp.type) },
                    { label: 'Revision', value: insp.revision ?? '—' },
                    { label: 'Drawing #', value: insp.drawing_number ?? '—' },
                    { label: 'Inspector', value: insp.inspector },
                    { label: 'Result', value: <StatusBadge status={insp.result} /> },
                    { label: 'Performed', value: formatDate(insp.performed_at) },
                  ]}
                />
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
