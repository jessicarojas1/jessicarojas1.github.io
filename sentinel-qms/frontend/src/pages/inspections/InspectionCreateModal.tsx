import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, Plus, Rows3, Trash2 } from 'lucide-react';
import { inspectionHooks } from '@/hooks';
import { api, getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextArea, TextInput } from '@/components/FormField';
import type { Inspection } from '@/types';

const characteristicSchema = z.object({
  balloon_number: z.string().optional(),
  characteristic: z.string().optional(),
  nominal: z.string().optional(),
  tol_minus: z.string().optional(),
  tol_plus: z.string().optional(),
  measured_value: z.string().optional(),
  result: z.string().optional(),
});

const schema = z.object({
  inspection_type: z.enum(['receiving', 'in_process', 'final', 'first_article', 'source']),
  part_number: z.string().optional(),
  lot_number: z.string().optional(),
  work_order: z.string().optional(),
  inspection_date: z.string().optional(),
  quantity_inspected: z.coerce.number().int().nonnegative().optional(),
  quantity_accepted: z.coerce.number().int().nonnegative().optional(),
  quantity_rejected: z.coerce.number().int().nonnegative().optional(),
  notes: z.string().optional(),
  // FAI-only fields
  fai_part_revision: z.string().optional(),
  fai_drawing_number: z.string().optional(),
  characteristics: z.array(characteristicSchema).optional(),
});
type FormValues = z.infer<typeof schema>;

const blankChar = {
  balloon_number: '',
  characteristic: '',
  nominal: '',
  tol_minus: '',
  tol_plus: '',
  measured_value: '',
  result: '',
};

/** Parse an optional decimal string to a number, or undefined when blank. */
function num(v?: string): number | undefined {
  if (v == null || v.trim() === '') return undefined;
  const n = Number(v);
  return Number.isFinite(n) ? n : undefined;
}

