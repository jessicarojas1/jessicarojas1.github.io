import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  useContracts,
  useCreateContract,
  useCreateCustomer,
  useCustomers,
  useUpdateContract,
  useUpdateCustomer,
} from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { ContractStatus, ContractSummary, Customer, CustomerStatus } from '@/types';

const CUST_STATUSES: CustomerStatus[] = ['active', 'inactive'];
const CONTRACT_STATUSES: ContractStatus[] = ['active', 'on_hold', 'closed'];
const label = (s: string) => s.replace(/_/g, ' ');

function CustomersSection({ writable }: { writable: boolean }) {
  const { data, isLoading } = useCustomers();
  const create = useCreateCustomer();
  const update = useUpdateCustomer();
  const { notify } = useToast();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [country, setCountry] = useState('');
  const [fStatus, setFStatus] = useState('');

  const list = data ?? [];
  const filtered = list.filter((c) => !fStatus || c.status === fStatus);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!code.trim() || !name.trim()) return;
    create.mutate(
      { code: code.trim(), name: name.trim(), country: country.trim() || null },
      {
        onSuccess: () => {
          setCode('');
          setName('');
          setCountry('');
          notify('Customer added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <div className="card">
      <div className="card__header"><div className="card__title">Customers</div></div>
      <FilterBar active={fStatus ? 1 : 0}>
        <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
          <option value="">All statuses</option>
          {CUST_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
      </FilterBar>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr><th>Code</th><th>Name</th><th>Country</th><th>Contracts</th><th>Status</th></tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={5}><span className="spinner" /> Loading…</td></tr>
            ) : filtered.length ? (
              filtered.map((c: Customer) => (
                <tr key={c.id}>
                  <td className="mono">{c.code}</td>
                  <td>{c.name}</td>
                  <td>{c.country ?? '—'}</td>
                  <td>{c.contract_count}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={c.status}
                        onChange={(e) =>
                          update.mutate(
                            { id: c.id, payload: { status: e.target.value as CustomerStatus } },
                            { onError: (err) => notify(getErrorMessage(err), 'danger') },
                          )
                        }
                      >
                        {CUST_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                      </select>
                    ) : (
                      label(c.status)
                    )}
                  </td>
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={5}><div className="empty-state-sm">{list.length ? 'No customers match the selected filters.' : 'No customers.'}</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} aria-label="Customer code" />
          <input className="input" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} aria-label="Customer name" />
          <input className="input" placeholder="Country" value={country} onChange={(e) => setCountry(e.target.value)} aria-label="Country" />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add customer</button>
        </form>
      )}
    </div>
  );
}

function ContractsSection({ writable, customers }: { writable: boolean; customers: Customer[] }) {
  const { data, isLoading } = useContracts();
  const create = useCreateContract();
  const update = useUpdateContract();
  const { notify } = useToast();
  const [customerId, setCustomerId] = useState('');
  const [number, setNumber] = useState('');
  const [title, setTitle] = useState('');
  const [itar, setItar] = useState(false);
  const [fStatus, setFStatus] = useState('');
  const [fCustomer, setFCustomer] = useState('');
  const [fItar, setFItar] = useState('');
  const custName = (id: number) => customers.find((c) => c.id === id)?.name ?? `#${id}`;

  const contracts = data ?? [];
  const filtered = contracts.filter(
    (k) =>
      (!fStatus || k.status === fStatus) &&
      (!fCustomer || k.customer_id === Number(fCustomer)) &&
      (!fItar || String(k.itar_controlled) === fItar),
  );
  const activeFilters = (fStatus ? 1 : 0) + (fCustomer ? 1 : 0) + (fItar ? 1 : 0);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!customerId || !number.trim() || !title.trim()) return;
    create.mutate(
      { customer_id: Number(customerId), contract_number: number.trim(), title: title.trim(), itar_controlled: itar },
      {
        onSuccess: () => {
          setNumber('');
          setTitle('');
          setItar(false);
          notify('Contract added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <div className="card">
      <div className="card__header"><div className="card__title">Contracts</div></div>
      <FilterBar active={activeFilters}>
        <select className="input field" value={fStatus} onChange={(e) => setFStatus(e.target.value)} aria-label="Filter by status">
          <option value="">All statuses</option>
          {CONTRACT_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select className="input field" value={fCustomer} onChange={(e) => setFCustomer(e.target.value)} aria-label="Filter by customer">
          <option value="">All customers</option>
          {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select className="input field" value={fItar} onChange={(e) => setFItar(e.target.value)} aria-label="Filter by ITAR">
          <option value="">ITAR: any</option>
          <option value="true">ITAR only</option>
          <option value="false">Non-ITAR</option>
        </select>
      </FilterBar>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr><th>Number</th><th>Customer</th><th>Title</th><th>DPAS</th><th>ITAR</th><th>Status</th></tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={6}><span className="spinner" /> Loading…</td></tr>
            ) : filtered.length ? (
              filtered.map((k: ContractSummary) => (
                <tr key={k.id}>
                  <td className="mono"><Link to={`/customers/contracts/${k.id}`} className="link-btn">{k.contract_number}</Link></td>
                  <td>{custName(k.customer_id)}</td>
                  <td>{k.title}</td>
                  <td className="mono">{k.dpas_rating ?? '—'}</td>
                  <td>{k.itar_controlled ? <span className="cfp-risk cfp-risk--high">ITAR</span> : '—'}</td>
                  <td>
                    {writable ? (
                      <select
                        className="input input-sm"
                        value={k.status}
                        onChange={(e) =>
                          update.mutate(
                            { id: k.id, payload: { status: e.target.value as ContractStatus } },
                            { onError: (err) => notify(getErrorMessage(err), 'danger') },
                          )
                        }
                      >
                        {CONTRACT_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                      </select>
                    ) : (
                      label(k.status)
                    )}
                  </td>
                </tr>
              ))
            ) : (
              <tr className="empty-row"><td colSpan={6}><div className="empty-state-sm">{contracts.length ? 'No contracts match the selected filters.' : 'No contracts.'}</div></td></tr>
            )}
          </tbody>
        </table>
      </div>
      {writable && (
        <form className="std-new" onSubmit={add}>
          <select className="input" value={customerId} onChange={(e) => setCustomerId(e.target.value)} aria-label="Customer">
            <option value="">Customer…</option>
            {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <input className="input" placeholder="Contract #" value={number} onChange={(e) => setNumber(e.target.value)} aria-label="Contract number" />
          <input className="input" placeholder="Title" value={title} onChange={(e) => setTitle(e.target.value)} aria-label="Contract title" />
          <label className="checkbox-row"><input type="checkbox" className="checkbox" checked={itar} onChange={(e) => setItar(e.target.checked)} /> ITAR</label>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add contract</button>
        </form>
      )}
    </div>
  );
}

export default function CustomersPage() {
  const { canEdit } = usePagePerms();
  const writable = canEdit('suppliers');
  const { data: customers, error } = useCustomers();

  return (
    <>
      <PageHeader
        title="Customers & Contracts"
        subtitle="Customer register and contracts with DPAS / ITAR / DFARS flags and requirement flow-down."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Customers & Contracts' }]}
      />
      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : (
        <div className="stack">
          <ContractsSection writable={writable} customers={customers ?? []} />
          <CustomersSection writable={writable} />
        </div>
      )}
    </>
  );
}
