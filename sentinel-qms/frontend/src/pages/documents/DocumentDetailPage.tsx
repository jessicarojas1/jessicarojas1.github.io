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
import { StatusBadge } from '@/components/StatusBadge';
import { AttachmentsCard, DataList, DetailState } from '@/components/detail';
import { SignatureModal, SignatureSummary, type SignaturePayload } from '@/components/SignatureModal';

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { notify } = useToast();
  const { data: doc, isLoading, error } = documentHooks.useDetail(id);
  const approve = documentHooks.useAction('approve');
  const [sigOpen, setSigOpen] = useState(false);

  const canApprove = can(user?.roles, 'documents.approve');
  const pendingApproval = doc?.status === 'in_review' || doc?.status === 'draft';

  const handleSign = async (sig: SignaturePayload) => {
    if (!id) return;
    try {
      await approve.mutateAsync({
        id,
        payload: { signature: { meaning: sig.meaning, reason: sig.reason } },
      });
      notify('Document approved and released', 'success');
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
                <span className="mono">{doc.doc_number}</span>
                <StatusBadge status={doc.status} />
              </span>
            }
            subtitle={`${doc.title} · Rev ${doc.current_revision}`}
            breadcrumbs={[{ label: 'Documents', to: '/documents' }, { label: doc.doc_number }]}
            actions={
              canApprove &&
              pendingApproval && (
                <button type="button" className="btn btn-primary" onClick={() => setSigOpen(true)}>
                  <Stamp size={16} /> Approve & Release
                </button>
              )
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
                      <th>Summary</th>
                      <th>Status</th>
                      <th>Author</th>
                      <th>Approved By</th>
                      <th>Effective</th>
                    </tr>
                  </thead>
                  <tbody>
                    {doc.revisions?.length ? (
                      doc.revisions.map((rev) => (
                        <tr key={rev.id}>
                          <td className="mono">{rev.revision}</td>
                          <td>{rev.summary}</td>
                          <td>
                            <StatusBadge status={rev.status} />
                          </td>
                          <td>{rev.author}</td>
                          <td>{rev.approved_by ?? '—'}</td>
                          <td>{formatDate(rev.effective_date)}</td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={6}>
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
                      { label: 'Category', value: doc.category ?? '—' },
                      { label: 'Owner', value: doc.owner },
                      { label: 'Department', value: doc.department ?? '—' },
                      { label: 'Current Rev', value: doc.current_revision },
                      { label: 'Effective', value: formatDate(doc.effective_date) },
                      { label: 'Next Review', value: formatDate(doc.next_review_date) },
                    ]}
                  />
                </div>
              </div>
              {doc.revisions?.find((r) => r.signature)?.signature && (
                <div className="card">
                  <div className="card__body">
                    <SignatureSummary
                      signature={doc.revisions.find((r) => r.signature)!.signature!}
                    />
                  </div>
                </div>
              )}
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
