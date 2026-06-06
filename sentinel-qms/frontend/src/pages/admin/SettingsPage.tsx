import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { AlertCircle, Image as ImageIcon, Info, Save, Settings, Upload } from 'lucide-react';
import { useOrgSettings, useUpdateSettings, type OrgSettingsUpdate } from '@/hooks';
import { sanitizeAccent, sanitizeLogoUrl, DEFAULT_BRANDING } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { BrandIcon } from '@/lib/nav';
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

// Uploaded logos are stored inline as data: URLs; cap the source file so we do
// not bloat the settings row / localStorage cache.
const MAX_LOGO_BYTES = 512 * 1024; // 512 KB
const ACCEPTED_LOGO_TYPES = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp', 'image/gif'];

function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error ?? new Error('Failed to read file'));
    reader.readAsDataURL(file);
  });
}

export default function SettingsPage() {
  const { data, isLoading, error } = useOrgSettings();
  const update = useUpdateSettings();
  const { notify } = useToast();
  const { canEdit } = usePagePerms();
  // Reuse the Users page permission as the admin gate for org settings.
  const writable = canEdit('users');

  const fileInputRef = useRef<HTMLInputElement>(null);
  // Live preview values driven outside react-hook-form for the color + logo.
  const [logoValue, setLogoValue] = useState('');
  const [colorValue, setColorValue] = useState('');
  const [logoBroken, setLogoBroken] = useState(false);

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
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
    setLogoValue(data.logo_url ?? '');
    setColorValue(data.primary_color ?? '');
    setLogoBroken(false);
  }, [data, reset]);

  const applyLogo = (value: string) => {
    setLogoValue(value);
    setLogoBroken(false);
    setValue('logo_url', value, { shouldDirty: true });
  };

  const applyColor = (value: string) => {
    setColorValue(value);
    setValue('primary_color', value, { shouldDirty: true });
  };

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = ''; // allow re-selecting the same file
    if (!file) return;
    if (!ACCEPTED_LOGO_TYPES.includes(file.type)) {
      notify('Logo must be a PNG, JPG, SVG, WEBP, or GIF image', 'danger');
      return;
    }
    if (file.size > MAX_LOGO_BYTES) {
      notify('Logo file is too large (max 512 KB)', 'danger');
      return;
    }
    try {
      const dataUrl = await readFileAsDataUrl(file);
      applyLogo(dataUrl);
      notify('Logo loaded — remember to save', 'info');
    } catch {
      notify('Could not read the selected file', 'danger');
    }
  };

  const onSubmit = handleSubmit(async (values) => {
    const trimmedLogo = values.logo_url.trim();
    const trimmedColor = values.primary_color.trim();

    // Client-side validation mirrors the server so users get instant feedback.
    if (trimmedLogo && !sanitizeLogoUrl(trimmedLogo)) {
      notify('Logo URL must start with http://, https://, or be an uploaded image', 'danger');
      return;
    }
    if (trimmedColor && !sanitizeAccent(trimmedColor)) {
      notify('Primary color must be a hex value, e.g. #2563eb', 'danger');
      return;
    }

    // Send empty optional strings as null so they clear server-side.
    const payload: OrgSettingsUpdate = {
      organization_name: values.organization_name.trim() || undefined,
      logo_url: trimmedLogo || null,
      primary_color: trimmedColor || null,
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

  const previewLogo = sanitizeLogoUrl(logoValue);
  const previewColor = sanitizeAccent(colorValue) ?? DEFAULT_BRANDING.accent;
  // The color input needs a concrete hex value; fall back to the default.
  const colorInputValue = sanitizeAccent(colorValue) ?? DEFAULT_BRANDING.accent;
  const previewName = (watch('organization_name') || '').trim() || DEFAULT_BRANDING.name;

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

              {/* Live preview of the brand mark + name + accent. */}
              <div className="branding-preview">
                <span
                  className="branding-preview__mark"
                  style={{ background: 'var(--primary-soft)', color: previewColor }}
                >
                  {previewLogo && !logoBroken ? (
                    <img
                      src={previewLogo}
                      alt=""
                      className="branding-preview__img"
                      onError={() => setLogoBroken(true)}
                    />
                  ) : (
                    <BrandIcon size={20} />
                  )}
                </span>
                <div className="branding-preview__meta">
                  <strong>{previewName}</strong>
                  <span className="branding-preview__accent">
                    <span
                      className="branding-preview__swatch"
                      style={{ background: previewColor }}
                      aria-hidden
                    />
                    <span className="muted text-sm">Live preview of your brand mark and accent.</span>
                  </span>
                </div>
              </div>

              <div className="form-grid">
                <FormField
                  label="Organization name"
                  htmlFor="st-name"
                  error={errors.organization_name?.message}
                  hint="Replaces the app name in the top bar, sign-in screen, and browser tab."
                >
                  <TextInput id="st-name" {...register('organization_name')} placeholder={DEFAULT_BRANDING.name} />
                </FormField>

                <FormField
                  label="Logo URL"
                  htmlFor="st-logo"
                  hint="Paste an image URL (http(s)://) or upload a file below. Shown in the top bar, sign-in screen, and printed reports."
                >
                  <TextInput
                    id="st-logo"
                    {...register('logo_url')}
                    placeholder="https://…/logo.svg"
                    onChange={(e) => applyLogo(e.target.value)}
                  />
                </FormField>

                <FormField label="Upload logo" htmlFor="st-logo-file" hint="PNG, JPG, SVG, WEBP, or GIF — max 512 KB. Stored inline so it works offline.">
                  <div className="logo-upload">
                    <input
                      ref={fileInputRef}
                      id="st-logo-file"
                      type="file"
                      accept={ACCEPTED_LOGO_TYPES.join(',')}
                      className="logo-upload__input"
                      onChange={handleUpload}
                    />
                    <button
                      type="button"
                      className="btn btn-sm"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={!writable}
                    >
                      <Upload size={14} /> Choose file…
                    </button>
                    {logoValue && (
                      <button
                        type="button"
                        className="btn btn-sm btn-ghost"
                        onClick={() => applyLogo('')}
                        disabled={!writable}
                      >
                        <ImageIcon size={14} /> Remove logo
                      </button>
                    )}
                  </div>
                </FormField>

                <FormField
                  label="Primary color"
                  htmlFor="st-color"
                  hint="Accent color applied across the app. Hex value, e.g. #2563eb."
                >
                  <div className="color-field">
                    <input
                      id="st-color-picker"
                      type="color"
                      className="color-field__picker"
                      value={colorInputValue}
                      onChange={(e) => applyColor(e.target.value)}
                      aria-label="Pick primary color"
                    />
                    <TextInput
                      id="st-color"
                      {...register('primary_color')}
                      placeholder="#2563eb"
                      onChange={(e) => applyColor(e.target.value)}
                    />
                  </div>
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
                  {isDirty && <span className="muted text-sm">Unsaved changes</span>}
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
