'use client';

import { Suspense, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ShieldCheck, Loader2, LogIn } from 'lucide-react';
import { BrandMark } from '@/components/branding/BrandMark';
import { useBranding } from '@/components/branding/BrandingProvider';

export default function LoginPage() {
  return (
    <Suspense fallback={<div className="min-h-screen" />}>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const { branding } = useBranding();

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ username, password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        setError(data.error || `Login failed (${res.status})`);
        return;
      }
      const next = params.get('next');
      // Only follow same-app relative paths to avoid open-redirect.
      const dest = next && /^\/(?!\/)/.test(next) ? next : '/';
      router.push(dest);
      router.refresh();
    } catch {
      setError('Network error. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-6">
      <div className="card w-full max-w-sm p-6">
        <div className="flex items-center gap-3 mb-6">
          <BrandMark size={36} />
          <div>
            <div className="text-base font-bold text-slate-100">{branding.displayName}</div>
            <div className="text-xs text-slate-500 flex items-center gap-1">
              <ShieldCheck className="w-3 h-3" /> Sign in to continue
            </div>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-4">
          <div>
            <label htmlFor="username" className="label mb-1">Username</label>
            <input
              id="username"
              name="username"
              type="text"
              autoComplete="username"
              className="input"
              value={username}
              onChange={e => setUsername(e.target.value)}
              required
            />
          </div>
          <div>
            <label htmlFor="password" className="label mb-1">Password</label>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              className="input"
              value={password}
              onChange={e => setPassword(e.target.value)}
              required
            />
          </div>

          {error && <p className="text-xs text-red-400">{error}</p>}

          <button type="submit" className="btn-primary w-full flex items-center justify-center gap-2" disabled={loading}>
            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <LogIn className="w-4 h-4" />}
            {loading ? 'Signing in…' : 'Sign In'}
          </button>
        </form>

        <p className="text-[10px] text-slate-600 mt-4 text-center">
          Credentials are configured server-side via environment variables.
        </p>
      </div>
    </div>
  );
}
