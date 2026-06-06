import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { AlertCircle, Info, Save, Settings } from 'lucide-react';
import { useOrgSettings, useUpdateSettings, type OrgSettingsUpdate } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { FormField, TextInput } from '@/components/FormField';

interface FormValues {
  organization_name: string;
  logo_url: string;
  primary_color: string;
  support_email: string;
  default_review_cycle_days: number;
  calibration_default_interval_days: number;
  timezone: string;
}

export default function SettingsPage() {
  const { data, isLoading, error } = useOrgSettings();
  const update = useUpdateSettings();
  const { notify } = useToast();
  const { canEdit } = usePagePerms();
  // Reuse the Users page permission as the admin gate for org settings.
  const writable = canEdit('users');

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isDirty },
  } = useForm<FormValues>({
    defaultValues: {
      organization_name: '',
      logo_url: '',
      primary_color: '',
      support_email: '',
      default_review_cycle_days: 365,
      calibration_default_interval_days: 365,
      timezone: 'UTC',
    },
  });

  // Hydrate the form once settings load (or after a save refreshes the cache).
  useEffect(() => {
    if (!data) return;
    reset({
      organization_name: data.organization_name ?? '',
      logo_url: data.logo_url ?? '',
      primary_color: data.primary_color ?? '',
      support_email: data.support_email ?? '',
      default_review_cycle_days: data.default_review_cycle_days ?? 365,
      calibration_default_interval_days: data.calibration_default_interval_days ?? 365,
      timezone: data.timezone ?? 'UTC',
    });
  }, [data, reset]);

  const onSubmit = handleSubmit(async (values) => {
    // Send empty optional strings as null so they clear server-side.
    const payload: OrgSettingsUpdate = {
      organization_name: values.organization_name.trim() || undefined,
      logo_url: values.logo_url.trim() || null,
      primary_color: values.primary_color.trim() || null,
      support_email: values.support_email.trim() || null,
      default_review_cycle_days: Number(values.default_review_cycle_days),
      calibration_default_interval_days: Number(values.calibration_default_interval_days),
      timezone: values.timezone.trim() || undefined,
    };
    try {
      await update.mutateAsync(payload);
      notify('Organization settings saved', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  return (
    <>
      <PageHeader
        title="Settings & Branding"
        icon={<Settings size={22} />}
        subtitle="Organization identity, branding, and default cadences."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Settings' }]}
      />

      {error && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(error)}</span>
        </div>
      )}

      {!writable && (
        <div className="alert alert--info" style={{ marginBottom: 16 }}>
          <Info size={16} />
          <span>You have read-only access to these settings. Ask an administrator to make changes.</span>
        </div>
      )}

      <div className="card settings-card">
        {isLoading ? (
          <div className="loading-block" style={{ minHeight: 200 }}>
            <span className="spinner spinner--lg" />
          </div>
        ) : (
          <form onSubmit={onSubmit} noValidate>
            <fieldset disabled={!writable} className="settings-fieldset">
              <h2 className="settings-section-title">Branding</h2>
              <div className="form-grid">
                <FormField
                  label="Organization name"
                  htmlFor="st-name"
                  error={errors.organization_name?.message}
                >
                  <TextInput id="st-name" {...register('organization_name')} placeholder="Sentinel QMS" />
                </FormField>
                <FormField
                  label="Logo URL"
                  htmlFor="st-logo"
                  hint="Shown in the top bar and on the sign-in screen."
                >
                  <TextInput id="st-logo" {...register('logo_url')} placeholder="https://…/logo.svg" />
                </FormField>
                <FormField
                  label="Primary color"
                  htmlFor="st-color"
                  hint="Hex value, e.g. #2563eb."
                >
                  <TextInput id="st-color" {...register('primary_color')} placeholder="#2563eb" />
                </FormField>
                <FormField label="Support email" htmlFor="st-email">
                  <TextInput id="st-email" type="email" {...register('support_email')} placeholder="support@example.com" />
                </FormField>
              </div>

              <h2 className="settings-section-title">Defaults</h2>
              <div className="form-grid">
                <FormField
                  label="Default review cycle (days)"
                  htmlFor="st-review"
                  error={errors.default_review_cycle_days?.message}
                >
                  <TextInput
                    id="st-review"
                    type="number"
                    min={0}
                    {...register('default_review_cycle_days', { valueAsNumber: true })}
                  />
                </FormField>
                <FormField
                  label="Default calibration interval (days)"
                  htmlFor="st-cal"
                  error={errors.calibration_default_interval_days?.message}
                >
                  <TextInput
                    id="st-cal"
                    type="number"
                    min={0}
                    {...register('calibration_default_interval_days', { valueAsNumber: true })}
                  />
                </FormField>
                <FormField label="Timezone" htmlFor="st-tz" hint="IANA name, e.g. UTC or America/New_York.">
                  <TextInput id="st-tz" {...register('timezone')} placeholder="UTC" />
                </FormField>
              </div>

              {writable && (
                <div className="settings-actions">
                  <button
                    type="submit"
                    className="btn btn-primary"
                    disabled={update.isPending || !isDirty}
                  >
                    {update.isPending ? <span className="spinner" /> : <Save size={15} />}
                    Save Settings
                  </button>
                </div>
              )}
            </fieldset>
          </form>
        )}
      </div>
    </>
  );
}
