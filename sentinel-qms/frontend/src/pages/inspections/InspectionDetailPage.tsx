import { useParams } from 'react-router-dom';
import { FlaskConical } from 'lucide-react';
import { inspectionHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
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
                <span className="mono">{insp.inspection_number}</span>
                <StatusBadge status={insp.result} />
              </span>
            }
            subtitle={`${insp.part_number ?? ''}${insp.fai_report?.part_name ? ` — ${insp.fai_report.part_name}` : ''}`}
            breadcrumbs={[{ label: 'Inspections', to: '/inspections' }, { label: insp.inspection_number }]}
            actions={<PrintButton />}
          />

          <div className="detail-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Characteristics (AS9102 Form 3)</div>
                <span className="text-sm muted">{insp.fai_report?.characteristics?.length ?? 0} features</span>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Balloon #</th>
                      <th>Characteristic</th>
                      <th>Nominal</th>
                      <th>Tol −/+</th>
                      <th>Measured</th>
                      <th>Result</th>
                    </tr>
                  </thead>
                  <tbody>
                    {insp.fai_report?.characteristics?.length ? (
                      insp.fai_report.characteristics.map((c) => (
                        <tr key={c.id}>
                          <td className="mono">{c.balloon_number}</td>
                          <td>{c.characteristic}</td>
                          <td className="mono">{c.nominal ?? '—'}</td>
                          <td className="mono">{c.tol_minus ?? '—'} / {c.tol_plus ?? '—'}</td>
                          <td className="mono">{c.measured_value ?? '—'}</td>
                          <td>{c.result ?? '—'}</td>
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
                    { label: 'Type', value: humanize(insp.inspection_type) },
                    { label: 'Revision', value: insp.fai_report?.part_revision ?? '—' },
                    { label: 'Drawing #', value: insp.fai_report?.drawing_number ?? '—' },
                    { label: 'Inspector', value: insp.inspector_id ?? '—' },
                    { label: 'Result', value: <StatusBadge status={insp.result} /> },
                    { label: 'Inspected', value: formatDate(insp.inspection_date) },
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
