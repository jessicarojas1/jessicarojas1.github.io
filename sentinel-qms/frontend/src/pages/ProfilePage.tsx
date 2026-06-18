import { useEffect, useState } from 'react';
import { useNotificationPrefs, useSaveNotificationPrefs } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { ROLE_LABELS } from '@/types';
import { PageHeader } from '@/components/PageHeader';

/** Notification categories a user may opt out of (email/chat; in-app always kept). */
const CATEGORIES: { key: string; label: string; help: string }[] = [
  { key: 'assignment', label: 'Assignments & mentions', help: 'CAPA actions, approvals, @mentions' },
  { key: 'sla', label: 'SLA escalations', help: 'Overdue NCRs, CAPAs and due-soon items' },
  { key: 'spc', label: 'SPC rule violations', help: 'Control-chart (Western Electric) alerts' },
  { key: 'fmea', label: 'FMEA high-RPN alerts', help: 'High action-priority failure modes' },
  { key: 'quality_objective', label: 'Quality objective at-risk', help: 'Objectives falling below target' },
  { key: 'general', label: 'General notices', help: 'Other system notifications' },
];

export default function ProfilePage() {
  const { user } = useAuth();
  const { data, isLoading } = useNotificationPrefs();
  const save = useSaveNotificationPrefs();
  const { notify } = useToast();
  const [muted, setMuted] = useState<Set<string>>(new Set());

  useEffect(() => {
    if (data) setMuted(new Set(data.muted_categories));
  }, [data]);

  const toggle = (key: string) =>
    setMuted((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  const onSave = async () => {
    try {
      await save.mutateAsync([...muted]);
      notify('Notification preferences saved', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <>
      <PageHeader
        title="My Profile"
        subtitle="Your account details and notification preferences."
        breadcrumbs={[{ label: 'Profile' }]}
      />

      <div className="card" style={{ marginBottom: 'var(--space-4)' }}>
        <div className="card__header"><div className="card__title">Account</div></div>
        <div className="card__body">
          <dl className="detail-grid">
            <div><dt>Name</dt><dd>{user?.full_name ?? '—'}</dd></div>
            <div><dt>Email</dt><dd>{user?.email ?? '—'}</dd></div>
            <div><dt>Roles</dt><dd>{user?.roles.map((r) => ROLE_LABELS[r]).join(', ') || '—'}</dd></div>
          </dl>
        </div>
      </div>

      <div className="card">
        <div className="card__header">
          <div className="card__title">Notification Preferences</div>
          <div className="card__subtitle">Muted categories still appear in-app but are not emailed or sent to chat.</div>
        </div>
        <div className="card__body">
          {isLoading ? (
            <div className="empty-state-sm"><span className="spinner" /> Loading…</div>
          ) : (
            <div className="stack" style={{ gap: 10 }}>
              {CATEGORIES.map((c) => (
                <label key={c.key} className="checkbox-row" style={{ alignItems: 'flex-start' }}>
                  <input
                    type="checkbox"
                    className="checkbox"
                    checked={!muted.has(c.key)}
                    onChange={() => toggle(c.key)}
                    aria-label={`Receive ${c.label}`}
                  />
                  <span>
                    <span style={{ fontWeight: 600 }}>{c.label}</span>
                    <span className="muted text-sm" style={{ display: 'block' }}>{c.help}</span>
                  </span>
                </label>
              ))}
              <div>
                <button type="button" className="btn btn-primary btn-sm" onClick={onSave} disabled={save.isPending}>
                  {save.isPending ? <span className="spinner" /> : 'Save preferences'}
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
