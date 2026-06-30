import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { riskHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { Risk } from '@/types';

const CATEGORIES = [
  'quality',
  'supply_chain',
  'operational',
  'compliance',
  'safety',
  'cybersecurity',
  'program',
] as const;

const STRATEGIES = ['mitigate', 'accept', 'transfer', 'avoid'] as const;

const scale = z.coerce.number().int().min(1).max(10);

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  category: z.enum(CATEGORIES),
  is_opportunity: z.boolean().optional(),
  description: z.string().min(5, 'Describe the risk'),
  severity: scale,
  likelihood: scale,
  detectability: scale,
  treatment_strategy: z.enum(STRATEGIES).optional(),
  treatment_plan: z.string().optional(),
  owner_id: z.string().optional(),
  review_date: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function RiskCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = riskHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      category: 'quality',
      is_opportunity: false,
      severity: 5,
      likelihood: 5,
      detectability: 5,
      treatment_strategy: 'mitigate',
    },
  });

  // Live RPN preview (severity × likelihood × detectability).
  const sev = Number(watch('severity')) || 0;
  const occ = Number(watch('likelihood')) || 0;
  const det = Number(watch('detectability')) || 0;
  const rpn = sev * occ * det;

  const submit = handleSubmit(async (values) => {
    try {
      const ownerId = values.owner_id?.trim();
      const payload: Omit<Partial<Risk>, 'owner_id'> & { owner_id?: number } = {
        title: values.title,
        category: values.category,
        is_opportunity: values.is_opportunity ?? false,
        description: values.description,
        severity: values.severity,
        likelihood: values.likelihood,
        detectability: values.detectability,
        treatment_strategy: values.treatment_strategy,
        treatment_plan: values.treatment_plan || undefined,
        owner_id: ownerId ? Number(ownerId) : undefined,
        review_date: values.review_date || undefined,
      };
      // owner_id is a numeric FK on the wire; Risk types it as a string, so cast.
      const created = (await create.mutateAsync(payload as unknown as Partial<Risk>)) as Risk;
      notify(`Risk ${created.risk_number ?? ''} logged`, 'success');
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
      title="Log Risk"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Log Risk'}
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
        <FormField label="Title" htmlFor="risk-title" required error={errors.title?.message}>
          <TextInput id="risk-title" {...register('title')} placeholder="Short description of the risk" />
        </FormField>
        <label className="checkbox-row" htmlFor="risk-opportunity" style={{ marginTop: 4 }}>
          <input id="risk-opportunity" type="checkbox" className="checkbox" {...register('is_opportunity')} />
          <span>Track as an opportunity (ISO 9001 6.1 — a positive opportunity rather than a risk)</span>
        </label>
        <div className="form-grid">
          <FormField label="Category" htmlFor="risk-category" required error={errors.category?.message}>
            <Select id="risk-category" {...register('category')}>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {c.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase())}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Treatment Strategy" htmlFor="risk-strategy" error={errors.treatment_strategy?.message}>
            <Select id="risk-strategy" {...register('treatment_strategy')}>
              {STRATEGIES.map((s) => (
                <option key={s} value={s}>
                  {s[0].toUpperCase() + s.slice(1)}
                </option>
              ))}
            </Select>
          </FormField>
        </div>
        <FormField label="Description" htmlFor="risk-desc" required error={errors.description?.message}>
          <TextArea id="risk-desc" rows={3} {...register('description')} />
        </FormField>

        <div className="form-grid form-grid--3">
          <FormField label="Severity (1–10)" htmlFor="risk-sev" required error={errors.severity?.message}>
            <TextInput id="risk-sev" type="number" min={1} max={10} {...register('severity')} />
          </FormField>
          <FormField label="Likelihood (1–10)" htmlFor="risk-occ" required error={errors.likelihood?.message}>
            <TextInput id="risk-occ" type="number" min={1} max={10} {...register('likelihood')} />
          </FormField>
          <FormField label="Detectability (1–10)" htmlFor="risk-det" required error={errors.detectability?.message}>
            <TextInput id="risk-det" type="number" min={1} max={10} {...register('detectability')} />
          </FormField>
        </div>

        <div className="risk-rpn-preview">
          <span className="muted">Risk Priority Number (RPN)</span>
          <strong>{rpn || '—'}</strong>
        </div>

        <FormField label="Treatment / Mitigation Plan" htmlFor="risk-plan" error={errors.treatment_plan?.message}>
          <TextArea id="risk-plan" rows={2} {...register('treatment_plan')} placeholder="Planned mitigation actions" />
        </FormField>
        <div className="form-grid">
          <FormField label="Owner (user ID)" htmlFor="risk-owner" error={errors.owner_id?.message}>
            <TextInput id="risk-owner" type="number" {...register('owner_id')} placeholder="Optional" />
          </FormField>
          <FormField label="Review date" htmlFor="risk-review" error={errors.review_date?.message}>
            <TextInput id="risk-review" type="date" {...register('review_date')} />
          </FormField>
        </div>
      </form>
    </Modal>
  );
}
