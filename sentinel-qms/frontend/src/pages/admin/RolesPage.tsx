import { Fragment } from 'react';
import { Award, Check } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { can, type Capability } from '@/lib/rbac';
import { ROLE_LABELS, type Role } from '@/types';

const ROLES: Role[] = [
  'admin',
  'quality_manager',
  'quality_engineer',
  'auditor',
  'supplier_quality',
  'operator',
  'read_only',
];

const CAPABILITY_GROUPS: { label: string; capabilities: { key: Capability; label: string }[] }[] = [
  {
    label: 'Documents',
    capabilities: [
      { key: 'documents.read', label: 'View' },
      { key: 'documents.write', label: 'Edit' },
      { key: 'documents.approve', label: 'Approve' },
    ],
  },
  {
    label: 'Nonconformances',
    capabilities: [
      { key: 'ncr.read', label: 'View' },
      { key: 'ncr.write', label: 'Edit' },
      { key: 'ncr.disposition', label: 'Disposition' },
    ],
  },
  {
    label: 'CAPA',
    capabilities: [
      { key: 'capa.read', label: 'View' },
      { key: 'capa.write', label: 'Edit' },
      { key: 'capa.close', label: 'Close' },
    ],
  },
  {
    label: 'Change Control',
    capabilities: [
      { key: 'changes.read', label: 'View' },
      { key: 'changes.write', label: 'Edit' },
      { key: 'changes.approve', label: 'Approve' },
    ],
  },
  {
    label: 'Administration',
    capabilities: [
      { key: 'admin.users', label: 'Manage Users' },
      { key: 'admin.roles', label: 'Manage Roles' },
    ],
  },
];

export default function RolesPage() {
  return (
    <>
      <PageHeader
        title="Roles & Permissions"
        icon={<Award size={22} />}
        subtitle="Role-based access control (RBAC) capability matrix."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Roles' }]}
      />

      <div className="card">
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Capability</th>
                {ROLES.map((r) => (
                  <th key={r} style={{ textAlign: 'center' }}>{ROLE_LABELS[r]}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {CAPABILITY_GROUPS.map((group) => (
                <Fragment key={group.label}>
                  <tr>
                    <td colSpan={ROLES.length + 1} style={{ background: 'var(--surface-2)' }}>
                      <strong className="text-sm">{group.label}</strong>
                    </td>
                  </tr>
                  {group.capabilities.map((cap) => (
                    <tr key={cap.key}>
                      <td>{cap.label}</td>
                      {ROLES.map((role) => (
                        <td key={role} style={{ textAlign: 'center' }}>
                          {can([role], cap.key) ? (
                            <Check size={16} style={{ color: 'var(--success)' }} aria-label="Granted" />
                          ) : (
                            <span className="muted" aria-label="Not granted">—</span>
                          )}
                        </td>
                      ))}
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
