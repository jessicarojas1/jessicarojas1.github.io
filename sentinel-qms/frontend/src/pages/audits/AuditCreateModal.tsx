import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { auditHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { Audit } from '@/types';

const AUDIT_TYPES = ['internal', 'external', 'supplier', 'certification', 'process'] as const;

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  audit_type: z.enum(AUDIT_TYPES),
  standard: z.string().optional(),
  scope: z.string().optional(),
  auditee_area: z.string().optional(),
  lead_auditor_id: z.string().optional(),
  planned_date: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function AuditCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = auditHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { audit_type: 'internal' },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const leadId = values.lead_auditor_id?.trim();
      const payload: Omit<Partial<Audit>, 'lead_auditor_id'> & { lead_auditor_id?: number } = {
        title: values.title,
        audit_type: values.audit_type,
        standard: values.standard || undefined,
        scope: values.scope || undefined,
        auditee_area: values.auditee_area || undefined,
        lead_auditor_id: leadId ? Number(leadId) : undefined,
        planned_date: values.planned_date || undefined,
      };
      // lead_auditor_id is a numeric FK on the wire; Audit types it as a string, so cast.
      const created = (await create.mutateAsync(payload as unknown as Partial<Audit>)) as Audit;
      notify(`Audit ${created.audit_number ?? ''} scheduled`, 'success');
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
      title="Schedule Audit"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Schedule Audit'}
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
        <FormField label="Title" htmlFor="aud-title" required error={errors.title?.message}>
          <TextInput id="aud-title" {...register('title')} placeholder="e.g. ISO 9001 Internal Audit — Production" />
        </FormField>
        <div className="form-grid">
          <FormField label="Type" htmlFor="aud-type" required error={errors.audit_type?.message}>
            <Select id="aud-type" {...register('audit_type')}>
              {AUDIT_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t[0].toUpperCase() + t.slice(1)}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Standard" htmlFor="aud-standard" error={errors.standard?.message}>
            <TextInput id="aud-standard" {...register('standard')} placeholder="e.g. AS9100D" />
          </FormField>
          <FormField label="Planned date" htmlFor="aud-planned" error={errors.planned_date?.message}>
            <TextInput id="aud-planned" type="date" {...register('planned_date')} />
          </FormField>
        </div>
        <div className="form-grid">
          <FormField label="Auditee area" htmlFor="aud-area" error={errors.auditee_area?.message}>
            <TextInput id="aud-area" {...register('auditee_area')} placeholder="Department / process audited" />
          </FormField>
          <FormField label="Lead auditor (user ID)" htmlFor="aud-lead" error={errors.lead_auditor_id?.message}>
            <TextInput id="aud-lead" type="number" {...register('lead_auditor_id')} placeholder="Optional" />
          </FormField>
        </div>
        <FormField label="Scope" htmlFor="aud-scope" error={errors.scope?.message}>
          <TextArea id="aud-scope" rows={3} {...register('scope')} placeholder="Clauses / areas / objectives covered by this audit" />
        </FormField>
      </form>
    </Modal>
  );
}
