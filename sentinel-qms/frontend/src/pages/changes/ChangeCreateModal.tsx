import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { changeHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { ChangeRequest } from '@/types';

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  change_type: z.enum(['ecn', 'eco', 'deviation', 'waiver']),
  priority: z.enum(['low', 'medium', 'high', 'emergency']),
  description: z.string().min(5, 'Describe the change'),
  reason: z.string().optional(),
  affected_items: z.string().optional(),
  impact_analysis: z.string().optional(),
  target_date: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function ChangeCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = changeHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { change_type: 'ecn', priority: 'medium' },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const payload: Partial<ChangeRequest> = {
        title: values.title,
        change_type: values.change_type,
        priority: values.priority,
        description: values.description,
        reason: values.reason || undefined,
        affected_items: values.affected_items || undefined,
        impact_analysis: values.impact_analysis || undefined,
        target_date: values.target_date || undefined,
      };
      const created = (await create.mutateAsync(payload)) as ChangeRequest;
      notify(`Change ${created.change_number ?? ''} created`, 'success');
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
      title="New Change Order"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Create Change'}
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
        <FormField label="Title" htmlFor="chg-title" required error={errors.title?.message}>
          <TextInput id="chg-title" {...register('title')} placeholder="Short description of the change" />
        </FormField>
        <div className="form-grid">
          <FormField label="Type" htmlFor="chg-type" required error={errors.change_type?.message}>
            <Select id="chg-type" {...register('change_type')}>
              <option value="ecn">ECN — Engineering Change Notice</option>
              <option value="eco">ECO — Engineering Change Order</option>
              <option value="deviation">Deviation</option>
              <option value="waiver">Waiver</option>
            </Select>
          </FormField>
          <FormField label="Priority" htmlFor="chg-priority" required error={errors.priority?.message}>
            <Select id="chg-priority" {...register('priority')}>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="emergency">Emergency</option>
            </Select>
          </FormField>
          <FormField label="Target date" htmlFor="chg-target" error={errors.target_date?.message}>
            <TextInput id="chg-target" type="date" {...register('target_date')} />
          </FormField>
        </div>
        <FormField label="Description" htmlFor="chg-desc" required error={errors.description?.message}>
          <TextArea id="chg-desc" rows={3} {...register('description')} />
        </FormField>
        <FormField label="Reason" htmlFor="chg-reason" error={errors.reason?.message}>
          <TextArea id="chg-reason" rows={2} {...register('reason')} placeholder="Why this change is needed" />
        </FormField>
        <FormField label="Affected items" htmlFor="chg-affected" error={errors.affected_items?.message}>
          <TextArea id="chg-affected" rows={2} {...register('affected_items')} placeholder="Part / document numbers impacted" />
        </FormField>
        <FormField label="Impact analysis" htmlFor="chg-impact" error={errors.impact_analysis?.message}>
          <TextArea id="chg-impact" rows={2} {...register('impact_analysis')} />
        </FormField>
      </form>
    </Modal>
  );
}
