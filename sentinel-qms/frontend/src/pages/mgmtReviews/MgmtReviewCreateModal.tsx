import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { mgmtReviewHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, TextArea, TextInput } from '@/components/FormField';
import type { MgmtReview } from '@/types';

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  meeting_date: z.string().optional(),
  attendees: z.string().optional(),
  summary: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function MgmtReviewCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = mgmtReviewHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
  });

  const submit = handleSubmit(async (values) => {
    try {
      const payload: Partial<MgmtReview> = {
        title: values.title,
        meeting_date: values.meeting_date || undefined,
        attendees: values.attendees || undefined,
        summary: values.summary || undefined,
      };
      const created = (await create.mutateAsync(payload)) as MgmtReview;
      notify(`Management review ${created.review_number ?? ''} created`, 'success');
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
      title="New Management Review"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Create Review'}
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
        <FormField label="Title" htmlFor="mr-title" required error={errors.title?.message}>
          <TextInput id="mr-title" {...register('title')} placeholder="e.g. Q2 2026 Management Review" />
        </FormField>
        <FormField label="Meeting date" htmlFor="mr-date" error={errors.meeting_date?.message}>
          <TextInput id="mr-date" type="date" {...register('meeting_date')} />
        </FormField>
        <FormField label="Attendees" htmlFor="mr-attendees" error={errors.attendees?.message}>
          <TextArea id="mr-attendees" rows={2} {...register('attendees')} placeholder="Names / roles present" />
        </FormField>
        <FormField label="Scope / summary" htmlFor="mr-summary" error={errors.summary?.message}>
          <TextArea id="mr-summary" rows={3} {...register('summary')} placeholder="Scope and objectives of the review" />
        </FormField>
      </form>
    </Modal>
  );
}
