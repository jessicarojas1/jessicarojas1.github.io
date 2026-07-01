import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import {
  useCreateRetentionPolicy,
  useUpdateRetentionPolicy,
} from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { RetentionPolicy } from '@/types';

const CATEGORIES = [
  'quality_records',
  'design_records',
  'supplier_records',
  'calibration_records',
  'training_records',
  'audit_records',
  'capa_records',
  'contract_records',
  'inspection_records',
  'other',
] as const;
const TRIGGERS = [
  'creation',
  'closure',
  'delivery',
  'contract_end',
  'obsolescence',
  'superseded',
] as const;
const ACTIONS = ['review', 'archive', 'destroy', 'permanent'] as const;
const STATUSES = ['draft', 'active', 'superseded'] as const;

const label = (s: string) => s.replace(/_/g, ' ');

const schema = z.object({
  title: z.string().min(1, 'Title is required'),
  record_category: z.enum(CATEGORIES),
  retention_trigger: z.enum(TRIGGERS),
  retention_years: z.string().optional(),
  disposition_action: z.enum(ACTIONS),
  legal_hold: z.boolean(),
  authority_reference: z.string().optional(),
  status: z.enum(STATUSES),
  notes: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

const toDefaults = (p?: RetentionPolicy): FormValues => ({
  title: p?.title ?? '',
  record_category: p?.record_category ?? 'other',
  retention_trigger: p?.retention_trigger ?? 'creation',
  retention_years:
    p?.retention_years == null ? '' : String(p.retention_years),
  disposition_action: p?.disposition_action ?? 'review',
  legal_hold: p?.legal_hold ?? false,
  authority_reference: p?.authority_reference ?? '',
  status: p?.status ?? 'draft',
  notes: p?.notes ?? '',
});

export function RetentionFormModal({
  open,
  policy,
  onClose,
  onSaved,
}: {
  open: boolean;
  /** Present => edit mode; absent => create mode. */
  policy?: RetentionPolicy;
  onClose: () => void;
  onSaved: (id: number) => void;
}) {
  const { notify } = useToast();
  const create = useCreateRetentionPolicy();
  const update = useUpdateRetentionPolicy(policy?.id ?? 0);
  const busy = create.isPending || update.isPending;
  const isEdit = Boolean(policy);

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: toDefaults(policy),
  });

  // Re-seed the form when the target policy changes (edit vs create).
  useEffect(() => {
    if (open) reset(toDefaults(policy));
  }, [open, policy, reset]);

  const disposition = watch('disposition_action');

  const submit = handleSubmit(async (values) => {
    const years =
      values.retention_years && values.retention_years.trim() !== ''
        ? Number(values.retention_years)
        : null;
    const payload: Partial<RetentionPolicy> = {
      title: values.title,
      record_category: values.record_category,
      retention_trigger: values.retention_trigger,
      retention_years: years,
      disposition_action: values.disposition_action,
      legal_hold: values.legal_hold,
      authority_reference: values.authority_reference || null,
      notes: values.notes || null,
    };
    try {
      if (isEdit && policy) {
        const saved = await update.mutateAsync({ ...payload, status: values.status });
        notify(`Policy ${saved.policy_number} saved`, 'success');
        onSaved(saved.id);
      } else {
        const saved = await create.mutateAsync(payload);
        notify(`Policy ${saved.policy_number} created`, 'success');
        onSaved(saved.id);
      }
      reset(toDefaults());
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  const handleClose = () => {
    reset(toDefaults(policy));
    onClose();
  };

  const error = create.isError ? create.error : update.isError ? update.error : null;

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={isEdit ? 'Edit Retention Policy' : 'New Retention Policy'}
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={busy}>
            {busy ? <span className="spinner" /> : isEdit ? 'Save Policy' : 'Create Policy'}
          </button>
        </>
      }
    >
      {error && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(error)}</span>
        </div>
      )}
      <p className="text-sm muted" style={{ marginTop: 0 }}>
        This records a retention <em>schedule</em>. The disposition action is a scheduled,
        manually-performed step — records are never destroyed automatically.
      </p>
      <form onSubmit={submit} noValidate>
        <FormField label="Title" htmlFor="ret-title" required error={errors.title?.message}>
          <TextInput id="ret-title" {...register('title')} placeholder="e.g. Quality records" />
        </FormField>
        <div className="form-grid">
          <FormField label="Record category" htmlFor="ret-cat" required>
            <Select id="ret-cat" {...register('record_category')}>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {label(c)}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Retention trigger" htmlFor="ret-trigger" required>
            <Select id="ret-trigger" {...register('retention_trigger')}>
              {TRIGGERS.map((t) => (
                <option key={t} value={t}>
                  {label(t)}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField
            label="Retention (years)"
            htmlFor="ret-years"
            hint={
              disposition === 'permanent'
                ? 'Leave blank for permanent / indefinite retention.'
                : undefined
            }
            error={errors.retention_years?.message}
          >
            <TextInput
              id="ret-years"
              type="number"
              min={0}
              {...register('retention_years')}
              placeholder="e.g. 7"
            />
          </FormField>
          <FormField label="Disposition action" htmlFor="ret-action" required>
            <Select id="ret-action" {...register('disposition_action')}>
              {ACTIONS.map((a) => (
                <option key={a} value={a}>
                  {label(a)}
                </option>
              ))}
            </Select>
          </FormField>
          {isEdit && (
            <FormField label="Status" htmlFor="ret-status" required>
              <Select id="ret-status" {...register('status')}>
                {STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {label(s)}
                  </option>
                ))}
              </Select>
            </FormField>
          )}
          <FormField label="Authority reference" htmlFor="ret-auth">
            <TextInput
              id="ret-auth"
              {...register('authority_reference')}
              placeholder="e.g. AS9100D 7.5.3 / DFARS 252.204-7012"
            />
          </FormField>
        </div>
        <FormField label="Legal hold" htmlFor="ret-hold" hint="Suspends disposition regardless of retention period.">
          <label className="row" style={{ gap: 8 }}>
            <input id="ret-hold" type="checkbox" {...register('legal_hold')} />
            <span className="text-sm">Place this record class on legal hold</span>
          </label>
        </FormField>
        <FormField label="Notes" htmlFor="ret-notes">
          <TextArea id="ret-notes" rows={3} {...register('notes')} />
        </FormField>
      </form>
    </Modal>
  );
}
