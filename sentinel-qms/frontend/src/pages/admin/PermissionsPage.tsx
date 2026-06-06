import { Fragment, useEffect, useMemo, useState } from 'react';
import { KeyRound } from 'lucide-react';
import {
  usePages,
  useRolePermissions,
  useSaveRolePermissions,
  type RolePermissionMatrix,
  type PageDef,
} from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import type { PermLevel } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { IamUserManager } from '@/components/IamUserManager';
import { ROLE_LABELS, type Role } from '@/types';

const LEVELS: PermLevel[] = ['none', 'view', 'edit'];
const LEVEL_LABEL: Record<string, string> = {
  inherit: 'Inherit',
  none: 'None',
  view: 'View',
  edit: 'Edit',
};

const roleHeader = (name: string): string => {
  const slug = name.trim().toLowerCase().replace(/[\s-]+/g, '_') as Role;
  return ROLE_LABELS[slug] ?? name;
};

const clone = (m: RolePermissionMatrix): RolePermissionMatrix =>
  JSON.parse(JSON.stringify(m));

/** Group pages by their `group` field, preserving first-seen order. */
function useGroupedPages(pages: PageDef[] | undefined) {
  return useMemo(() => {
    const out: { group: string; pages: PageDef[] }[] = [];
    const index = new Map<string, number>();
    for (const p of pages ?? []) {
      if (!index.has(p.group)) {
        index.set(p.group, out.length);
        out.push({ group: p.group, pages: [] });
      }
      out[index.get(p.group)!].pages.push(p);
    }
    return out;
  }, [pages]);
}

/* -------------------------------------------------------------------------- */
/* By Role                                                                     */
/* -------------------------------------------------------------------------- */

function RoleMatrix() {
  const { notify } = useToast();
  const pagesQ = usePages();
  const matrixQ = useRolePermissions();
  const save = useSaveRolePermissions();
  const [draft, setDraft] = useState<RolePermissionMatrix>({});

  useEffect(() => {
    if (matrixQ.data) setDraft(clone(matrixQ.data));
  }, [matrixQ.data]);

  const roleNames = useMemo(() => Object.keys(matrixQ.data ?? {}), [matrixQ.data]);
  const grouped = useGroupedPages(pagesQ.data);
  const dirty = useMemo(
    () => Boolean(matrixQ.data) && JSON.stringify(draft) !== JSON.stringify(matrixQ.data),
    [draft, matrixQ.data],
  );

  const setLevel = (role: string, page: string, level: PermLevel) =>
    setDraft((prev) => ({ ...prev, [role]: { ...(prev[role] ?? {}), [page]: level } }));

  const onSave = async () => {
    try {
      await save.mutateAsync(draft);
      notify('Role permissions saved', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const loading = pagesQ.isLoading || matrixQ.isLoading;
  const error = pagesQ.error ?? matrixQ.error;

  if (loading) return <div className="loading-block" style={{ minHeight: 200 }}><span className="spinner spinner--lg" /></div>;
  if (error) return <div className="empty-state-sm" style={{ color: 'var(--danger)' }}>{getErrorMessage(error)}</div>;
  if (roleNames.length === 0) return <EmptyState title="No permission data" description="No roles were returned." />;

  return (
    <>
      <div className="perm-toolbar">
        <span className="muted text-sm">Set each role&apos;s access to each page.</span>
        <button type="button" className="btn" onClick={() => matrixQ.data && setDraft(clone(matrixQ.data))} disabled={!dirty || save.isPending}>Reset</button>
        <button type="button" className="btn btn-primary" onClick={onSave} disabled={!dirty || save.isPending}>
          {save.isPending ? <span className="spinner" /> : 'Save changes'}
        </button>
      </div>
      <div className="table-wrap perm-matrix-wrap">
        <table className="data-table perm-matrix">
          <thead>
            <tr>
              <th className="perm-matrix__page-col">Page</th>
              {roleNames.map((role) => <th key={role} style={{ textAlign: 'center' }}>{roleHeader(role)}</th>)}
            </tr>
          </thead>
          <tbody>
            {grouped.map((g) => (
              <Fragment key={g.group}>
                <tr><td className="perm-matrix__page-col" colSpan={roleNames.length + 1} style={{ background: 'var(--surface-2)' }}><strong className="text-sm">{g.group}</strong></td></tr>
                {g.pages.map((page) => (
                  <tr key={page.key}>
                    <td className="perm-matrix__page-col">{page.label}</td>
                    {roleNames.map((role) => (
                      <td key={role} style={{ textAlign: 'center' }}>
                        <select className="select select--sm" value={draft[role]?.[page.key] ?? 'none'}
                          aria-label={`${roleHeader(role)} access to ${page.label}`}
                          onChange={(e) => setLevel(role, page.key, e.target.value as PermLevel)}>
                          {LEVELS.map((lvl) => <option key={lvl} value={lvl}>{LEVEL_LABEL[lvl]}</option>)}
                        </select>
                      </td>
                    ))}
                  </tr>
                ))}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

/* -------------------------------------------------------------------------- */
/* Page                                                                        */
/* -------------------------------------------------------------------------- */

export default function PermissionsPage() {
  const [tab, setTab] = useState<'roles' | 'users'>('roles');
  return (
    <>
      <PageHeader
        title="Permission Management"
        icon={<KeyRound size={22} />}
        subtitle="Control access by role, then fine-tune per individual user."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Permissions' }]}
      />
      <div className="tab-bar">
        <button type="button" className={`tab ${tab === 'roles' ? 'tab--active' : ''}`} onClick={() => setTab('roles')}>By Role</button>
        <button type="button" className={`tab ${tab === 'users' ? 'tab--active' : ''}`} onClick={() => setTab('users')}>By User</button>
      </div>
      <div className="card">
        <div className="card__body">{tab === 'roles' ? <RoleMatrix /> : <IamUserManager />}</div>
      </div>
    </>
  );
}
