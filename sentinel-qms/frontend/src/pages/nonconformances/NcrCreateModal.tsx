import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { ncrHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { Nonconformance } from '@/types';

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  description: z.string().min(5, 'Describe the nonconformance'),
  severity: z.enum(['minor', 'major', 'critical']),
  source: z.string().min(1, 'Source is required'),
  part_number: z.string().optional(),
  lot_number: z.string().optional(),
  quantity_affected: z.coerce.number().int().nonnegative().optional(),
});
type FormValues = z.infer<typeof schema>;

export function NcrCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = ncrHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { severity: 'minor', source: 'production' },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const created = (await create.mutateAsync(values as Partial<Nonconformance>)) as Nonconformance;
      notify(`NCR ${created.ncr_number ?? ''} created`, 'success');
      reset();
      onCreated(created.id);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  const handleClose = () => {
    reset();
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title="New Nonconformance"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Create NCR'}
          </button>
        </>
      }
    >
      {create.isError && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(create.error)}</span>
        </div>
      )}
      <form onSubmit={submit} noValidate>
        <FormField label="Title" htmlFor="ncr-title" required error={errors.title?.message}>
          <TextInput id="ncr-title" {...register('title')} placeholder="Short description of the issue" />
        </FormField>
        <FormField
          label="Description"
          htmlFor="ncr-desc"
          required
          error={errors.description?.message}
        >
          <TextArea id="ncr-desc" rows={3} {...register('description')} />
        </FormField>
        <div className="form-grid">
          <FormField label="Severity" htmlFor="ncr-sev" required error={errors.severity?.message}>
            <Select id="ncr-sev" {...register('severity')}>
              <option value="minor">Minor</option>
              <option value="major">Major</option>
              <option value="critical">Critical</option>
            </Select>
          </FormField>
          <FormField label="Source" htmlFor="ncr-source" required error={errors.source?.message}>
            <Select id="ncr-source" {...register('source')}>
              <option value="production">Production</option>
              <option value="receiving">Receiving Inspection</option>
              <option value="in_process">In-Process</option>
              <option value="final">Final Inspection</option>
              <option value="customer">Customer Return</option>
              <option value="audit">Audit</option>
            </Select>
          </FormField>
          <FormField label="Part number" htmlFor="ncr-part">
            <TextInput id="ncr-part" {...register('part_number')} placeholder="e.g. 12345-001" />
          </FormField>
          <FormField label="Lot / serial" htmlFor="ncr-lot">
            <TextInput id="ncr-lot" {...register('lot_number')} />
          </FormField>
          <FormField
            label="Quantity affected"
            htmlFor="ncr-qty"
            error={errors.quantity_affected?.message}
          >
            <TextInput id="ncr-qty" type="number" min={0} {...register('quantity_affected')} />
          </FormField>
        </div>
      </form>
    </Modal>
  );
}
