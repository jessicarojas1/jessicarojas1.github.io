import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { Modal } from './Modal';
import { FormField, Select, TextArea, TextInput } from './FormField';
import { useAuth } from '@/lib/auth';
import type { ElectronicSignature } from '@/types';

export interface SignaturePayload {
  meaning: string;
  reason: string;
  password: string;
}

const DEFAULT_REASONS = [
  'Approval',
  'Review',
  'Disposition authorization',
  'Verification of effectiveness',
  'Release for production',
  'Responsibility / authorship',
];

/**
 * Electronic signature capture reflecting 21 CFR Part 11 §11.200:
 * the signer must declare meaning + reason and re-authenticate with a
 * password before the signed action is committed.
 */
export function SignatureModal({
  open,
  title = 'Electronic Signature',
  meaning,
  meaningOptions,
  submitLabel = 'Sign & Submit',
  loading = false,
  onClose,
  onSign,
}: {
  open: boolean;
  title?: string;
  meaning?: string;
  meaningOptions?: string[];
  submitLabel?: string;
  loading?: boolean;
  onClose: () => void;
  onSign: (payload: SignaturePayload) => void | Promise<void>;
}) {
  const { user, reauthenticate } = useAuth();
  const [reason, setReason] = useState('');
  const [selectedMeaning, setSelectedMeaning] = useState(meaning ?? DEFAULT_REASONS[0]);
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);

  const reasons = meaningOptions ?? DEFAULT_REASONS;

  const reset = () => {
    setReason('');
    setPassword('');
    setError(null);
    setVerifying(false);
  };

  const handleClose = () => {
    reset();
    onClose();
  };

  const handleSubmit = async () => {
    setError(null);
    if (!reason.trim()) {
      setError('A reason for this signature is required.');
      return;
    }
    if (!password) {
      setError('Re-enter your password to authenticate this signature.');
      return;
    }
    setVerifying(true);
    const ok = await reauthenticate(password);
    setVerifying(false);
    if (!ok) {
      setError('Authentication failed. The signature was not applied.');
      setPassword('');
      return;
    }
    await onSign({ meaning: meaning ?? selectedMeaning, reason: reason.trim(), password });
    reset();
  };

  const busy = loading || verifying;

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={
        <span className="row" style={{ gap: 8 }}>
          <ShieldCheck size={18} /> {title}
        </span>
      }
      size="sm"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={handleSubmit} disabled={busy}>
            {busy ? <span className="spinner" /> : submitLabel}
          </button>
        </>
      }
    >
      <div className="alert alert--info" style={{ marginBottom: 16 }}>
        <ShieldCheck size={16} />
        <span>
          You are applying a legally-binding electronic signature as{' '}
          <strong>{user?.full_name}</strong> ({user?.username}). This action is recorded in the
          audit trail per 21 CFR Part 11.
        </span>
      </div>

      {meaning ? (
        <FormField label="Meaning of signature">
          <TextInput value={meaning} readOnly />
        </FormField>
      ) : (
        <FormField label="Meaning of signature" htmlFor="sig-meaning" required>
          <Select
            id="sig-meaning"
            value={selectedMeaning}
            onChange={(e) => setSelectedMeaning(e.target.value)}
          >
            {reasons.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </Select>
        </FormField>
      )}

      <FormField label="Reason / comments" htmlFor="sig-reason" required>
        <TextArea
          id="sig-reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="State the basis for this signed action…"
          rows={3}
        />
      </FormField>

      <FormField
        label="Confirm password"
        htmlFor="sig-password"
        required
        error={error ?? undefined}
      >
        <TextInput
          id="sig-password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="••••••••"
        />
      </FormField>
    </Modal>
  );
}

/** Render a recorded signature inline. */
export function SignatureSummary({ signature }: { signature: ElectronicSignature }) {
  return (
    <div className="alert alert--success">
      <ShieldCheck size={16} />
      <div>
        <strong>{signature.signed_by_name}</strong> — {signature.meaning}
        <div className="text-sm">{signature.reason}</div>
        <div className="text-sm muted">{new Date(signature.signed_at).toLocaleString()}</div>
      </div>
    </div>
  );
}
