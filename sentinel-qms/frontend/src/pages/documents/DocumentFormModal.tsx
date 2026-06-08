import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { documentHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { ControlledDocument } from '@/types';
import { DEPARTMENT_OPTIONS, DOC_TYPE_OPTIONS } from './documentOptions';

const schema = z.object({
  title: z.string().min(3, 'Title is required'),
  doc_type: z.enum(['work_instruction', 'policy', 'process', 'procedure', 'form', 'guide']),
  department: z.enum(['ens', 'exec', 'qual', 'ilm', 'ins', 'ts', 'fin', 'ops']).optional().or(z.literal('')),
  version: z.string().optional(),
  current_revision: z.string().optional(),
  next_review_date: z.string().optional(),
  owner_id: z.string().optional(),
  description: z.string().optional(),
  purpose: z.string().optional(),
  scope: z.string().optional(),
  definitions: z.string().optional(),
  responsibilities: z.string().optional(),
  detail: z.string().optional(),
  revision_history: z.string().optional(),
  appendix: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
  title: '',
  doc_type: 'procedure',
  department: '',
  version: '',
  current_revision: '',
  next_review_date: '',
  owner_id: '',
  description: '',
  purpose: '',
  scope: '',
  definitions: '',
  responsibilities: '',
  detail: '',
  revision_history: '',
  appendix: '',
};

const TEMPLATE_SECTIONS: { name: keyof FormValues; label: string }[] = [
  { name: 'purpose', label: 'Purpose' },
  { name: 'scope', label: 'Scope' },
  { name: 'definitions', label: 'Definitions' },
  { name: 'responsibilities', label: 'Responsibilities' },
  { name: 'detail', label: 'Detail' },
  { name: 'revision_history', label: 'Revision History' },
  { name: 'appendix', label: 'Appendix' },
];

export function DocumentFormModal({
  open,
  onClose,
  onSaved,
  document: doc,
}: {
  open: boolean;
  onClose: () => void;
  onSaved: (id: string) => void;
  /** When provided, the modal edits this document; otherwise it creates one. */
  document?: ControlledDocument;
}) {
  const { notify } = useToast();
  const create = documentHooks.useCreate();
  const update = documentHooks.useUpdate();
  const isEdit = Boolean(doc);
  const pending = create.isPending || update.isPending;

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY,
  });

  // Hydrate the form when opening (or switching target document).
  useEffect(() => {
    if (!open) return;
    if (doc) {
      reset({
        title: doc.title ?? '',
        doc_type: doc.doc_type ?? 'procedure',
        department: doc.department ?? '',
        version: doc.version ?? '',
        current_revision: doc.current_revision ?? '',
        next_review_date: doc.next_review_date?.slice(0, 10) ?? '',
        owner_id: doc.owner_id != null ? String(doc.owner_id) : '',
        description: doc.description ?? '',
        purpose: doc.purpose ?? '',
        scope: doc.scope ?? '',
        definitions: doc.definitions ?? '',
        responsibilities: doc.responsibilities ?? '',
        detail: doc.detail ?? '',
        revision_history: doc.revision_history ?? '',
        appendix: doc.appendix ?? '',
      });
    } else {
      reset(EMPTY);
    }
  }, [open, doc, reset]);

  const submit = handleSubmit(async (values) => {
    const payload: Partial<ControlledDocument> = {
      title: values.title,
      doc_type: values.doc_type,
      department: values.department ? values.department : undefined,
      version: values.version || undefined,
      current_revision: values.current_revision || undefined,
      next_review_date: values.next_review_date || undefined,
      owner_id: values.owner_id || undefined,
      description: values.description || undefined,
      purpose: values.purpose || undefined,
      scope: values.scope || undefined,
      definitions: values.definitions || undefined,
      responsibilities: values.responsibilities || undefined,
      detail: values.detail || undefined,
      revision_history: values.revision_history || undefined,
      appendix: values.appendix || undefined,
    };
    try {
      let saved: ControlledDocument;
      if (doc) {
        saved = (await update.mutateAsync({ id: doc.id, ...payload })) as ControlledDocument;
        notify('Document updated', 'success');
      } else {
        saved = (await create.mutateAsync(payload)) as ControlledDocument;
        notify(`Document ${saved.document_number ?? ''} created`, 'success');
      }
      reset(EMPTY);
      onSaved(saved.id);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  const handleClose = () => {
    reset(EMPTY);
    onClose();
  };

  const mutError = create.isError ? create.error : update.isError ? update.error : null;

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={isEdit ? 'Edit Document' : 'New Document'}
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={pending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={pending}>
            {pending ? <span className="spinner" /> : isEdit ? 'Save Changes' : 'Create Document'}
          </button>
        </>
      }
    >
      {mutError && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(mutError)}</span>
        </div>
      )}
      <form onSubmit={submit} noValidate>
        <FormField label="Title" htmlFor="doc-title" required error={errors.title?.message}>
          <TextInput id="doc-title" {...register('title')} placeholder="Document title" />
        </FormField>
        <FormField label="Document number" htmlFor="doc-number" hint="Auto-assigned on creation.">
          <TextInput id="doc-number" value={doc?.document_number ?? 'Auto-generated'} readOnly disabled />
        </FormField>
        <div className="form-grid">
          <FormField label="Type" htmlFor="doc-type" required error={errors.doc_type?.message}>
            <Select id="doc-type" {...register('doc_type')}>
              {DOC_TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Department" htmlFor="doc-dept" error={errors.department?.message}>
            <Select id="doc-dept" {...register('department')}>
              <option value="">— Select —</option>
              {DEPARTMENT_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Version" htmlFor="doc-version" error={errors.version?.message}>
            <TextInput id="doc-version" {...register('version')} placeholder="e.g. 1.0" />
          </FormField>
          <FormField label="Revision" htmlFor="doc-rev" error={errors.current_revision?.message}>
            <TextInput id="doc-rev" {...register('current_revision')} placeholder="e.g. A" />
          </FormField>
          <FormField label="Next review date" htmlFor="doc-review" error={errors.next_review_date?.message}>
            <TextInput id="doc-review" type="date" {...register('next_review_date')} />
          </FormField>
          <FormField label="Owner (user ID)" htmlFor="doc-owner" error={errors.owner_id?.message}>
            <TextInput id="doc-owner" {...register('owner_id')} placeholder="Owner user ID" />
          </FormField>
        </div>
        <FormField label="Description" htmlFor="doc-desc" error={errors.description?.message}>
          <TextArea id="doc-desc" rows={2} {...register('description')} />
        </FormField>

        <div className="section-title" style={{ marginTop: 8 }}>Document Template</div>
        {TEMPLATE_SECTIONS.map((s) => (
          <FormField key={s.name} label={s.label} htmlFor={`doc-${s.name}`} error={errors[s.name]?.message}>
            <TextArea id={`doc-${s.name}`} rows={3} {...register(s.name)} placeholder={s.label} />
          </FormField>
        ))}
      </form>
    </Modal>
  );
}
