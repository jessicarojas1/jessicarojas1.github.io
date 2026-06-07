import { useEffect, useMemo, useState, type ComponentType } from 'react';
import {
  Award, BookOpen, ChevronDown, ChevronRight, ClipboardCheck, FileBarChart,
  FileText, FlaskConical, GaugeCircle, GitPullRequestArrow, GraduationCap,
  History, KeyRound, MessageSquareWarning, ScrollText, Settings, Shield,
  ShieldAlert, TrendingUp, Truck, Users, Wrench,
} from 'lucide-react';
import { useIamCatalog, useIamUsers, useSaveUserGrants, type IamModule } from '@/hooks/useIam';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { ROLE_LABELS, type Role } from '@/types';

const ICONS: Record<string, ComponentType<{ size?: number | string }>> = {
  'shield-alert': ShieldAlert, 'clipboard-check': ClipboardCheck,
  'message-warning': MessageSquareWarning, 'file-text': FileText,
  'git-pull-request': GitPullRequestArrow, 'scroll-text': ScrollText,
  flask: FlaskConical, truck: Truck, wrench: Wrench, 'graduation-cap': GraduationCap,
  gauge: GaugeCircle, 'trending-up': TrendingUp, 'file-bar-chart': FileBarChart,
  'book-open': BookOpen, users: Users, award: Award, key: KeyRound,
  history: History, settings: Settings,
};

const roleHeader = (name: string): string => {
  const slug = name.trim().toLowerCase().replace(/[\s-]+/g, '_') as Role;
  return ROLE_LABELS[slug] ?? name;
};

