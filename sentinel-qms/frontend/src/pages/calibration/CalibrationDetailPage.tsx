import { useParams } from 'react-router-dom';
import { Wrench } from 'lucide-react';
import { calibrationHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';

export default function CalibrationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: eq, isLoading, error } = calibrationHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !eq}
    >
      {eq && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <Wrench size={22} />
                {eq.name}
                <StatusBadge status={eq.status} />
              </span>
            }
            subtitle={`Asset ${eq.asset_tag}`}
            breadcrumbs={[{ label: 'Calibration', to: '/calibration' }, { label: eq.asset_tag }]}
            actions={<PrintButton />}
          />

          <div className="detail-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Calibration History</div>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Result</th>
                      <th>Certificate</th>
                      <th>Performed By</th>
                      <th>Next Due</th>
                    </tr>
                  </thead>
                  <tbody>
                    {eq.records?.length ? (
                      eq.records.map((h) => (
                        <tr key={h.id}>
                          <td>{formatDate(h.calibration_date)}</td>
                          <td>
                            <StatusBadge status={h.result} />
                          </td>
                          <td className="mono text-sm">{h.certificate_number ?? '—'}</td>
                          <td>{h.performed_by ?? '—'}</td>
                          <td>{formatDate(h.due_date)}</td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={5}>
                          <div className="empty-state-sm">No calibration records yet.</div>
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">Equipment</div>
              </div>
              <div className="card__body">
                <DataList
                  items={[
                    { label: 'Manufacturer', value: eq.manufacturer ?? '—' },
                    { label: 'Model', value: eq.model ?? '—' },
                    { label: 'Serial #', value: eq.serial_number ?? '—' },
                    { label: 'Location', value: eq.location ?? '—' },
                    { label: 'Custodian', value: eq.custodian_id ?? '—' },
                    { label: 'Interval', value: `${eq.calibration_interval_days} days` },
                    { label: 'Last Calibrated', value: formatDate(eq.last_calibration_date) },
                    { label: 'Next Due', value: formatDate(eq.next_due_date) },
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
