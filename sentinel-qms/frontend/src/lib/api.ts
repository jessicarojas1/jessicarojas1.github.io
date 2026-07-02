import axios, {
  AxiosError,
  type AxiosInstance,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig,
} from 'axios';
import type { TokenResponse } from '@/types';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

/* ---- In-memory access-token store ---- */
/* The access token is kept in memory ONLY (never localStorage), so an XSS payload
 * cannot exfiltrate a persisted credential and the token is gone on tab close.
 * The refresh token lives in an HttpOnly cookie the JS can neither read nor
 * write; the session is restored after a reload via a silent /auth/refresh. */
let accessToken: string | null = null;

export const tokenStore = {
  get access(): string | null {
    return accessToken;
  },
  set(tokens: Pick<TokenResponse, 'access_token'>) {
    accessToken = tokens.access_token;
  },
  clear() {
    accessToken = null;
  },
};

export const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: { 'Content-Type': 'application/json' },
  timeout: 30_000,
  // Send the HttpOnly refresh cookie on auth calls (same-origin in prod; the
  // Vite dev proxy is same-origin too).
  withCredentials: true,
});

/* ---- Request interceptor: attach bearer token ---- */
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = tokenStore.access;
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`);
  }
  return config;
});

/* ---- Response interceptor: transparent refresh on 401 ---- */
let refreshing: Promise<string | null> | null = null;

export async function refreshAccessToken(): Promise<string | null> {
  try {
    // No body: the refresh token rides in the HttpOnly cookie. withCredentials
    // ensures the cookie is sent (and the rotated one is stored back).
    const { data } = await axios.post<TokenResponse>(
      `${API_BASE_URL}/auth/refresh`,
      undefined,
      { headers: { 'Content-Type': 'application/json' }, withCredentials: true },
    );
    tokenStore.set(data);
    return data.access_token;
  } catch {
    tokenStore.clear();
    return null;
  }
}

type RetriableConfig = AxiosRequestConfig & { _retry?: boolean };

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const original = error.config as RetriableConfig | undefined;
    const status = error.response?.status;

    const isAuthEndpoint =
      typeof original?.url === 'string' && original.url.includes('/auth/');

    if (status === 401 && original && !original._retry && !isAuthEndpoint) {
      original._retry = true;
      if (!refreshing) {
        refreshing = refreshAccessToken().finally(() => {
          refreshing = null;
        });
      }
      const newToken = await refreshing;
      if (newToken) {
        original.headers = original.headers ?? {};
        (original.headers as Record<string, string>).Authorization = `Bearer ${newToken}`;
        return api(original);
      }
      // Refresh failed — broadcast a logout so the app can redirect.
      window.dispatchEvent(new CustomEvent('sentinel:session-expired'));
    }
    return Promise.reject(error);
  },
);

/** The app's error envelope: {"error":{"code","message","request_id"}}. */
interface AppErrorEnvelope {
  code?: string;
  message?: string;
  request_id?: string;
}

function appErrorEnvelope(error: unknown): AppErrorEnvelope | undefined {
  if (axios.isAxiosError(error)) {
    const env = (error.response?.data as { error?: AppErrorEnvelope } | undefined)?.error;
    if (env && typeof env === 'object') return env;
  }
  return undefined;
}

/** Machine-readable error code from the app envelope, if present. */
export function getErrorCode(error: unknown): string | undefined {
  return appErrorEnvelope(error)?.code;
}

/**
 * True when the server rejected a PATCH because the record was modified since
 * it was loaded (optimistic-concurrency lost-update guard).
 */
export function isStaleWriteError(error: unknown): boolean {
  return (
    axios.isAxiosError(error) &&
    error.response?.status === 409 &&
    getErrorCode(error) === 'stale_write'
  );
}

/** Normalize an axios error into a user-facing message. */
export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    // Prefer the app error envelope's human message.
    const envelopeMessage = appErrorEnvelope(error)?.message;
    if (typeof envelopeMessage === 'string' && envelopeMessage) return envelopeMessage;
    const detail = error.response?.data?.detail;
    if (typeof detail === 'string') return detail;
    if (Array.isArray(detail) && detail[0]?.msg) return String(detail[0].msg);
    return error.message;
  }
  if (error instanceof Error) return error.message;
  return 'An unexpected error occurred.';
}

export { API_BASE_URL };
