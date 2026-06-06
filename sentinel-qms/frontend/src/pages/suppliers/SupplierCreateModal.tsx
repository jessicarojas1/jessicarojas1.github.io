import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { supplierHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { Supplier } from '@/types';

const schema = z.object({
  name: z.string().min(2, 'Supplier name is required'),
  status: z.enum(['prospective', 'approved', 'conditional', 'probation', 'disqualified']),
  cage_code: z.string().optional(),
  duns_number: z.string().optional(),
  certification: z.string().optional(),
  cert_expiry: z.string().optional(),
  country: z.string().optional(),
  notes: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function SupplierCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = supplierHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { status: 'prospective' },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const payload: Partial<Supplier> = {
        name: values.name,
        status: values.status,
        cage_code: values.cage_code || undefined,
        duns_number: values.duns_number || undefined,
        certification: values.certification || undefined,
        cert_expiry: values.cert_expiry || undefined,
        country: values.country || undefined,
        notes: values.notes || undefined,
      };
      const created = (await create.mutateAsync(payload)) as Supplier;
      notify(`Supplier ${created.supplier_code ?? ''} created`, 'success');
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
      title="New Supplier"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Create Supplier'}
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
        <FormField label="Supplier name" htmlFor="sup-name" required error={errors.name?.message}>
          <TextInput id="sup-name" {...register('name')} placeholder="e.g. Acme Precision Machining" />
        </FormField>
        <div className="form-grid">
          <FormField label="ASL status" htmlFor="sup-status" required error={errors.status?.message}>
            <Select id="sup-status" {...register('status')}>
              <option value="prospective">Prospective</option>
              <option value="approved">Approved</option>
              <option value="conditional">Conditional</option>
              <option value="probation">Probation</option>
              <option value="disqualified">Disqualified</option>
            </Select>
          </FormField>
          <FormField label="Country" htmlFor="sup-country" error={errors.country?.message}>
            <TextInput id="sup-country" {...register('country')} placeholder="e.g. USA" />
          </FormField>
          <FormField label="CAGE code" htmlFor="sup-cage" error={errors.cage_code?.message}>
            <TextInput id="sup-cage" {...register('cage_code')} placeholder="5-char NATO code" />
          </FormField>
          <FormField label="DUNS number" htmlFor="sup-duns" error={errors.duns_number?.message}>
            <TextInput id="sup-duns" {...register('duns_number')} />
          </FormField>
          <FormField label="Certification" htmlFor="sup-cert" error={errors.certification?.message}>
            <TextInput id="sup-cert" {...register('certification')} placeholder="AS9100, ISO 9001…" />
          </FormField>
          <FormField label="Certification expiry" htmlFor="sup-cert-exp" error={errors.cert_expiry?.message}>
            <TextInput id="sup-cert-exp" type="date" {...register('cert_expiry')} />
          </FormField>
        </div>
        <FormField label="Notes" htmlFor="sup-notes" error={errors.notes?.message}>
          <TextArea id="sup-notes" rows={2} {...register('notes')} />
        </FormField>
      </form>
    </Modal>
  );
}
