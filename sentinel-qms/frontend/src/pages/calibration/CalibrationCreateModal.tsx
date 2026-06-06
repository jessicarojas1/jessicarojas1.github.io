import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { calibrationHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Modal } from '@/components/Modal';
import { FormField, Select, TextInput } from '@/components/FormField';
import type { Equipment } from '@/types';

const schema = z.object({
  name: z.string().min(2, 'Equipment name is required'),
  equipment_type: z.string().optional(),
  manufacturer: z.string().optional(),
  model: z.string().optional(),
  serial_number: z.string().optional(),
  location: z.string().optional(),
  status: z.enum(['active', 'out_of_service', 'lost', 'retired']),
  calibration_interval_days: z.coerce.number().int().min(1).max(3650),
  last_calibration_date: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function CalibrationCreateModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const { notify } = useToast();
  const create = calibrationHooks.useCreate();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { status: 'active', calibration_interval_days: 365 },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const payload: Partial<Equipment> = {
        name: values.name,
        equipment_type: values.equipment_type || undefined,
        manufacturer: values.manufacturer || undefined,
        model: values.model || undefined,
        serial_number: values.serial_number || undefined,
        location: values.location || undefined,
        status: values.status,
        calibration_interval_days: values.calibration_interval_days,
        last_calibration_date: values.last_calibration_date || undefined,
      };
      const created = (await create.mutateAsync(payload)) as Equipment;
      notify(`Equipment ${created.asset_tag ?? ''} added`, 'success');
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
      title="New Equipment"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Add Equipment'}
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
        <FormField label="Equipment name" htmlFor="cal-name" required error={errors.name?.message}>
          <TextInput id="cal-name" {...register('name')} placeholder="e.g. Mitutoyo Digital Caliper" />
        </FormField>
        <div className="form-grid">
          <FormField label="Equipment type" htmlFor="cal-type" error={errors.equipment_type?.message}>
            <TextInput id="cal-type" {...register('equipment_type')} placeholder="Caliper, CMM, gage block…" />
          </FormField>
          <FormField label="Location" htmlFor="cal-loc" error={errors.location?.message}>
            <TextInput id="cal-loc" {...register('location')} />
          </FormField>
          <FormField label="Manufacturer" htmlFor="cal-mfr" error={errors.manufacturer?.message}>
            <TextInput id="cal-mfr" {...register('manufacturer')} />
          </FormField>
          <FormField label="Model" htmlFor="cal-model" error={errors.model?.message}>
            <TextInput id="cal-model" {...register('model')} />
          </FormField>
          <FormField label="Serial number" htmlFor="cal-serial" error={errors.serial_number?.message}>
            <TextInput id="cal-serial" {...register('serial_number')} />
          </FormField>
          <FormField label="Status" htmlFor="cal-status" required error={errors.status?.message}>
            <Select id="cal-status" {...register('status')}>
              <option value="active">Active</option>
              <option value="out_of_service">Out of service</option>
              <option value="lost">Lost</option>
              <option value="retired">Retired</option>
            </Select>
          </FormField>
          <FormField
            label="Calibration interval (days)"
            htmlFor="cal-interval"
            required
            error={errors.calibration_interval_days?.message}
          >
            <TextInput id="cal-interval" type="number" min={1} max={3650} {...register('calibration_interval_days')} />
          </FormField>
          <FormField label="Last calibration date" htmlFor="cal-last" error={errors.last_calibration_date?.message}>
            <TextInput id="cal-last" type="date" {...register('last_calibration_date')} />
          </FormField>
        </div>
      </form>
    </Modal>
  );
}
