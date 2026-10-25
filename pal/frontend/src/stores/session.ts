import { defineStore } from 'pinia'
import { api, ApiError, setCsrfToken, setUnauthorizedHandler } from '@/api/client'

export interface Me {
  user: { id: string; displayName: string; email: string | null; timezone: string | null }
  roles: string[]
  permissions: string[]
  csrfToken: string
  defaultTimezone: string
  session: { idleTimeoutSeconds: number; absoluteExpiresAt: string | null }
}

type Status = 'unknown' | 'authenticated' | 'anonymous'

interface SessionState {
  status: Status
  me: Me | null
}

export const useSessionStore = defineStore('session', {
  state: (): SessionState => ({
    status: 'unknown',
    me: null,
  }),
  getters: {
    displayName: (state): string => state.me?.user.displayName ?? '',
    effectiveTimezone: (state): string => state.me?.user.timezone ?? state.me?.defaultTimezone ?? 'UTC',
  },
  actions: {
    can(permission: string): boolean {
      return this.me?.permissions.includes(permission) ?? false
    },

    async load(): Promise<void> {
      setUnauthorizedHandler(() => this.markSignedOut())
      try {
        const me = await api<Me>('/api/me')
        this.me = me
        this.status = 'authenticated'
        setCsrfToken(me.csrfToken)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          this.markSignedOut()
          return
        }
        throw error
      }
    },

    markSignedOut(): void {
      this.me = null
      this.status = 'anonymous'
      setCsrfToken(null)
    },

    signIn(returnTo: string): void {
      window.location.assign('/auth/login?returnTo=' + encodeURIComponent(returnTo))
    },

    async signOut(): Promise<void> {
      let endSessionUrl: string | null = null
      try {
        const result = await api<{ ok: boolean; endSessionUrl: string | null }>('/api/session/logout', { method: 'POST' })
        endSessionUrl = result.endSessionUrl
      } finally {
        this.markSignedOut()
      }
      window.location.assign(endSessionUrl !== null && endSessionUrl.startsWith('https://') ? endSessionUrl : '/sign-in')
    },
  },
})
