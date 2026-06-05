import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { CheckCircle2, Gavel, ShieldAlert } from 'lucide-react';
import { ncrHooks } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatDateTime, humanize } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import {
  AttachmentsCard,
  AuditTrailCard,
  DataList,
  DetailState,
} from '@/components/detail';
import { SignatureSummary } from '@/components/SignatureModal';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DispositionModal } from './DispositionModal';

export default function NcrDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { notify } = useToast();
  const { data: ncr, isLoading, error } = ncrHooks.useDetail(id);
  const close = ncrHooks.useAction('close');
  const [dispOpen, setDispOpen] = useState(false);
  const [closeOpen, setCloseOpen] = useState(false);

  const canDisposition = can(user?.roles, 'ncr.disposition');

  const handleClose = async () => {
    if (!id) return;
    try {
      await close.mutateAsync({ id });
      notify('NCR closed', 'success');
      setCloseOpen(false);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !ncr}
    >
      {ncr && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <ShieldAlert size={22} />
                <span className="mono">{ncr.ncr_number}</span>
                <StatusBadge status={ncr.status} />
              </span>
            }
            subtitle={ncr.title}
            breadcrumbs={[
              { label: 'Nonconformances', to: '/nonconformances' },
              { label: ncr.ncr_number },
            ]}
            actions={
              canDisposition && (
                <>
                  {!ncr.disposition && ncr.status !== 'closed' && (
                    <button type="button" className="btn btn-primary" onClick={() => setDispOpen(true)}>
                      <Gavel size={16} /> Disposition
                    </button>
                  )}
                  {ncr.disposition && ncr.status !== 'closed' && (
                    <button type="button" className="btn" onClick={() => setCloseOpen(true)}>
                      <CheckCircle2 size={16} /> Close NCR
                    </button>
                  )}
                </>
              )
            }
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Details</div>
                  <StatusBadge status={ncr.severity} />
                </div>
                <div className="card__body">
                  <p style={{ marginTop: 0 }}>{ncr.description}</p>
                  <DataList
                    items={[
                      { label: 'Source', value: humanize(ncr.source) },
                      { label: 'Part Number', value: ncr.part_number ?? '—' },
                      { label: 'Lot / Serial', value: ncr.lot_number ?? '—' },
                      { label: 'Qty Affected', value: ncr.quantity_affected ?? '—' },
                      { label: 'Supplier', value: ncr.supplier_name ?? '—' },
                      { label: 'Detected By', value: ncr.detected_by },
                      { label: 'Detected', value: formatDateTime(ncr.detected_at) },
                      { label: 'Assigned To', value: ncr.assigned_to ?? 'Unassigned' },
                      { label: 'Due Date', value: formatDate(ncr.due_date) },
                    ]}
                  />
                </div>
              </div>

              <div className="card">
                <div className="card__header">
                  <div className="card__title">Disposition</div>
                </div>
                <div className="card__body">
                  {ncr.disposition ? (
                    <div className="stack">
                      <DataList
                        items={[
                          { label: 'Disposition', value: <StatusBadge status={ncr.disposition.type} noDot /> },
                          { label: 'MRB Required', value: ncr.disposition.mrb_required ? 'Yes' : 'No' },
                          { label: 'By', value: ncr.disposition.dispositioned_by },
                          { label: 'Date', value: formatDateTime(ncr.disposition.dispositioned_at) },
                        ]}
                      />
                      <div>
                        <div className="section-title">Justification</div>
                        <p style={{ margin: 0 }}>{ncr.disposition.justification}</p>
                      </div>
                      {ncr.disposition.signature && (
                        <SignatureSummary signature={ncr.disposition.signature} />
                      )}
                    </div>
                  ) : (
                    <div className="empty-state-sm">
                      No disposition recorded. {canDisposition ? 'Use the Disposition action above.' : 'Awaiting MRB.'}
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Linkage</div>
                </div>
                <div className="card__body">
                  <DataList
                    items={[
                      {
                        label: 'CAPA',
                        value: ncr.linked_capa_id ? (
                          <a href={`/capa/${ncr.linked_capa_id}`}>View linked CAPA</a>
                        ) : (
                          'None'
                        ),
                      },
                      { label: 'Created', value: formatDate(ncr.created_at) },
                      { label: 'Closed', value: formatDate(ncr.closed_at) },
                    ]}
                  />
                </div>
              </div>
              <AttachmentsCard attachments={ncr.attachments} />
              <AuditTrailCard entries={ncr.audit_trail} />
            </div>
          </div>

          {id && <DispositionModal open={dispOpen} ncrId={id} onClose={() => setDispOpen(false)} />}
          <ConfirmDialog
            open={closeOpen}
            title="Close NCR"
            message="Confirm that all disposition actions are complete and this nonconformance can be closed."
            confirmLabel="Close NCR"
            loading={close.isPending}
            onConfirm={handleClose}
            onCancel={() => setCloseOpen(false)}
          />
        </>
      )}
    </DetailState>
  );
}
