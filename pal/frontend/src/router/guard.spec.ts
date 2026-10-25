import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'
import { guard } from './index'
import { useSessionStore } from '@/stores/session'

function route(name: string, meta: RouteLocationNormalized['meta'] = {}, fullPath = '/'): RouteLocationNormalized {
  return { name, meta, fullPath, path: fullPath, params: {}, query: {}, hash: '', matched: [], redirectedFrom: undefined }
}

describe('navigation guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('sends anonymous users to sign-in with a return path', async () => {
    const session = useSessionStore()
    vi.spyOn(session, 'load').mockImplementation(() => {
      session.markSignedOut()
      return Promise.resolve()
    })

    expect(await guard(route('dashboard', {}, '/settings/preferences'))).toEqual({ name: 'sign-in', query: { returnTo: '/settings/preferences' } })
  })

  it('blocks pages the user lacks permission for', async () => {
    const session = useSessionStore()
    session.status = 'authenticated'
    session.me = { user: { id: '1', displayName: 'A', email: null, timezone: null }, roles: ['READER'], permissions: ['library.read'], csrfToken: 't', defaultTimezone: 'America/Denver', session: { idleTimeoutSeconds: 3600, absoluteExpiresAt: null } }

    expect(await guard(route('branding', { permission: 'system.config' }))).toEqual({ name: 'forbidden' })
    expect(await guard(route('dashboard'))).toBe(true)
  })

  it('lets public pages through and skips sign-in when already authenticated', async () => {
    const session = useSessionStore()
    session.status = 'anonymous'
    expect(await guard(route('sign-in', { public: true }))).toBe(true)

    session.status = 'authenticated'
    expect(await guard(route('sign-in', { public: true }))).toEqual({ name: 'dashboard' })
  })
})
