import { useState } from 'react';
import { useNavigate, useLocation, Navigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, Boxes, KeyRound, LogIn } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { API_BASE_URL, getErrorMessage } from '@/lib/api';
import { useBranding, useSsoInfo } from '@/hooks';
import { FormField, TextInput } from '@/components/FormField';
import { ThemeToggle } from '@/components/ThemeToggle';

const SSO_ERRORS: Record<string, string> = {
  sso_failed: 'Single sign-on did not complete. Please try again.',
  sso_denied: 'Single sign-on was denied for this account.',
};

const schema = z.object({
  username: z.string().min(1, 'Username is required'),
  password: z.string().min(1, 'Password is required'),
});
type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const { login, isAuthenticated } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [serverError, setServerError] = useState<string | null>(null);
  // Branding is best-effort here (endpoint requires auth); the localStorage
  // cache from a prior session keeps the sign-in screen branded.
  const branding = useBranding();
  const brandName = branding.name;
  const logoUrl = branding.logoUrl;
  const ssoInfo = useSsoInfo();
  const ssoErrorCode = new URLSearchParams(location.search).get('sso_error');
  const ssoError = ssoErrorCode ? (SSO_ERRORS[ssoErrorCode] ?? 'Single sign-on failed.') : null;

  const startSso = (provider: 'oidc' | 'saml') => {
    const from = (location.state as { from?: string } | null)?.from ?? '/';
    window.location.href =
      `${API_BASE_URL}/auth/${provider}/login?redirect=${encodeURIComponent(from)}`;
  };

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  if (isAuthenticated) {
    return <Navigate to="/" replace />;
  }

  const onSubmit = async (values: FormValues) => {
    setServerError(null);
    try {
      await login(values);
      const from = (location.state as { from?: string } | null)?.from ?? '/';
      navigate(from, { replace: true });
    } catch (err) {
      setServerError(getErrorMessage(err));
    }
  };

  return (
    <div className="login-shell">
      <div className="login-main">
        <div style={{ position: 'absolute', top: 12, right: 16 }}>
          <ThemeToggle />
        </div>
        <div className="login-card">
          <div className="login-brand">
            <span className="logo-mark">
              {logoUrl ? (
                <img src={logoUrl} alt="" className="logo-mark__img" />
              ) : (
                <Boxes size={22} />
              )}
            </span>
            <div>
              <h1 style={{ fontSize: 20 }}>{brandName}</h1>
              <div className="muted text-sm">Enterprise Quality Management</div>
            </div>
          </div>

          <p className="muted text-sm" style={{ marginBottom: 20 }}>
            Authorized use only. This is a U.S. Government information system handling Controlled
            Unclassified Information (CUI). Activity is monitored and recorded.
          </p>

          {(serverError || ssoError) && (
            <div className="alert alert--danger" style={{ marginBottom: 16 }}>
              <AlertCircle size={16} />
              <span>{serverError ?? ssoError}</span>
            </div>
          )}

          <form onSubmit={handleSubmit(onSubmit)} noValidate>
            <FormField label="Username" htmlFor="username" required error={errors.username?.message}>
              <TextInput
                id="username"
                autoComplete="username"
                autoFocus
                {...register('username')}
              />
            </FormField>
            <FormField label="Password" htmlFor="password" required error={errors.password?.message}>
              <TextInput
                id="password"
                type="password"
                autoComplete="current-password"
                {...register('password')}
              />
            </FormField>
            <button type="submit" className="btn btn-primary btn-block" disabled={isSubmitting}>
              {isSubmitting ? <span className="spinner" /> : <LogIn size={16} />}
              Sign in
            </button>
          </form>

          {ssoInfo.data?.enabled && (
            <>
              <div
                className="muted text-sm"
                style={{ textAlign: 'center', margin: '14px 0 10px' }}
              >
                or
              </div>
              {ssoInfo.data.oidc && (
                <button type="button" className="btn btn-block" onClick={() => startSso('oidc')}>
                  <KeyRound size={16} /> {ssoInfo.data.label}
                </button>
              )}
              {ssoInfo.data.saml && (
                <button
                  type="button"
                  className="btn btn-block"
                  style={{ marginTop: ssoInfo.data.oidc ? 8 : 0 }}
                  onClick={() => startSso('saml')}
                >
                  <KeyRound size={16} /> Sign in with SAML
                </button>
              )}
            </>
          )}

          <div className="muted text-sm" style={{ marginTop: 20, textAlign: 'center' }}>
            AS9100D · ISO 9001:2015 · CMMC 2.0 · 21 CFR Part 11
          </div>
        </div>
      </div>
    </div>
  );
}
