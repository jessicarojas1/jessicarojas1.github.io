import { useState } from 'react';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea } from '@/components/FormField';
import { SignatureModal, type SignaturePayload } from '@/components/SignatureModal';
import { ncrHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import type { DispositionType } from '@/types';

const DISPOSITIONS: { value: DispositionType; label: string }[] = [
  { value: 'use_as_is', label: 'Use As-Is' },
  { value: 'rework', label: 'Rework' },
  { value: 'repair', label: 'Repair' },
  { value: 'scrap', label: 'Scrap' },
  { value: 'return', label: 'Return to Supplier' },
];

export function DispositionModal({
  open,
  ncrId,
  onClose,
}: {
  open: boolean;
  ncrId: string;
  onClose: () => void;
}) {
  const { notify } = useToast();
  const disposition = ncrHooks.useAction('dispositions');
  const [type, setType] = useState<DispositionType>('use_as_is');
  const [justification, setJustification] = useState('');
  const [customerApprovalRequired, setCustomerApprovalRequired] = useState(false);
  const [sigOpen, setSigOpen] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const reset = () => {
    setType('use_as_is');
    setJustification('');
    setCustomerApprovalRequired(false);
    setFormError(null);
  };

  const proceedToSign = () => {
    if (justification.trim().length < 5) {
      setFormError('A disposition justification is required.');
      return;
    }
    setFormError(null);
    setSigOpen(true);
  };

  const handleSign = async (sig: SignaturePayload) => {
    try {
      await disposition.mutateAsync({
        id: ncrId,
        payload: {
          disposition_type: type,
          justification: justification.trim(),
          customer_approval_required: customerApprovalRequired,
          signature: { meaning: sig.meaning, reason: sig.reason, password: sig.password },
        },
      });
      notify('Disposition recorded and signed', 'success');
      setSigOpen(false);
      reset();
      onClose();
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <>
      <Modal
        open={open && !sigOpen}
        onClose={() => {
          reset();
          onClose();
        }}
        title="MRB Disposition"
        footer={
          <>
            <button type="button" className="btn" onClick={onClose}>
              Cancel
            </button>
            <button type="button" className="btn btn-primary" onClick={proceedToSign}>
              Continue to Signature
            </button>
          </>
        }
      >
        <FormField label="Disposition" htmlFor="disp-type" required>
          <Select
            id="disp-type"
            value={type}
            onChange={(e) => setType(e.target.value as DispositionType)}
          >
            {DISPOSITIONS.map((d) => (
              <option key={d.value} value={d.value}>
                {d.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField
          label="Justification"
          htmlFor="disp-just"
          required
          error={formError ?? undefined}
          hint="Document the engineering basis for this disposition."
        >
          <TextArea
            id="disp-just"
            rows={4}
            value={justification}
            onChange={(e) => setJustification(e.target.value)}
          />
        </FormField>
        <label className="row text-sm" style={{ gap: 8, cursor: 'pointer' }}>
          <input
            type="checkbox"
            checked={customerApprovalRequired}
            onChange={(e) => setCustomerApprovalRequired(e.target.checked)}
          />
          Customer approval required
        </label>
      </Modal>

      <SignatureModal
        open={sigOpen}
        title="Sign Disposition"
        meaning="Disposition authorization"
        submitLabel="Sign & Record"
        loading={disposition.isPending}
        onClose={() => setSigOpen(false)}
        onSign={handleSign}
      />
    </>
  );
}
