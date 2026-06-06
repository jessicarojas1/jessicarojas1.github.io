import { useState } from 'react';
import { useNavigate, useLocation, Navigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, Boxes, LogIn } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { getErrorMessage } from '@/lib/api';
import { FormField, TextInput } from '@/components/FormField';
import { ThemeToggle } from '@/components/ThemeToggle';

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
              <Boxes size={22} />
            </span>
            <div>
              <h1 style={{ fontSize: 20 }}>Sentinel QMS</h1>
              <div className="muted text-sm">Enterprise Quality Management</div>
            </div>
          </div>

          <p className="muted text-sm" style={{ marginBottom: 20 }}>
            Authorized use only. This is a U.S. Government information system handling Controlled
            Unclassified Information (CUI). Activity is monitored and recorded.
          </p>

          {serverError && (
            <div className="alert alert--danger" style={{ marginBottom: 16 }}>
              <AlertCircle size={16} />
              <span>{serverError}</span>
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

          <div className="muted text-sm" style={{ marginTop: 20, textAlign: 'center' }}>
            AS9100D · ISO 9001:2015 · CMMC 2.0 · 21 CFR Part 11
          </div>
        </div>
      </div>
    </div>
  );
}
