import { CheckCircle2, FileCheck } from 'lucide-react';
import { useAcknowledgeDocument, useDocumentAcknowledgements } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';

/**
 * Read-and-acknowledge panel for a controlled document. Shows whether the
 * current user has acknowledged the active revision, lets them attest, and lists
 * everyone who has acknowledged (awareness / training evidence).
 */
export function AcknowledgementCard({
  documentId,
  revision,
  approved,
}: {
  documentId: number;
  revision: string | null | undefined;
  approved: boolean;
}) {
  const { user } = useAuth();
  const { data: acks = [], isLoading } = useDocumentAcknowledgements(documentId);
  const acknowledge = useAcknowledgeDocument(documentId);
  const { notify } = useToast();

  const rev = revision ?? '';
  const mine = acks.find((a) => String(a.user_id) === user?.id && a.revision === rev);

  const onAck = async () => {
    try {
      await acknowledge.mutateAsync(null);
      notify('Document acknowledged', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title row" style={{ gap: 8 }}>
          <FileCheck size={16} /> Read &amp; Acknowledge
        </div>
      </div>
      <div className="card__body">
        {!approved ? (
          <div className="empty-state-sm">
            Acknowledgement opens once the document is approved.
          </div>
        ) : mine ? (
          <div className="alert alert--success" style={{ marginBottom: acks.length ? 12 : 0 }}>
            <CheckCircle2 size={16} />
            <span>
              You acknowledged revision <strong>{rev || '—'}</strong> on{' '}
              {formatDateTime(mine.acknowledged_at)}.
            </span>
          </div>
        ) : (
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={onAck}
            disabled={acknowledge.isPending}
            style={{ marginBottom: acks.length ? 12 : 0 }}
          >
            {acknowledge.isPending ? <span className="spinner" /> : <CheckCircle2 size={16} />} I
            have read and acknowledge revision {rev || 'this document'}
          </button>
        )}

        {isLoading ? (
          <div className="empty-state-sm">
            <span className="spinner" /> Loading…
          </div>
        ) : acks.length > 0 ? (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Revision</th>
                  <th>Acknowledged</th>
                </tr>
              </thead>
              <tbody>
                {acks.map((a) => (
                  <tr key={a.id}>
                    <td>{a.user_name}</td>
                    <td className="mono">{a.revision || '—'}</td>
                    <td>{formatDateTime(a.acknowledged_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </div>
    </div>
  );
}