export function InspectionCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = inspectionHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    watch,
    control,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { inspection_type: 'receiving', characteristics: [] },
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'characteristics' });
  const inspectionType = watch('inspection_type');
  const isFai = inspectionType === 'first_article';
  const pending = create.isPending || isSubmitting;

  const addTemplateRows = () => {
    for (let i = 0; i < 5; i++) append({ ...blankChar });
  };

  const submit = handleSubmit(async (values) => {
    try {
      const payload: Partial<Inspection> = {
        inspection_type: values.inspection_type,
        part_number: values.part_number || undefined,
        lot_number: values.lot_number || undefined,
        work_order: values.work_order || undefined,
        inspection_date: values.inspection_date || undefined,
        quantity_inspected: values.quantity_inspected,
        quantity_accepted: values.quantity_accepted,
        quantity_rejected: values.quantity_rejected,
        notes: values.notes || undefined,
      };
      const created = (await create.mutateAsync(payload)) as Inspection;

      // First Article: create the FAI report + characteristics via the dedicated endpoint.
      if (isFai) {
        const characteristics = (values.characteristics ?? [])
          .filter((c) => (c.balloon_number?.trim() || c.characteristic?.trim()))
          .map((c) => ({
            balloon_number: c.balloon_number?.trim() || '—',
            characteristic: c.characteristic?.trim() || 'Characteristic',
            nominal: num(c.nominal),
            tol_minus: num(c.tol_minus),
            tol_plus: num(c.tol_plus),
            measured_value: num(c.measured_value),
            result: c.result || undefined,
          }));
        try {
          await api.post('/inspections/fai', {
            part_number: values.part_number?.trim() || created.inspection_number,
            part_revision: values.fai_part_revision || undefined,
            drawing_number: values.fai_drawing_number || undefined,
            inspection_id: Number(created.id),
            characteristics,
          });
        } catch (faiErr) {
          notify(`Inspection created, but FAI report failed: ${getErrorMessage(faiErr)}`, 'danger');
          reset();
          onCreated(created.id);
          return;
        }
      }

      notify(`Inspection ${created.inspection_number ?? ''} created`, 'success');
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
      title="New Inspection"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={pending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={pending}>
            {pending ? <span className="spinner" /> : 'Create Inspection'}
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
        <div className="form-grid">
          <FormField label="Inspection type" htmlFor="insp-type" required error={errors.inspection_type?.message}>
            <Select id="insp-type" {...register('inspection_type')}>
              <option value="receiving">Receiving</option>
              <option value="in_process">In-process</option>
              <option value="final">Final</option>
              <option value="first_article">First Article (AS9102)</option>
              <option value="source">Source</option>
            </Select>
          </FormField>
          <FormField label="Inspection date" htmlFor="insp-date" error={errors.inspection_date?.message}>
            <TextInput id="insp-date" type="date" {...register('inspection_date')} />
          </FormField>
          <FormField label="Part number" htmlFor="insp-part" error={errors.part_number?.message}>
            <TextInput id="insp-part" {...register('part_number')} placeholder="e.g. 12345-001" />
          </FormField>
          <FormField label="Lot / serial" htmlFor="insp-lot" error={errors.lot_number?.message}>
            <TextInput id="insp-lot" {...register('lot_number')} />
          </FormField>
          <FormField label="Work order" htmlFor="insp-wo" error={errors.work_order?.message}>
            <TextInput id="insp-wo" {...register('work_order')} />
          </FormField>
          <FormField label="Qty inspected" htmlFor="insp-qi" error={errors.quantity_inspected?.message}>
            <TextInput id="insp-qi" type="number" min={0} {...register('quantity_inspected')} />
          </FormField>
          <FormField label="Qty accepted" htmlFor="insp-qa" error={errors.quantity_accepted?.message}>
            <TextInput id="insp-qa" type="number" min={0} {...register('quantity_accepted')} />
          </FormField>
          <FormField label="Qty rejected" htmlFor="insp-qr" error={errors.quantity_rejected?.message}>
            <TextInput id="insp-qr" type="number" min={0} {...register('quantity_rejected')} />
          </FormField>
        </div>
        <FormField label="Notes" htmlFor="insp-notes" error={errors.notes?.message}>
          <TextArea id="insp-notes" rows={2} {...register('notes')} />
        </FormField>

        {isFai && (
          <div style={{ marginTop: 8, borderTop: '1px solid var(--border)', paddingTop: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: '0.95rem' }}>
              First Article Inspection Report (AS9102)
            </h3>
            <div className="form-grid">
              <FormField label="Part revision" htmlFor="fai-rev" error={errors.fai_part_revision?.message}>
                <TextInput id="fai-rev" {...register('fai_part_revision')} placeholder="e.g. C" />
              </FormField>
              <FormField label="Drawing number" htmlFor="fai-dwg" error={errors.fai_drawing_number?.message}>
                <TextInput id="fai-dwg" {...register('fai_drawing_number')} />
              </FormField>
            </div>

            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                margin: '12px 0 8px',
              }}
            >
              <strong style={{ fontSize: '0.875rem' }}>Characteristics</strong>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" className="btn btn-sm" onClick={addTemplateRows}>
                  <Rows3 size={14} /> Add 5 rows
                </button>
                <button type="button" className="btn btn-sm" onClick={() => append({ ...blankChar })}>
                  <Plus size={14} /> Add row
                </button>
              </div>
            </div>

            {fields.length === 0 ? (
              <p className="muted" style={{ fontSize: '0.825rem', margin: '4px 0 0' }}>
                No characteristics yet — add balloon rows to record measurements.
              </p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table className="table table--compact" style={{ minWidth: 640 }}>
                  <thead>
                    <tr>
                      <th style={{ width: 70 }}>Balloon</th>
                      <th>Characteristic</th>
                      <th style={{ width: 90 }}>Nominal</th>
                      <th style={{ width: 80 }}>Tol −</th>
                      <th style={{ width: 80 }}>Tol +</th>
                      <th style={{ width: 90 }}>Measured</th>
                      <th style={{ width: 90 }}>Result</th>
                      <th style={{ width: 40 }} aria-label="Remove" />
                    </tr>
                  </thead>
                  <tbody>
                    {fields.map((field, i) => (
                      <tr key={field.id}>
                        <td>
                          <TextInput aria-label="Balloon number" {...register(`characteristics.${i}.balloon_number`)} />
                        </td>
                        <td>
                          <TextInput aria-label="Characteristic" {...register(`characteristics.${i}.characteristic`)} />
                        </td>
                        <td>
                          <TextInput aria-label="Nominal" {...register(`characteristics.${i}.nominal`)} />
                        </td>
                        <td>
                          <TextInput aria-label="Tolerance minus" {...register(`characteristics.${i}.tol_minus`)} />
                        </td>
                        <td>
                          <TextInput aria-label="Tolerance plus" {...register(`characteristics.${i}.tol_plus`)} />
                        </td>
                        <td>
                          <TextInput aria-label="Measured value" {...register(`characteristics.${i}.measured_value`)} />
                        </td>
                        <td>
                          <Select aria-label="Result" {...register(`characteristics.${i}.result`)}>
                            <option value="">—</option>
                            <option value="pass">Pass</option>
                            <option value="fail">Fail</option>
                          </Select>
                        </td>
                        <td>
                          <button
                            type="button"
                            className="btn btn-sm btn-icon"
                            aria-label="Remove row"
                            onClick={() => remove(i)}
                          >
                            <Trash2 size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </form>
    </Modal>
  );
}
