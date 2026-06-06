import { Fragment, useEffect, useMemo, useState } from 'react';
import { KeyRound } from 'lucide-react';
import {
  usePages,
  useRolePermissions,
  useSaveRolePermissions,
  type RolePermissionMatrix,
} from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import type { PermLevel } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { ROLE_LABELS, type Role } from '@/types';

const LEVELS: PermLevel[] = ['none', 'view', 'edit'];
const LEVEL_LABEL: Record<PermLevel, string> = {
  none: 'None',
  view: 'View',
  edit: 'Edit',
};

/** Turn a server role name ("Quality Manager"/"admin") into a display header. */
const roleHeader = (name: string): string => {
  const slug = name.trim().toLowerCase().replace(/[\s-]+/g, '_') as Role;
  return ROLE_LABELS[slug] ?? name;
};

const clone = (m: RolePermissionMatrix): RolePermissionMatrix =>
  JSON.parse(JSON.stringify(m));

export default function PermissionsPage() {
  const { notify } = useToast();
  const pagesQ = usePages();
  const matrixQ = useRolePermissions();
  const save = useSaveRolePermissions();

  const [draft, setDraft] = useState<RolePermissionMatrix>({});

  // Seed the editable draft whenever the server matrix loads/refreshes.
  useEffect(() => {
    if (matrixQ.data) setDraft(clone(matrixQ.data));
  }, [matrixQ.data]);

  const roleNames = useMemo(() => Object.keys(matrixQ.data ?? {}), [matrixQ.data]);

  // Group pages by their `group` field, preserving first-seen order.
  const grouped = useMemo(() => {
    const out: { group: string; pages: typeof pagesQ.data }[] = [];
    const index = new Map<string, number>();
    for (const p of pagesQ.data ?? []) {
      if (!index.has(p.group)) {
        index.set(p.group, out.length);
        out.push({ group: p.group, pages: [] });
      }
      out[index.get(p.group)!].pages!.push(p);
    }
    return out;
  }, [pagesQ.data]);

  const dirty = useMemo(() => {
    if (!matrixQ.data) return false;
    return JSON.stringify(draft) !== JSON.stringify(matrixQ.data);
  }, [draft, matrixQ.data]);

  const levelFor = (role: string, page: string): PermLevel =>
    draft[role]?.[page] ?? 'none';

  const setLevel = (role: string, page: string, level: PermLevel) => {
    setDraft((prev) => ({
      ...prev,
      [role]: { ...(prev[role] ?? {}), [page]: level },
    }));
  };

  const onReset = () => {
    if (matrixQ.data) setDraft(clone(matrixQ.data));
  };

  const onSave = async () => {
    try {
      await save.mutateAsync(draft);
      notify('Permissions saved', 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const loading = pagesQ.isLoading || matrixQ.isLoading;
  const error = pagesQ.error ?? matrixQ.error;

  return (
    <>
      <PageHeader
        title="Page Permissions"
        icon={<KeyRound size={22} />}
        subtitle="Control which roles can view or edit each page."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Permissions' }]}
        actions={
          <>
            <button
              type="button"
              className="btn"
              onClick={onReset}
              disabled={!dirty || save.isPending}
            >
              Reset
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={onSave}
              disabled={!dirty || save.isPending}
            >
              {save.isPending ? <span className="spinner" /> : 'Save changes'}
            </button>
          </>
        }
      />

      <div className="card">
        {loading ? (
          <div className="loading-block" style={{ minHeight: 200 }}>
            <span className="spinner spinner--lg" />
          </div>
        ) : error ? (
          <div className="empty-state-sm" style={{ color: 'var(--danger)' }}>
            {getErrorMessage(error)}
          </div>
        ) : roleNames.length === 0 || (pagesQ.data ?? []).length === 0 ? (
          <EmptyState
            title="No permission data"
            description="No pages or roles were returned by the server."
          />
        ) : (
          <div className="table-wrap perm-matrix-wrap">
            <table className="data-table perm-matrix">
              <thead>
                <tr>
                  <th className="perm-matrix__page-col">Page</th>
                  {roleNames.map((role) => (
                    <th key={role} style={{ textAlign: 'center' }}>
                      {roleHeader(role)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {grouped.map((g) => (
                  <Fragment key={g.group}>
                    <tr>
                      <td
                        className="perm-matrix__page-col"
                        colSpan={roleNames.length + 1}
                        style={{ background: 'var(--surface-2)' }}
                      >
                        <strong className="text-sm">{g.group}</strong>
                      </td>
                    </tr>
                    {g.pages!.map((page) => (
                      <tr key={page.key}>
                        <td className="perm-matrix__page-col">{page.label}</td>
                        {roleNames.map((role) => (
                          <td key={role} style={{ textAlign: 'center' }}>
                            <select
                              className="select select--sm"
                              value={levelFor(role, page.key)}
                              aria-label={`${roleHeader(role)} access to ${page.label}`}
                              onChange={(e) =>
                                setLevel(role, page.key, e.target.value as PermLevel)
                              }
                            >
                              {LEVELS.map((lvl) => (
                                <option key={lvl} value={lvl}>
                                  {LEVEL_LABEL[lvl]}
                                </option>
                              ))}
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
        )}
      </div>
    </>
  );
}
