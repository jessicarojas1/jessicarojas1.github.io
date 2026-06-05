import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { ClipboardCheck, ShieldCheck } from 'lucide-react';
import { capaHooks } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatDateTime, humanize } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { AttachmentsCard, DataList, DetailState } from '@/components/detail';
import { SignatureModal, SignatureSummary, type SignaturePayload } from '@/components/SignatureModal';
import { EightDStepper } from './EightDStepper';

export default function CapaDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { notify } = useToast();
  const { data: capa, isLoading, error } = capaHooks.useDetail(id);
  const closeCapa = capaHooks.useAction('close');
  const [sigOpen, setSigOpen] = useState(false);

  const canClose = can(user?.roles, 'capa.close');

  const handleSign = async (sig: SignaturePayload) => {
    if (!id) return;
    try {
      await closeCapa.mutateAsync({
        id,
        payload: { signature: { meaning: sig.meaning, reason: sig.reason } },
      });
      notify('CAPA closed with electronic signature', 'success');
      setSigOpen(false);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !capa}
    >
      {capa && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <ClipboardCheck size={22} />
                <span className="mono">{capa.capa_number}</span>
                <StatusBadge status={capa.status} />
              </span>
            }
            subtitle={capa.title}
            breadcrumbs={[{ label: 'CAPA', to: '/capa' }, { label: capa.capa_number }]}
            actions={
              canClose &&
              capa.status !== 'closed' && (
                <button type="button" className="btn btn-primary" onClick={() => setSigOpen(true)}>
                  <ShieldCheck size={16} /> Close & Sign
                </button>
              )
            }
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">8D Problem Solving</div>
                  <StatusBadge status={capa.type} noDot />
                </div>
                <div className="card__body">
                  <EightDStepper steps={capa.eight_d} />
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Summary</div>
                  <StatusBadge status={capa.priority} />
                </div>
                <div className="card__body">
                  <p style={{ marginTop: 0 }}>{capa.description}</p>
                  <DataList
                    items={[
                      { label: 'Type', value: humanize(capa.type) },
                      { label: 'Owner', value: capa.owner },
                      { label: 'Source', value: humanize(capa.source) },
                      { label: 'Reference', value: capa.source_ref ?? '—' },
                      { label: 'Opened', value: formatDate(capa.opened_at) },
                      { label: 'Due', value: formatDate(capa.due_date) },
                      { label: 'Closed', value: formatDate(capa.closed_at) },
                    ]}
                  />
                </div>
              </div>

              {capa.root_cause && (
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Root Cause</div>
                  </div>
                  <div className="card__body">
                    <p style={{ margin: 0 }}>{capa.root_cause}</p>
                  </div>
                </div>
              )}

              <div className="card">
                <div className="card__header">
                  <div className="card__title">Effectiveness Verification</div>
                </div>
                <div className="card__body">
                  {capa.effectiveness ? (
                    <div className="stack">
                      <DataList
                        items={[
                          { label: 'Method', value: capa.effectiveness.method },
                          {
                            label: 'Result',
                            value: <StatusBadge status={capa.effectiveness.result ?? 'pending'} />,
                          },
                          { label: 'Verified By', value: capa.effectiveness.verified_by ?? '—' },
                          { label: 'Verified', value: formatDateTime(capa.effectiveness.verified_at) },
                          { label: 'Due', value: formatDate(capa.effectiveness.due_date) },
                        ]}
                      />
                      <div>
                        <div className="section-title">Criteria</div>
                        <p style={{ margin: 0 }}>{capa.effectiveness.criteria}</p>
                      </div>
                    </div>
                  ) : (
                    <div className="empty-state-sm">Effectiveness check not yet defined.</div>
                  )}
                </div>
              </div>

              {capa.signature && (
                <div className="card">
                  <div className="card__body">
                    <SignatureSummary signature={capa.signature} />
                  </div>
                </div>
              )}

              <AttachmentsCard attachments={capa.attachments} />
            </div>
          </div>

          <SignatureModal
            open={sigOpen}
            title="Close CAPA"
            meaning="Verification of effectiveness"
            submitLabel="Close & Sign"
            loading={closeCapa.isPending}
            onClose={() => setSigOpen(false)}
            onSign={handleSign}
          />
        </>
      )}
    </DetailState>
  );
}
