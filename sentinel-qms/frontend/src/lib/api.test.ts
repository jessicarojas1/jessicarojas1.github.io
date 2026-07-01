import { AxiosError, AxiosHeaders } from 'axios';
import { describe, expect, it } from 'vitest';
import { getErrorCode, getErrorMessage, isStaleWriteError } from './api';

/** Build an AxiosError carrying the app's error envelope for a given status. */
function envelopeError(
  status: number,
  code: string,
  message: string,
): AxiosError {
  const err = new AxiosError('Request failed with status code ' + status);
  err.response = {
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { error: { code, message, request_id: 'req-1' } },
  };
  return err;
}

describe('api error helpers', () => {
  it('surfaces the app error envelope message', () => {
    const err = envelopeError(409, 'stale_write', 'This record was modified by someone else.');
    expect(getErrorMessage(err)).toBe('This record was modified by someone else.');
  });

  it('detects a stale-write (optimistic-concurrency) conflict', () => {
    const stale = envelopeError(409, 'stale_write', 'Reload and reapply your changes.');
    expect(isStaleWriteError(stale)).toBe(true);
    expect(getErrorCode(stale)).toBe('stale_write');
  });

  it('does not flag other 409 conflicts as stale writes', () => {
    const other = envelopeError(409, 'invalid_state_transition', 'Bad transition.');
    expect(isStaleWriteError(other)).toBe(false);
  });

  it('falls back gracefully for non-axios errors', () => {
    expect(getErrorMessage(new Error('boom'))).toBe('boom');
    expect(isStaleWriteError(new Error('boom'))).toBe(false);
    expect(getErrorCode(new Error('boom'))).toBeUndefined();
  });
});
