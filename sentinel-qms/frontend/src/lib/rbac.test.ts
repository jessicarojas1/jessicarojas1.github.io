import { describe, expect, it } from 'vitest';
import { can, canAny } from './rbac';

describe('rbac', () => {
  it('grants admin every capability', () => {
    expect(can(['admin'], 'ncr.disposition')).toBe(true);
    expect(can(['admin'], 'admin.users')).toBe(true);
  });

  it('limits operators to operational capabilities', () => {
    expect(can(['operator'], 'ncr.write')).toBe(true);
    expect(can(['operator'], 'ncr.disposition')).toBe(false);
    expect(can(['operator'], 'admin.users')).toBe(false);
  });

  it('read_only can read but not write', () => {
    expect(can(['read_only'], 'capa.read')).toBe(true);
    expect(can(['read_only'], 'capa.write')).toBe(false);
  });

  it('returns false for missing roles', () => {
    expect(can(undefined, 'ncr.read')).toBe(false);
    expect(can([], 'ncr.read')).toBe(false);
  });

  it('canAny checks across capabilities', () => {
    expect(canAny(['auditor'], ['audits.write', 'admin.users'])).toBe(true);
    expect(canAny(['auditor'], ['admin.users', 'admin.roles'])).toBe(false);
  });
});
