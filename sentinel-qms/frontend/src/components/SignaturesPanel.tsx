import { PenLine } from 'lucide-react';
import { useSignatures } from '@/hooks';
import { formatDateTime } from '@/lib/format';

interface SignaturesPanelProps {
  /** Entity type matching how the signature was recorded (e.g. "capa"). */
  entityType: string;
  entityId?: string | number | null;
}

/**
 * Read-only manifest of the 21 CFR Part 11 electronic signatures captured on a
 * record (signer, meaning, reason, timestamp, tamper-evident hash).
 */
export function SignaturesPanel({ entityType, entityId }: SignaturesPanelProps) {
  const { data, isLoading } = useSignatures(entityType, entityId);

  if (isLoading) {
    return (
      <div className="esig-empty">
        <span className="spinner" /> Loading…
      </div>
    );
  }
  if (!data || data.length === 0) {
    return <div className="empty-state-sm">No electronic signatures recorded.</div>;
  }

  return (
    <ul className="esig-list">
      {data.map((s) => (
        <li key={s.id} className="esig-item">
          <div className="esig-item__head">
            <span className="esig-meaning">{s.meaning}</span>
            <span className="esig-signer">{s.signer_name}</span>
            <span className="esig-time">{formatDateTime(s.signed_at)}</span>
          </div>
          {s.reason && <div className="esig-reason">{s.reason}</div>}
          {s.signed_hash && (
            <div className="esig-hash" title={s.signed_hash}>
              SHA-256 {s.signed_hash.slice(0, 16)}…
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}

export function SignaturesPanelCard(props: SignaturesPanelProps) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">
          <PenLine size={16} /> Electronic Signatures
        </div>
      </div>
      <div className="card__body">
        <SignaturesPanel {...props} />
      </div>
    </div>
  );
}
