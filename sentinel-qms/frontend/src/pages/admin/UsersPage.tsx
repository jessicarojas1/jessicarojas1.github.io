import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, KeyRound, Pencil, Plus, Power, Users } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { userHooks, useUserRoles, useResetPassword, type UserRoleOption } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { Modal } from '@/components/Modal';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { StatusBadge } from '@/components/StatusBadge';
import { FormField, Select, TextInput } from '@/components/FormField';
import { ROLE_LABELS, type Role, type User } from '@/types';

/** Slugify a server role name ("Quality Manager") to the RBAC slug form. */
const roleSlug = (name: string): Role =>
  name.trim().toLowerCase().replace(/[\s-]+/g, '_') as Role;

const roleLabel = (name: string): string => ROLE_LABELS[roleSlug(name)] ?? name;

/* -------------------------------------------------------------------------- */
/* Create / Edit form                                                          */
/* -------------------------------------------------------------------------- */

const createSchema = z.object({
  email: z.string().email('A valid email is required'),
  full_name: z.string().min(2, 'Full name is required'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  department: z.string().optional(),
  is_active: z.boolean(),
});
type CreateValues = z.infer<typeof createSchema>;

const editSchema = z.object({
  full_name: z.string().min(2, 'Full name is required'),
  department: z.string().optional(),
  is_active: z.boolean(),
  password: z
    .string()
    .optional()
    .refine((v) => !v || v.length >= 8, 'Password must be at least 8 characters'),
});
type EditValues = z.infer<typeof editSchema>;

/** Reusable multi-select of role names rendered as toggleable chips. */
function RoleSelect({
  options,
  selected,
  onChange,
}: {
  options: UserRoleOption[];
  selected: string[];
  onChange: (next: string[]) => void;
}) {
  const toggle = (name: string) => {
    onChange(
      selected.includes(name) ? selected.filter((r) => r !== name) : [...selected, name],
    );
  };
  return (
    <div className="role-chip-grid">
      {options.map((opt) => {
        const active = selected.includes(opt.name);
        return (
          <button
            type="button"
            key={opt.id}
            className={`role-chip ${active ? 'role-chip--active' : ''}`}
            aria-pressed={active}
            onClick={() => toggle(opt.name)}
          >
            {roleLabel(opt.name)}
          </button>
        );
      })}
      {options.length === 0 && <span className="muted text-sm">No roles available.</span>}
    </div>
  );
}

function CreateUserModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { notify } = useToast();
  const create = userHooks.useCreate();
  const { data: roleOptions = [] } = useUserRoles();
  const [roles, setRoles] = useState<string[]>([]);
  const [roleError, setRoleError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { is_active: true },
  });

  const close = () => {
    reset();
    setRoles([]);
    setRoleError(null);
    onClose();
  };

  const submit = handleSubmit(async (values) => {
    if (roles.length === 0) {
      setRoleError('Select at least one role');
      return;
    }
    try {
      await create.mutateAsync({ ...values, roles } as unknown as Partial<User>);
      notify(`User ${values.email} created`, 'success');
      close();
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  return (
    <Modal
      open={open}
      onClose={close}
      title="Add User"
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={close} disabled={create.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={create.isPending}>
            {create.isPending ? <span className="spinner" /> : 'Create User'}
          </button>
        </>
      }
    >
      {create.isError && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(create.error)}</span>
        </div>
      )}
      <form onSubmit={submit} noValidate>
        <div className="form-grid">
          <FormField label="Full name" htmlFor="cu-name" required error={errors.full_name?.message}>
            <TextInput id="cu-name" {...register('full_name')} placeholder="Jane Doe" />
          </FormField>
          <FormField label="Email" htmlFor="cu-email" required error={errors.email?.message}>
            <TextInput id="cu-email" type="email" {...register('email')} placeholder="jane@example.com" />
          </FormField>
          <FormField label="Password" htmlFor="cu-pw" required error={errors.password?.message}>
            <TextInput id="cu-pw" type="password" {...register('password')} autoComplete="new-password" />
          </FormField>
          <FormField label="Department" htmlFor="cu-dept">
            <TextInput id="cu-dept" {...register('department')} placeholder="Quality" />
          </FormField>
          <FormField label="Status" htmlFor="cu-status">
            <Select id="cu-status" {...register('is_active', {
              setValueAs: (v) => v === 'true' || v === true,
            })}>
              <option value="true">Active</option>
              <option value="false">Inactive</option>
            </Select>
          </FormField>
        </div>
        <FormField label="Roles" required error={roleError ?? undefined}>
          <RoleSelect
            options={roleOptions}
            selected={roles}
            onChange={(next) => {
              setRoles(next);
              if (next.length) setRoleError(null);
            }}
          />
        </FormField>
      </form>
    </Modal>
  );
}

function EditUserModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { notify } = useToast();
  const update = userHooks.useUpdate();
  const { data: roleOptions = [] } = useUserRoles();

  // Map the user's slug roles back to the server role names used as option keys.
  const initialRoles = useMemo(() => {
    return roleOptions
      .filter((opt) => user.roles.includes(roleSlug(opt.name)))
      .map((opt) => opt.name);
  }, [roleOptions, user.roles]);

  const [roles, setRoles] = useState<string[]>(initialRoles);
  const [roleError, setRoleError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<EditValues>({
    resolver: zodResolver(editSchema),
    defaultValues: {
      full_name: user.full_name,
      department: user.department ?? '',
      is_active: user.is_active,
      password: '',
    },
  });

  const submit = handleSubmit(async (values) => {
    if (roles.length === 0) {
      setRoleError('Select at least one role');
      return;
    }
    const payload: Record<string, unknown> = {
      id: user.id,
      full_name: values.full_name,
      department: values.department || undefined,
      is_active: values.is_active,
      roles,
    };
    if (values.password) payload.password = values.password;
    try {
      await update.mutateAsync(payload as Partial<User> & { id: string });
      notify(`User ${user.email} updated`, 'success');
      onClose();
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Edit ${user.full_name}`}
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={onClose} disabled={update.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={update.isPending}>
            {update.isPending ? <span className="spinner" /> : 'Save Changes'}
          </button>
        </>
      }
    >
      {update.isError && (
        <div className="alert alert--danger" style={{ marginBottom: 16 }}>
          <AlertCircle size={16} />
          <span>{getErrorMessage(update.error)}</span>
        </div>
      )}
      <form onSubmit={submit} noValidate>
        <div className="form-grid">
          <FormField label="Full name" htmlFor="eu-name" required error={errors.full_name?.message}>
            <TextInput id="eu-name" {...register('full_name')} />
          </FormField>
          <FormField label="Email" htmlFor="eu-email">
            <TextInput id="eu-email" value={user.email} disabled readOnly />
          </FormField>
          <FormField label="Department" htmlFor="eu-dept">
            <TextInput id="eu-dept" {...register('department')} />
          </FormField>
          <FormField label="Status" htmlFor="eu-status">
            <Select id="eu-status" {...register('is_active', {
              setValueAs: (v) => v === 'true' || v === true,
            })}>
              <option value="true">Active</option>
              <option value="false">Inactive</option>
            </Select>
          </FormField>
          <FormField
            label="New password"
            htmlFor="eu-pw"
            hint="Leave blank to keep the current password."
            error={errors.password?.message}
          >
            <TextInput id="eu-pw" type="password" {...register('password')} autoComplete="new-password" />
          </FormField>
        </div>
        <FormField label="Roles" required error={roleError ?? undefined}>
          <RoleSelect
            options={roleOptions}
            selected={roles}
            onChange={(next) => {
              setRoles(next);
              if (next.length) setRoleError(null);
            }}
          />
        </FormField>
      </form>
    </Modal>
  );
}

function ResetPasswordModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { notify } = useToast();
  const reset = useResetPassword();
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (password.length < 8) {
      setError('Password must be at least 8 characters');
      return;
    }
    try {
      await reset.mutateAsync({ id: user.id, password });
      notify(`Password reset for ${user.email}`, 'success');
      onClose();
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={`Reset password — ${user.full_name}`}
      size="sm"
      footer={
        <>
          <button type="button" className="btn" onClick={onClose} disabled={reset.isPending}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={reset.isPending}>
            {reset.isPending ? <span className="spinner" /> : 'Set Password'}
          </button>
        </>
      }
    >
      <FormField label="New password" htmlFor="rp-pw" required error={error ?? undefined}>
        <TextInput
          id="rp-pw"
          type="password"
          value={password}
          autoComplete="new-password"
          onChange={(e) => {
            setPassword(e.target.value);
            if (e.target.value.length >= 8) setError(null);
          }}
        />
      </FormField>
    </Modal>
  );
}

/* -------------------------------------------------------------------------- */
/* Page                                                                        */
/* -------------------------------------------------------------------------- */

export default function UsersPage() {
  const ctl = useListController({ sort: 'full_name', order: 'asc' });
  const { data, isLoading, error } = userHooks.useList(ctl.params);
  const { canEdit } = usePagePerms();
  const writable = canEdit('users');
  const { notify } = useToast();
  const qc = useQueryClient();
  const update = userHooks.useUpdate();

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [resetting, setResetting] = useState<User | null>(null);
  const [deactivating, setDeactivating] = useState<User | null>(null);

  const setActive = async (user: User, isActive: boolean) => {
    try {
      await update.mutateAsync({ id: user.id, is_active: isActive } as Partial<User> & { id: string });
      notify(`${user.full_name} ${isActive ? 'reactivated' : 'deactivated'}`, 'success');
      qc.invalidateQueries({ queryKey: ['users'] });
      setDeactivating(null);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const columns: Column<User>[] = [
    {
      key: 'full_name',
      header: 'Name',
      sortable: true,
      render: (r) => <strong>{r.full_name}</strong>,
    },
    { key: 'email', header: 'Email', render: (r) => <a href={`mailto:${r.email}`}>{r.email}</a> },
    { key: 'department', header: 'Department', render: (r) => r.department ?? '—' },
    {
      key: 'roles',
      header: 'Roles',
      render: (r) => (
        <div className="tag-list">
          {r.roles.map((role) => (
            <span key={role} className="pill">
              {ROLE_LABELS[role] ?? role}
            </span>
          ))}
        </div>
      ),
    },
    {
      key: 'is_active',
      header: 'Status',
      render: (r) => <StatusBadge status={r.is_active ? 'active' : 'inactive'} label={r.is_active ? 'Active' : 'Inactive'} />,
    },
    { key: 'created_at', header: 'Created', sortable: true, render: (r) => formatDate(r.created_at) },
    ...(writable
      ? [
          {
            key: 'actions',
            header: '',
            align: 'right' as const,
            render: (r: User) => (
              <div className="row-actions">
                <button
                  type="button"
                  className="btn btn-sm btn-icon"
                  title="Edit user"
                  aria-label="Edit user"
                  onClick={() => setEditing(r)}
                >
                  <Pencil size={14} />
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-icon"
                  title="Reset password"
                  aria-label="Reset password"
                  onClick={() => setResetting(r)}
                >
                  <KeyRound size={14} />
                </button>
                <button
                  type="button"
                  className={`btn btn-sm btn-icon ${r.is_active ? 'btn-danger-ghost' : ''}`}
                  title={r.is_active ? 'Deactivate user' : 'Reactivate user'}
                  aria-label={r.is_active ? 'Deactivate user' : 'Reactivate user'}
                  onClick={() => (r.is_active ? setDeactivating(r) : void setActive(r, true))}
                >
                  <Power size={14} />
                </button>
              </div>
            ),
          },
        ]
      : []),
  ];

  return (
    <>
      <PageHeader
        title="User Administration"
        icon={<Users size={22} />}
        subtitle="Manage user accounts, roles, and access."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }]}
        actions={
          writable ? (
            <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
              <Plus size={15} /> Add User
            </button>
          ) : undefined
        }
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search name, email…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        emptyTitle="No users found"
        emptyDescription="Add a user to get started."
      />

      {creating && <CreateUserModal open onClose={() => setCreating(false)} />}
      {editing && <EditUserModal user={editing} onClose={() => setEditing(null)} />}
      {resetting && <ResetPasswordModal user={resetting} onClose={() => setResetting(null)} />}
      <ConfirmDialog
        open={Boolean(deactivating)}
        title="Deactivate user"
        message={
          deactivating
            ? `Deactivate ${deactivating.full_name}? They will no longer be able to sign in.`
            : ''
        }
        confirmLabel="Deactivate"
        destructive
        loading={update.isPending}
        onConfirm={() => deactivating && setActive(deactivating, false)}
        onCancel={() => setDeactivating(null)}
      />
    </>
  );
}
