import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { GitPullRequestArrow, Stamp } from 'lucide-react';
import { changeHooks } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';
import { SignatureModal, SignatureSummary, type SignaturePayload } from '@/components/SignatureModal';

export default function ChangeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { notify } = useToast();
  const { data: chg, isLoading, error } = changeHooks.useDetail(id);
  const approve = changeHooks.useAction('approve');
  const [sigOpen, setSigOpen] = useState(false);

  const canApprove = can(user?.roles, 'changes.approve');
  const pending = chg?.status === 'submitted' || chg?.status === 'in_review';

  const handleSign = async (sig: SignaturePayload) => {
    if (!id) return;
    try {
      await approve.mutateAsync({ id, payload: { signature: { meaning: sig.meaning, reason: sig.reason } } });
      notify('Change approved', 'success');
      setSigOpen(false);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !chg}
    >
      {chg && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <GitPullRequestArrow size={22} />
                <span className="mono">{chg.change_number}</span>
                <StatusBadge status={chg.status} />
              </span>
            }
            subtitle={chg.title}
            breadcrumbs={[{ label: 'Change Control', to: '/changes' }, { label: chg.change_number }]}
            actions={
              canApprove && pending && (
                <button type="button" className="btn btn-primary" onClick={() => setSigOpen(true)}>
                  <Stamp size={16} /> Approve & Sign
                </button>
              )
            }
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Change Description</div></div>
                <div className="card__body">
                  <p style={{ marginTop: 0 }}>{chg.description}</p>
                  <div className="section-title">Reason for Change</div>
                  <p style={{ margin: 0 }}>{chg.reason}</p>
                </div>
              </div>
              {chg.affected_items?.length ? (
                <div className="card">
                  <div className="card__header"><div className="card__title">Affected Items</div></div>
                  <div className="card__body">
                    <div className="tag-list">
                      {chg.affected_items.map((i) => <span key={i} className="pill mono">{i}</span>)}
                    </div>
                  </div>
                </div>
              ) : null}
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Details</div><StatusBadge status={chg.priority} /></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Type', value: chg.type.toUpperCase() },
                      { label: 'Originator', value: chg.originator },
                      { label: 'CCB Required', value: chg.ccb_required ? 'Yes' : 'No' },
                      { label: 'Priority', value: humanize(chg.priority) },
                      { label: 'Submitted', value: formatDate(chg.submitted_at) },
                      { label: 'Approved', value: formatDate(chg.approved_at) },
                      { label: 'Effective', value: formatDate(chg.effective_date) },
                    ]}
                  />
                </div>
              </div>
              {chg.signature && (
                <div className="card"><div className="card__body"><SignatureSummary signature={chg.signature} /></div></div>
              )}
            </div>
          </div>

          <SignatureModal
            open={sigOpen}
            title="Approve Change"
            meaning="Approval"
            submitLabel="Approve & Sign"
            loading={approve.isPending}
            onClose={() => setSigOpen(false)}
            onSign={handleSign}
          />
        </>
      )}
    </DetailState>
  );
}
