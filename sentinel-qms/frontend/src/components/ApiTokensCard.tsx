import { useState } from 'react';
import { Copy, KeyRound, Trash2 } from 'lucide-react';
import {
  useApiTokens,
  useCreateApiToken,
  useRevokeApiToken,
  type ApiTokenCreated,
} from '@/hooks';
import { FormField, Select, TextInput } from '@/components/FormField';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';

const EXPIRY_OPTIONS: { label: string; days: number | null }[] = [
  { label: 'Never', days: null },
  { label: '30 days', days: 30 },
  { label: '90 days', days: 90 },
  { label: '1 year', days: 365 },
];

/** Self-service Personal Access Token management (scoped API keys). */
export function ApiTokensCard() {
  const { data: tokens = [], isLoading } = useApiTokens();
  const create = useCreateApiToken();
  const revoke = useRevokeApiToken();
  const { notify } = useToast();

  const [name, setName] = useState('');
  const [canWrite, setCanWrite] = useState(false);
  const [expiryDays, setExpiryDays] = useState<number | null>(null);
  // The one-time plaintext secret, shown only immediately after creation.
  const [secret, setSecret] = useState<ApiTokenCreated | null>(null);

  const onCreate = async () => {
    const trimmed = name.trim();
    if (!trimmed) {
      notify('Give the token a name first', 'danger');
      return;
    }
    try {
      const created = await create.mutateAsync({
        name: trimmed,
        scopes: canWrite ? ['read', 'write'] : ['read'],
        expires_in_days: expiryDays,
      });
      setSecret(created);
      setName('');
      setCanWrite(false);
      setExpiryDays(null);
      notify('API token created — copy it now, it won’t be shown again', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const onRevoke = async (id: number, label: string) => {
    if (!window.confirm(`Revoke token “${label}”? Any integration using it will stop working.`)) {
      return;
    }
    try {
      await revoke.mutateAsync(id);
      notify('Token revoked', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const copySecret = async () => {
    if (!secret) return;
    try {
      await navigator.clipboard.writeText(secret.token);
      notify('Copied to clipboard', 'success');
    } catch {
      notify('Copy failed — select and copy manually', 'danger');
    }
  };

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">
          <KeyRound size={16} /> API Tokens
        </div>
        <div className="card__subtitle">
          Scoped keys for scripts and integrations. A token acts as you, with your
          permissions; read-only tokens cannot change data.
        </div>
      </div>
      <div className="card__body">
        {secret && (
          <div className="alert alert--success" style={{ marginBottom: 'var(--space-4)' }}>
            <div>
              <strong>New token “{secret.name}”.</strong> Copy it now — it is shown only once.
            </div>
            <div
              style={{
                display: 'flex',
                gap: 8,
                marginTop: 8,
                alignItems: 'center',
                flexWrap: 'wrap',
              }}
            >
              <code className="mono" style={{ wordBreak: 'break-all', flex: '1 1 240px' }}>
                {secret.token}
              </code>
              <button type="button" className="btn btn-sm" onClick={copySecret}>
                <Copy size={14} /> Copy
              </button>
              <button
                type="button"
                className="btn btn-sm btn-ghost"
                onClick={() => setSecret(null)}
              >
                Dismiss
              </button>
            </div>
          </div>
        )}

        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <FormField label="Token name" htmlFor="tok-name" className="form-field--span">
            <TextInput
              id="tok-name"
              placeholder="e.g. CI export job"
              value={name}
              maxLength={128}
              onChange={(e) => setName(e.target.value)}
            />
          </FormField>
          <FormField label="Expires" htmlFor="tok-expiry">
            <Select
              id="tok-expiry"
              value={expiryDays ?? ''}
              onChange={(e) =>
                setExpiryDays(e.target.value === '' ? null : Number(e.target.value))
              }
            >
              {EXPIRY_OPTIONS.map((o) => (
                <option key={o.label} value={o.days ?? ''}>
                  {o.label}
                </option>
              ))}
            </Select>
          </FormField>
          <label className="checkbox-row" style={{ marginBottom: 10 }}>
            <input
              type="checkbox"
              className="checkbox"
              checked={canWrite}
              onChange={(e) => setCanWrite(e.target.checked)}
            />
            <span>Allow write (create / update / delete)</span>
          </label>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            style={{ marginBottom: 10 }}
            onClick={onCreate}
            disabled={create.isPending}
          >
            {create.isPending ? <span className="spinner" /> : 'Create token'}
          </button>
        </div>

        <div className="table-wrap" style={{ marginTop: 'var(--space-4)' }}>
          <table className="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Prefix</th>
                <th>Scopes</th>
                <th>Last used</th>
                <th>Expires</th>
                <th>Status</th>
                <th aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="empty-row">
                    <div className="empty-state-sm">
                      <span className="spinner" /> Loading…
                    </div>
                  </td>
                </tr>
              ) : tokens.length === 0 ? (
                <tr>
                  <td colSpan={7} className="empty-row">
                    <div className="empty-state-sm">No API tokens yet.</div>
                  </td>
                </tr>
              ) : (
                tokens.map((t) => (
                  <tr key={t.id}>
                    <td>{t.name}</td>
                    <td>
                      <code className="mono">{t.token_prefix}…</code>
                    </td>
                    <td>{t.scopes.join(', ') || '—'}</td>
                    <td>{t.last_used_at ? formatDateTime(t.last_used_at) : 'Never'}</td>
                    <td>{t.expires_at ? formatDate(t.expires_at) : 'Never'}</td>
                    <td>
                      <span className={`badge ${t.active ? 'badge--success' : 'badge--neutral'}`}>
                        {t.active ? 'Active' : t.revoked_at ? 'Revoked' : 'Expired'}
                      </span>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {t.active && (
                        <button
                          type="button"
                          className="btn btn-sm btn-ghost btn-danger"
                          onClick={() => onRevoke(t.id, t.name)}
                          disabled={revoke.isPending}
                          aria-label={`Revoke ${t.name}`}
                        >
                          <Trash2 size={14} /> Revoke
                        </button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