export function IamUserManager() {
  const { notify } = useToast();
  const catalogQ = useIamCatalog();
  const usersQ = useIamUsers();
  const save = useSaveUserGrants();

  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [draft, setDraft] = useState<Set<string>>(new Set());
  const [open, setOpen] = useState<Set<string>>(new Set());

  const users = usersQ.data ?? [];
  const modules = catalogQ.data ?? [];
  const selected = useMemo(() => users.find((u) => u.id === selectedId) ?? null, [users, selectedId]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return users;
    return users.filter((u) => u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q));
  }, [users, search]);

  useEffect(() => {
    if (selectedId === null && users.length) setSelectedId(users[0].id);
  }, [users, selectedId]);

  // Seed draft + default-open first 3 modules when selection changes.
  useEffect(() => {
    if (selected) setDraft(new Set(selected.explicit));
  }, [selected]);
  useEffect(() => {
    if (modules.length && open.size === 0) setOpen(new Set(modules.slice(0, 3).map((m) => m.key)));
  }, [modules, open.size]);

  const roleDefault = useMemo(() => new Set(selected?.role_default ?? []), [selected]);

  const dirty = useMemo(() => {
    if (!selected) return false;
    const orig = new Set(selected.explicit);
    if (orig.size !== draft.size) return true;
    for (const p of draft) if (!orig.has(p)) return true;
    return false;
  }, [draft, selected]);

  const totalGranted = useMemo(() => {
    let n = 0;
    for (const m of modules) for (const a of m.actions) {
      const perm = `${m.key}.${a.key}`;
      if (roleDefault.has(perm) || draft.has(perm)) n += 1;
    }
    return n;
  }, [modules, roleDefault, draft]);

  const toggle = (perm: string) => {
    if (roleDefault.has(perm)) return; // granted by role — locked on
    setDraft((prev) => {
      const next = new Set(prev);
      next.has(perm) ? next.delete(perm) : next.add(perm);
      return next;
    });
  };

  const grantAll = (m: IamModule) =>
    setDraft((prev) => {
      const next = new Set(prev);
      for (const a of m.actions) {
        const perm = `${m.key}.${a.key}`;
        if (!roleDefault.has(perm)) next.add(perm);
      }
      return next;
    });

  const clearAll = (m: IamModule) =>
    setDraft((prev) => {
      const next = new Set(prev);
      for (const a of m.actions) next.delete(`${m.key}.${a.key}`);
      return next;
    });

  const onSave = async () => {
    if (!selected) return;
    try {
      await save.mutateAsync({ userId: selected.id, granted: [...draft] });
      notify(`Permissions updated for ${selected.full_name}`, 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  if (catalogQ.isLoading || usersQ.isLoading)
    return <div className="loading-block" style={{ minHeight: 200 }}><span className="spinner spinner--lg" /></div>;
  const error = catalogQ.error ?? usersQ.error;
  if (error) return <div className="empty-state-sm" style={{ color: 'var(--danger)' }}>{getErrorMessage(error)}</div>;

  return (
    <div className="iam-layout">
      {/* Left: user list */}
      <aside className="iam-userlist">
        <input className="input iam-search" placeholder="Search users…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <div className="iam-userlist__scroll">
          {filtered.map((u) => (
            <button key={u.id} type="button"
              className={`iam-usercard ${u.id === selectedId ? 'iam-usercard--active' : ''}`}
              onClick={() => setSelectedId(u.id)}>
              <span className="iam-avatar">{(u.full_name || u.email).charAt(0).toUpperCase()}</span>
              <span className="iam-usercard__body">
                <span className="iam-usercard__name">{u.full_name}</span>
                <span className="iam-usercard__meta">{u.roles.map(roleHeader).join(', ') || '—'}</span>
              </span>
            </button>
          ))}
          {filtered.length === 0 && <div className="empty-state-sm">No users match.</div>}
        </div>
      </aside>

      {/* Right: editor */}
      <section className="iam-editor">
        {!selected ? (
          <div className="empty-state-sm">Select a user to manage their permissions.</div>
        ) : (
          <>
            <div className="iam-editor__head">
              <div>
                <div className="iam-editor__name">{selected.full_name}</div>
                <div className="muted text-sm">{selected.email} · {selected.roles.map(roleHeader).join(', ')}</div>
              </div>
              <div className="iam-editor__actions">
                <span className="iam-count-badge">{totalGranted} permissions</span>
                <button type="button" className="btn btn-sm" onClick={() => setOpen(new Set(modules.map((m) => m.key)))}>Expand all</button>
                <button type="button" className="btn btn-sm" onClick={() => setOpen(new Set())}>Collapse all</button>
                {dirty && <span className="iam-dirty">● Unsaved</span>}
                <button type="button" className="btn btn-primary btn-sm" onClick={onSave} disabled={!dirty || save.isPending}>
                  {save.isPending ? <span className="spinner" /> : 'Save'}
                </button>
              </div>
            </div>

            <div className="iam-legend">
              <span><i className="perm-dot perm-dot--role" /> Granted by role</span>
              <span><i className="perm-dot perm-dot--explicit" /> Explicit grant</span>
              <span><i className="perm-dot perm-dot--none" /> Not granted</span>
            </div>

            <div className="iam-modules">
              {modules.map((m) => {
                const Icon = ICONS[m.icon] ?? Shield;
                const grantedCount = m.actions.filter((a) => {
                  const perm = `${m.key}.${a.key}`;
                  return roleDefault.has(perm) || draft.has(perm);
                }).length;
                const isOpen = open.has(m.key);
                return (
                  <div key={m.key} className="iam-module">
                    <button type="button" className="iam-module__head" onClick={() =>
                      setOpen((prev) => { const n = new Set(prev); n.has(m.key) ? n.delete(m.key) : n.add(m.key); return n; })}>
                      {isOpen ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                      <Icon size={16} />
                      <span className="iam-module__label">{m.label}</span>
                      <span className="iam-module__count">{grantedCount}/{m.actions.length}</span>
                      <span className="iam-module__batch">
                        <span role="button" tabIndex={0} className="link-btn"
                          onClick={(e) => { e.stopPropagation(); grantAll(m); }}>Grant all</span>
                        <span role="button" tabIndex={0} className="link-btn"
                          onClick={(e) => { e.stopPropagation(); clearAll(m); }}>Clear</span>
                      </span>
                    </button>
                    {isOpen && (
                      <div className="iam-actions">
                        {m.actions.map((a) => {
                          const perm = `${m.key}.${a.key}`;
                          const byRole = roleDefault.has(perm);
                          const byExplicit = draft.has(perm);
                          const state = byRole ? 'role' : byExplicit ? 'explicit' : 'none';
                          return (
                            <button key={a.key} type="button"
                              className={`iam-action iam-action--${state}`}
                              title={byRole ? 'Granted by role (inherited)' : byExplicit ? 'Explicitly granted' : 'Not granted'}
                              disabled={byRole}
                              onClick={() => toggle(perm)}>
                              <i className={`perm-dot perm-dot--${state}`} />
                              <span>{a.label}</span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </>
        )}
      </section>
    </div>
  );
}
