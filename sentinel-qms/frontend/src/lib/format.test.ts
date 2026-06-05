import { describe, expect, it } from 'vitest';
import { daysUntil, formatBytes, humanize, initials, isOverdue } from './format';

describe('format helpers', () => {
  it('humanizes snake_case', () => {
    expect(humanize('disposition_pending')).toBe('Disposition Pending');
    expect(humanize(null)).toBe('—');
  });

  it('computes initials', () => {
    expect(initials('Dana Reyes')).toBe('DR');
    expect(initials('cher')).toBe('C');
    expect(initials(undefined)).toBe('?');
  });

  it('detects overdue dates', () => {
    const past = new Date(Date.now() - 86_400_000 * 3).toISOString();
    const future = new Date(Date.now() + 86_400_000 * 3).toISOString();
    expect(isOverdue(past)).toBe(true);
    expect(isOverdue(future)).toBe(false);
    expect(daysUntil(undefined)).toBeNull();
  });

  it('formats bytes', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(2048)).toBe('2.0 KB');
  });
});
