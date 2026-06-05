import { Users } from 'lucide-react';
import { userHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { ROLE_LABELS, type User } from '@/types';

export default function UsersPage() {
  const ctl = useListController({ sort: 'full_name', order: 'asc' });
  const { data, isLoading, error } = userHooks.useList(ctl.params);

  const columns: Column<User>[] = [
    { key: 'full_name', header: 'Name', sortable: true, render: (r) => <strong>{r.full_name}</strong> },
    { key: 'username', header: 'Username', render: (r) => <span className="mono">{r.username}</span> },
    { key: 'email', header: 'Email', render: (r) => <a href={`mailto:${r.email}`}>{r.email}</a> },
    { key: 'department', header: 'Department', render: (r) => r.department ?? '—' },
    {
      key: 'roles',
      header: 'Roles',
      render: (r) => (
        <div className="tag-list">
          {r.roles.map((role) => (
            <span key={role} className="pill">{ROLE_LABELS[role]}</span>
          ))}
        </div>
      ),
    },
    {
      key: 'is_active',
      header: 'Status',
      render: (r) =>
        r.is_active ? (
          <span className="badge badge--success">Active</span>
        ) : (
          <span className="badge badge--neutral">Disabled</span>
        ),
    },
    { key: 'last_login_at', header: 'Last Login', render: (r) => formatDateTime(r.last_login_at) },
  ];

  return (
    <>
      <PageHeader
        title="User Administration"
        icon={<Users size={22} />}
        subtitle="Manage user accounts, roles, and access."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search name, username, email…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
      />
    </>
  );
}
