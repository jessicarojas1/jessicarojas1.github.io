import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Archive, Lock, Plus } from 'lucide-react';
import { useRetentionPolicies } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import { StatusBadge } from '@/components/StatusBadge';
import { RetentionFormModal } from './RetentionFormModal';
import type { RetentionPolicy } from '@/types';

const CATEGORIES = [
  'quality_records',
  'design_records',
  'supplier_records',
  'calibration_records',
  'training_records',
  'audit_records',
  'capa_records',
  'contract_records',
  'inspection_records',
  'other',
];
const STATUSES = ['draft', 'active', 'superseded'];
const label = (s: string) => s.replace(/_/g, ' ');

function retentionLabel(p: RetentionPolicy): string {
  if (p.disposition_action === 'permanent' || p.retention_years == null) return 'Permanent';
  return `${p.retention_years} yr${p.retention_years === 1 ? '' : 's'}`;
}

export default function RetentionListPage() {
  const navigate = useNavigate();
  const { data, isLoading, error } = useRetentionPolicies();
  const { canEdit } = usePagePerms();
  const writable = canEdit('retention');
  const [createOpen, setCreateOpen] = useState(false);
  const [fStatus, setFStatus] = useState('');
  const [fCategory, setFCategory] = useState('');

  const rows = data ?? [];
  const filtered = rows.filter(
    (p) =>
      (!fStatus || p.status === fStatus) && (!fCategory || p.record_category === fCategory),
  );
  const activeFilters = (fStatus ? 1 : 0) + (fCategory ? 1 : 0);

  return (
    <>
      <PageHeader
        title="Retention Schedule"
        icon={<Archive size={22} />}
        subtitle="Documented records retention & disposition schedule. Each policy is a retention rule per record category; disposition is a scheduled, manually-performed action — records are never destroyed automatically."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Retention Schedule' }]}
        actions={
          writable && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New Policy
            </button>
          )
        }
      />

      {error ? (
        <div className="card">
          <div className="card__body">
            <EmptyState title="Unable to load" description={getErrorMessage(error)} />
          </div>
        </div>
      ) : isLoading || !data ? (
        <div className="card">
          <div className="card__body">
            <span className="spinner" /> Loading…
          </div>
        </div>
      ) : rows.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <EmptyState
              title="No retention policies yet"
              description="Define a retention policy per record category to document how long records are kept and the scheduled disposition action."
            />
          </div>
        </div>
      ) : (
        <div className="card">
          <FilterBar active={activeFilters}>
            <select
              className="input field"
              value={fStatus}
              onChange={(e) => setFStatus(e.target.value)}
              aria-label="Filter by status"
            >
              <option value="">All statuses</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {label(s)}
                </option>
              ))}
            </select>
            <select
              className="input field"
              value={fCategory}
              onChange={(e) => setFCategory(e.target.value)}
              aria-label="Filter by category"
            >
              <option value="">All categories</option>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {label(c)}
                </option>
              ))}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Policy #</th>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Trigger</th>
                  <th>Retention</th>
                  <th>Disposition</th>
                  <th>Legal hold</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row">
                    <td colSpan={8}>
                      <div className="empty-state-sm">No policies match the selected filters.</div>
                    </td>
                  </tr>
                ) : (
                  filtered.map((p) => (
                    <tr
                      key={p.id}
                      className="clickable"
                      onClick={() => navigate(`/retention/${p.id}`)}
                    >
                      <td className="mono">{p.policy_number}</td>
                      <td>
                        <strong>{p.title}</strong>
                      </td>
                      <td>{label(p.record_category)}</td>
                      <td>{label(p.retention_trigger)}</td>
                      <td>{retentionLabel(p)}</td>
                      <td>{label(p.disposition_action)}</td>
                      <td>
                        {p.legal_hold ? (
                          <span className="badge badge--warning">
                            <Lock size={12} /> On hold
                          </span>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        <StatusBadge status={p.status} />
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <RetentionFormModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onSaved={(id) => {
          setCreateOpen(false);
          navigate(`/retention/${id}`);
        }}
      />
    </>
  );
}
