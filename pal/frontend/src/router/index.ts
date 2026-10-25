import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    permission?: string
    title?: string
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/sign-in', name: 'sign-in', component: () => import('@/pages/SignInPage.vue'), meta: { public: true, title: 'Sign in' } },
    {
      path: '/',
      component: () => import('@/layouts/MainLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
        { path: 'settings/preferences', name: 'preferences', component: () => import('@/pages/PreferencesPage.vue'), meta: { title: 'Preferences' } },
        { path: 'settings/branding', name: 'branding', component: () => import('@/pages/BrandingPage.vue'), meta: { permission: 'system.config', title: 'Branding' } },
        { path: 'admin/roles', name: 'role-mappings', component: () => import('@/pages/RoleMappingsPage.vue'), meta: { permission: 'admin.roles', title: 'Role mappings' } },
        { path: 'forbidden', name: 'forbidden', component: () => import('@/pages/ForbiddenPage.vue'), meta: { title: 'Access denied' } },
        { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Not found' } },
      ],
    },
  ],
})

/**
 * Navigation guard. This only shapes the UI: every permission is enforced again by the API.
 */
export async function guard(to: RouteLocationNormalized): Promise<true | { name: string; query?: Record<string, string> }> {
  const session = useSessionStore()
  if (session.status === 'unknown') {
    await session.load()
  }
  if (to.meta.public === true) {
    return session.status === 'authenticated' && to.name === 'sign-in' ? { name: 'dashboard' } : true
  }
  if (session.status !== 'authenticated') {
    return { name: 'sign-in', query: { returnTo: to.fullPath } }
  }
  if (to.meta.permission !== undefined && !session.can(to.meta.permission)) {
    return { name: 'forbidden' }
  }
  return true
}

router.beforeEach(guard)
