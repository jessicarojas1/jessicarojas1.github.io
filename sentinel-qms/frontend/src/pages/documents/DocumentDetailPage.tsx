import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { FileText, Stamp } from 'lucide-react';
import { documentHooks } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { StatusBadge } from '@/components/StatusBadge';
import { AttachmentsCard, DataList, DetailState } from '@/components/detail';
import { SignatureModal, type SignaturePayload } from '@/components/SignatureModal';

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { notify } = useToast();
  const { data: doc, isLoading, error } = documentHooks.useDetail(id);
  // Approval is performed per-revision: POST /documents/revisions/{revision_id}/approve
  const approve = documentHooks.useAction('approve');
  const [sigOpen, setSigOpen] = useState(false);

  const canApprove = can(user?.roles, 'documents.approve');
  const pendingRevision = doc?.revisions?.find(
    (r) => r.status === 'in_review' || r.status === 'draft',
  );
  const pendingApproval = Boolean(pendingRevision);

  const handleSign = async (sig: SignaturePayload) => {
    if (!pendingRevision) return;
    try {
      await approve.mutateAsync({
        id: `revisions/${pendingRevision.id}`,
        payload: {
          decision: 'approved',
          signature: { meaning: sig.meaning, reason: sig.reason, password: sig.password },
        },
      });
      notify('Document revision approved', 'success');
      setSigOpen(false);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !doc}
    >
      {doc && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <FileText size={22} />
                <span className="mono">{doc.document_number}</span>
                <StatusBadge status={doc.status} />
              </span>
            }
            subtitle={`${doc.title} · Rev ${doc.current_revision}`}
            breadcrumbs={[{ label: 'Documents', to: '/documents' }, { label: doc.document_number }]}
            actions={
              <>
                <PrintButton />
                {canApprove && pendingApproval && (
                  <button type="button" className="btn btn-primary" onClick={() => setSigOpen(true)}>
                    <Stamp size={16} /> Approve & Release
                  </button>
                )}
              </>
            }
          />

          <div className="detail-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Revision History</div>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Rev</th>
                      <th>Change Summary</th>
                      <th>Status</th>
                      <th>Effective</th>
                      <th>Created</th>
                    </tr>
                  </thead>
                  <tbody>
                    {doc.revisions?.length ? (
                      doc.revisions.map((rev) => (
                        <tr key={rev.id}>
                          <td className="mono">{rev.revision}</td>
                          <td>{rev.change_summary ?? '—'}</td>
                          <td>
                            <StatusBadge status={rev.status} />
                          </td>
                          <td>{formatDate(rev.effective_date)}</td>
                          <td>{formatDate(rev.created_at)}</td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={5}>
                          <div className="empty-state-sm">No revision history.</div>
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header">
                  <div className="card__title">Document</div>
                </div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Type', value: doc.doc_type },
                      { label: 'Owner', value: doc.owner_id ?? '—' },
                      { label: 'AS9100 Clause', value: doc.as9100_clause ?? '—' },
                      { label: 'Current Rev', value: doc.current_revision ?? '—' },
                      { label: 'Effective', value: formatDate(doc.effective_date) },
                      { label: 'Next Review', value: formatDate(doc.next_review_date) },
                    ]}
                  />
                </div>
              </div>
              <AttachmentsCard attachments={doc.attachments} />
            </div>
          </div>

          <SignatureModal
            open={sigOpen}
            title="Approve Document"
            meaning="Approval"
            submitLabel="Approve & Release"
            loading={approve.isPending}
            onClose={() => setSigOpen(false)}
            onSign={handleSign}
          />
        </>
      )}
    </DetailState>
  );
}
