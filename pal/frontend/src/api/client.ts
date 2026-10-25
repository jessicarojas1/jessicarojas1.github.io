// Thin fetch wrapper for PAL's same-origin JSON API (BFF session cookie, no tokens in JS).

export interface ApiErrorBody {
  error: string
  message?: string
  fields?: Record<string, string>
  correlationId?: string
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly fields: Record<string, string> = {},
    readonly correlationId?: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type UnauthorizedHandler = () => void

let csrfToken: string | null = null
let onUnauthorized: UnauthorizedHandler | null = null

export function setCsrfToken(token: string | null): void {
  csrfToken = token
}

export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  onUnauthorized = handler
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

export async function api<T>(path: string, init: { method?: string; body?: unknown; signal?: AbortSignal } = {}): Promise<T> {
  if (!path.startsWith('/api/')) {
    throw new Error('API paths must start with /api/')
  }
  const method = (init.method ?? 'GET').toUpperCase()
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (init.body !== undefined) headers['Content-Type'] = 'application/json'
  if (!SAFE_METHODS.has(method) && csrfToken !== null) headers['X-CSRF-Token'] = csrfToken

  let response: Response
  try {
    response = await fetch(path, {
      method,
      headers,
      credentials: 'same-origin',
      body: init.body === undefined ? null : JSON.stringify(init.body),
      signal: init.signal ?? null,
    })
  } catch {
    throw new ApiError(0, 'network_error', 'PAL could not be reached. Check your connection and try again.')
  }

  const text = await response.text()
  let data: unknown = null
  if (text !== '') {
    try {
      data = JSON.parse(text)
    } catch {
      data = null
    }
  }

  if (!response.ok) {
    const body = (typeof data === 'object' && data !== null ? data : {}) as Partial<ApiErrorBody>
    if (response.status === 401 && onUnauthorized !== null) onUnauthorized()
    throw new ApiError(
      response.status,
      typeof body.error === 'string' ? body.error : 'http_' + String(response.status),
      typeof body.message === 'string' ? body.message : 'The request could not be completed.',
      body.fields ?? {},
      body.correlationId,
    )
  }

  return data as T
}
