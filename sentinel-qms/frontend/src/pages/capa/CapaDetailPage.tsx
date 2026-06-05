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
import { SignatureModal, type SignaturePayload } from '@/components/SignatureModal';
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
    if (!id || !capa) return;
    try {
      await closeCapa.mutateAsync({
        id,
        payload: {
          d8_closure: capa.d8_closure ?? sig.reason,
          signature: { meaning: sig.meaning, reason: sig.reason, password: sig.password },
        },
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
                  <StatusBadge status={capa.capa_type} noDot />
                </div>
                <div className="card__body">
                  <EightDStepper capa={capa} />
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Summary</div>
                </div>
                <div className="card__body">
                  <p style={{ marginTop: 0 }}>{capa.d2_problem_description}</p>
                  <DataList
                    items={[
                      { label: 'Type', value: humanize(capa.capa_type) },
                      { label: 'Owner', value: capa.owner_id ?? '—' },
                      { label: 'Root Cause Method', value: capa.root_cause_method ?? '—' },
                      { label: 'Due', value: formatDate(capa.due_date) },
                      { label: 'Closed', value: formatDate(capa.closed_at) },
                    ]}
                  />
                </div>
              </div>

              {capa.d4_root_cause && (
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Root Cause</div>
                  </div>
                  <div className="card__body">
                    <p style={{ margin: 0 }}>{capa.d4_root_cause}</p>
                  </div>
                </div>
              )}

              <div className="card">
                <div className="card__header">
                  <div className="card__title">Effectiveness Verification</div>
                </div>
                <div className="card__body">
                  <DataList
                    items={[
                      {
                        label: 'Verified',
                        value: <StatusBadge status={capa.effectiveness_verified ? 'verified' : 'pending'} />,
                      },
                      { label: 'Verified By', value: capa.effectiveness_verified_by ?? '—' },
                      { label: 'Verified At', value: formatDateTime(capa.effectiveness_verified_at) },
                    ]}
                  />
                  {capa.effectiveness_notes && (
                    <div>
                      <div className="section-title">Notes</div>
                      <p style={{ margin: 0 }}>{capa.effectiveness_notes}</p>
                    </div>
                  )}
                </div>
              </div>

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
