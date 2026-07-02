import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Archive, Lock, Pencil } from 'lucide-react';
import { useRetentionPolicy } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { getErrorMessage } from '@/lib/api';
import { humanize } from '@/lib/format';
import { PrintButton } from '@/components/PrintButton';
import { DataList, DetailState } from '@/components/detail';
import { RecordDetailHeader } from '@/components/RecordDetailHeader';
import { UserName } from '@/components/UserName';
import { RetentionFormModal } from './RetentionFormModal';
import type { RetentionPolicy } from '@/types';

function retentionPeriod(p: RetentionPolicy): string {
  if (p.disposition_action === 'permanent' || p.retention_years == null) {
    return 'Permanent / indefinite';
  }
  return `${p.retention_years} year${p.retention_years === 1 ? '' : 's'}`;
}

export default function RetentionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const policyId = id ? Number(id) : undefined;
  const { data: policy, isLoading, error } = useRetentionPolicy(policyId);
  const { canEdit } = usePagePerms();
  const writable = canEdit('retention');
  const [editOpen, setEditOpen] = useState(false);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !policy}
    >
      {policy && (
        <>
          <RecordDetailHeader
            icon={<Archive size={22} />}
            recordNumber={policy.policy_number}
            status={policy.status}
            title={policy.title}
            badges={
              policy.legal_hold ? (
                <span className="badge badge--warning">
                  <Lock size={12} /> Legal hold
                </span>
              ) : undefined
            }
            listLabel="Retention Schedule"
            listTo="/retention"
            actions={
              <>
                <PrintButton />
                {writable && (
                  <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => setEditOpen(true)}
                  >
                    <Pencil size={16} /> Edit
                  </button>
                )}
              </>
            }
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Retention & Disposition</div>
                </div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Record Category', value: humanize(policy.record_category) },
                      { label: 'Retention Trigger', value: humanize(policy.retention_trigger) },
                      { label: 'Retention Period', value: retentionPeriod(policy) },
                      {
                        label: 'Disposition Action (scheduled)',
                        value: humanize(policy.disposition_action),
                      },
                      {
                        label: 'Legal Hold',
                        value: policy.legal_hold
                          ? 'On hold — disposition suspended'
                          : 'Not on hold',
                      },
                      {
                        label: 'Authority Reference',
                        value: policy.authority_reference || '—',
                      },
                      { label: 'Owner', value: <UserName id={policy.owner_id} /> },
                    ]}
                  />
                  <p className="text-sm muted" style={{ marginBottom: 0 }}>
                    Disposition is a scheduled, manually-performed step. This record documents the
                    schedule only — nothing is destroyed or archived automatically.
                  </p>
                </div>
              </div>

              {policy.notes ? (
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Notes</div>
                  </div>
                  <div className="card__body">
                    <p style={{ margin: 0 }}>{policy.notes}</p>
                  </div>
                </div>
              ) : null}
            </div>
          </div>

          <RetentionFormModal
            open={editOpen}
            policy={policy}
            onClose={() => setEditOpen(false)}
            onSaved={() => setEditOpen(false)}
          />
        </>
      )}
    </DetailState>
  );
}
