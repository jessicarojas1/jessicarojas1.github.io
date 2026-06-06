import { useNavigate } from 'react-router-dom';
import { Truck } from 'lucide-react';
import { supplierHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Supplier } from '@/types';

export default function SupplierListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'name', order: 'asc' });
  const { data, isLoading, error } = supplierHooks.useList(ctl.params);

  const columns: Column<Supplier>[] = [
    { key: 'supplier_code', header: 'Code', sortable: true, width: '100px', render: (r) => <span className="mono">{r.supplier_code}</span> },
    { key: 'name', header: 'Supplier', sortable: true, render: (r) => <strong>{r.name}</strong> },
    { key: 'status', header: 'ASL Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'certification', header: 'Certification', render: (r) => r.certification ?? '—' },
    { key: 'country', header: 'Country', render: (r) => r.country ?? '—' },
  ];

  return (
    <>
      <PageHeader
        title="Approved Supplier List"
        icon={<Truck size={22} />}
        subtitle="Supplier qualification, scorecards, and corrective action requests (SCAR)."
        breadcrumbs={[{ label: 'Suppliers' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/suppliers/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search supplier name or code…"
        sort={ctl.sort}
        order={ctl.order}
        onSortChange={ctl.onSortChange}
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        exportFilename="suppliers"
        filters={
          <div className="field">
            <Select
              aria-label="Filter by status"
              value={ctl.filters.status ?? ''}
              onChange={(e) => ctl.setFilter('status', e.target.value)}
            >
              <option value="">All statuses</option>
              {['prospective', 'approved', 'conditional', 'probation', 'disqualified'].map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </Select>
          </div>
        }
      />
    </>
  );
}
