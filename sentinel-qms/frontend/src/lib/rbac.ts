import type { Role } from '@/types';

/**
 * Application capabilities used to gate UI actions. The backend remains the
 * authoritative enforcement point; this only controls what the UI offers.
 */
export type Capability =
  | 'documents.read'
  | 'documents.write'
  | 'documents.approve'
  | 'ncr.read'
  | 'ncr.write'
  | 'ncr.disposition'
  | 'capa.read'
  | 'capa.write'
  | 'capa.close'
  | 'audits.read'
  | 'audits.write'
  | 'suppliers.read'
  | 'suppliers.write'
  | 'calibration.read'
  | 'calibration.write'
  | 'training.read'
  | 'training.write'
  | 'changes.read'
  | 'changes.write'
  | 'changes.approve'
  | 'risks.read'
  | 'risks.write'
  | 'inspections.read'
  | 'inspections.write'
  | 'mgmt_reviews.read'
  | 'mgmt_reviews.write'
  | 'complaints.read'
  | 'complaints.write'
  | 'quality_objectives.read'
  | 'quality_objectives.write'
  | 'improvements.read'
  | 'improvements.write'
  | 'csat.read'
  | 'csat.write'
  | 'admin.users'
  | 'admin.roles'
  | 'docs.read';

const ALL_READ: Capability[] = [
  'docs.read',
  'documents.read',
  'ncr.read',
  'capa.read',
  'audits.read',
  'suppliers.read',
  'calibration.read',
  'training.read',
  'changes.read',
  'risks.read',
  'inspections.read',
  'mgmt_reviews.read',
  'complaints.read',
  'quality_objectives.read',
  'improvements.read',
  'csat.read',
];

const ROLE_CAPABILITIES: Record<Role, Capability[]> = {
  admin: [
    ...ALL_READ,
    'documents.write',
    'documents.approve',
    'ncr.write',
    'ncr.disposition',
    'capa.write',
    'capa.close',
    'audits.write',
    'suppliers.write',
    'calibration.write',
    'training.write',
    'changes.write',
    'changes.approve',
    'risks.write',
    'inspections.write',
    'mgmt_reviews.write',
    'complaints.write',
    'quality_objectives.write',
    'improvements.write',
    'csat.write',
    'admin.users',
    'admin.roles',
  ],
  quality_manager: [
    ...ALL_READ,
    'documents.write',
    'documents.approve',
    'ncr.write',
    'ncr.disposition',
    'capa.write',
    'capa.close',
    'audits.write',
    'suppliers.write',
    'changes.write',
    'changes.approve',
    'risks.write',
    'mgmt_reviews.write',
    'complaints.write',
    'quality_objectives.write',
    'improvements.write',
    'csat.write',
  ],
  quality_engineer: [
    ...ALL_READ,
    'documents.write',
    'ncr.write',
    'ncr.disposition',
    'capa.write',
    'changes.write',
    'risks.write',
    'inspections.write',
    'complaints.write',
    'quality_objectives.write',
    'improvements.write',
    'csat.write',
  ],
  auditor: [...ALL_READ, 'audits.write'],
  supplier_quality: [
    ...ALL_READ,
    'suppliers.write',
    'ncr.write',
    'inspections.write',
  ],
  operator: [
    'docs.read',
    'ncr.read',
    'ncr.write',
    'inspections.read',
    'inspections.write',
    'calibration.read',
    'documents.read',
    'training.read',
  ],
  read_only: [...ALL_READ],
  // Customer: no module capabilities — access is limited to "Shared with Me".
  customer: [],
};

export function can(roles: Role[] | undefined, capability: Capability): boolean {
  if (!roles?.length) return false;
  return roles.some((role) => ROLE_CAPABILITIES[role]?.includes(capability));
}

export function canAny(roles: Role[] | undefined, capabilities: Capability[]): boolean {
  return capabilities.some((c) => can(roles, c));
}
