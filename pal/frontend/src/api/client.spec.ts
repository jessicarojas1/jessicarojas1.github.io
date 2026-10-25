import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, setCsrfToken, setUnauthorizedHandler } from './client'

function mockFetch(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fn = vi.fn().mockImplementation(() => Promise.resolve(new Response(body === undefined ? '' : JSON.stringify(body), { status })))
  vi.stubGlobal('fetch', fn)
  return fn
}

afterEach(() => {
  vi.unstubAllGlobals()
  setCsrfToken(null)
  setUnauthorizedHandler(null)
})

describe('api client', () => {
  it('sends the CSRF token only on state-changing requests', async () => {
    const fetchMock = mockFetch(200, { ok: true })
    setCsrfToken('token-123')

    await api('/api/me')
    await api('/api/me/preferences', { method: 'PATCH', body: { timezone: 'UTC' } })

    const getHeaders = (fetchMock.mock.calls[0]?.[1] as RequestInit).headers as Record<string, string>
    const patchInit = fetchMock.mock.calls[1]?.[1] as RequestInit
    const patchHeaders = patchInit.headers as Record<string, string>
    expect(getHeaders['X-CSRF-Token']).toBeUndefined()
    expect(patchHeaders['X-CSRF-Token']).toBe('token-123')
    expect(patchHeaders['Content-Type']).toBe('application/json')
    expect(patchInit.credentials).toBe('same-origin')
  })

  it('turns error responses into ApiError with field messages', async () => {
    mockFetch(422, { error: 'validation_failed', message: 'Invalid.', fields: { timezone: 'Bad zone' } })

    await expect(api('/api/me/preferences', { method: 'PATCH', body: {} })).rejects.toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { timezone: 'Bad zone' },
    })
  })

  it('notifies the session on 401', async () => {
    mockFetch(401, { error: 'unauthenticated' })
    const handler = vi.fn()
    setUnauthorizedHandler(handler)

    await expect(api('/api/me')).rejects.toBeInstanceOf(ApiError)
    expect(handler).toHaveBeenCalledOnce()
  })

  it('reports network failures without leaking details', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(api('/api/me')).rejects.toMatchObject({ status: 0, code: 'network_error' })
  })

  it('refuses paths outside the API', async () => {
    await expect(api('https://evil.example/api/x')).rejects.toThrow('API paths must start with /api/')
  })
})
