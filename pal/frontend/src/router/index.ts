import { nextTick } from 'vue'
import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    permission?: string
    title?: string
    /** Parent shown before the page title in the breadcrumb trail. */
    parent?: { label: string; to?: string }
  }
}

const LIBRARY = { label: 'Library', to: '/library' }
const ADMIN = { label: 'Administration' }
const SETTINGS = { label: 'Settings' }

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/sign-in', name: 'sign-in', component: () => import('@/pages/SignInPage.vue'), meta: { public: true, title: 'Sign in' } },
    { path: '/change-password', name: 'change-password', component: () => import('@/pages/ChangePasswordPage.vue'), meta: { title: 'Change password' } },
    { path: '/unavailable', name: 'unavailable', component: () => import('@/pages/UnavailablePage.vue'), meta: { public: true, title: 'PAL is unavailable' } },
    {
      path: '/',
      component: () => import('@/layouts/MainLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
        { path: 'library', name: 'library', component: () => import('@/pages/LibraryPage.vue'), meta: { title: 'Library' } },
        { path: 'workspace', name: 'workspace', component: () => import('@/pages/WorkspacePage.vue'), meta: { title: 'My workspace' } },
        { path: 'documents/new', name: 'new-document', component: () => import('@/pages/NewDocumentPage.vue'), meta: { permission: 'document.create', title: 'New document', parent: LIBRARY } },
        { path: 'documents/:id', name: 'document', component: () => import('@/pages/DocumentPage.vue'), meta: { title: 'Document', parent: LIBRARY } },
        { path: 'admin/users', name: 'users-admin', component: () => import('@/pages/UsersAdminPage.vue'), meta: { permission: 'admin.users', title: 'Users', parent: ADMIN } },
        { path: 'settings/sign-in', name: 'sign-in-methods', component: () => import('@/pages/SignInMethodsPage.vue'), meta: { permission: 'system.config', title: 'Sign-in methods', parent: SETTINGS } },
        { path: 'admin/templates', name: 'templates-admin', component: () => import('@/pages/TemplatesAdminPage.vue'), meta: { permission: 'admin.templates', title: 'Document templates', parent: ADMIN } },
        { path: 'admin/reference', name: 'reference-admin', component: () => import('@/pages/ReferenceAdminPage.vue'), meta: { permission: 'admin.types', title: 'Types and departments', parent: ADMIN } },
        { path: 'settings/preferences', name: 'preferences', component: () => import('@/pages/PreferencesPage.vue'), meta: { title: 'Preferences', parent: SETTINGS } },
        { path: 'settings/branding', name: 'branding', component: () => import('@/pages/BrandingPage.vue'), meta: { permission: 'system.config', title: 'Branding', parent: SETTINGS } },
        { path: 'admin/roles', name: 'role-mappings', component: () => import('@/pages/RoleMappingsPage.vue'), meta: { permission: 'admin.roles', title: 'Role mappings', parent: ADMIN } },
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
  if (session.status === 'unknown' || (session.status === 'unreachable' && to.name !== 'unavailable')) {
    await session.load()
  }
  if (session.status === 'unreachable') {
    return to.name === 'unavailable' ? true : { name: 'unavailable', query: { returnTo: to.fullPath } }
  }
  if (to.meta.public === true) {
    return session.status === 'authenticated' && to.name === 'sign-in' ? { name: 'dashboard' } : true
  }
  if (session.status !== 'authenticated') {
    return { name: 'sign-in', query: { returnTo: to.fullPath } }
  }
  // A temporary password must be replaced before anything else.
  if (session.me?.user.mustChangePassword === true && to.name !== 'change-password') {
    return { name: 'change-password' }
  }
  if (to.meta.permission !== undefined && !session.can(to.meta.permission)) {
    return { name: 'forbidden' }
  }
  return true
}

router.beforeEach(guard)

// After in-app navigation, move focus to the new page's main heading so keyboard and
// screen-reader users land on the new content (the initial load keeps natural focus).
// Pages that load data first render their heading later, so wait (briefly) for it.
function focusHeadingWhenReady(timeoutMs = 3000): void {
  const find = (): HTMLElement | null => document.querySelector<HTMLElement>('#main-content h1, main h1')
  const focus = (heading: HTMLElement): void => {
    heading.setAttribute('tabindex', '-1')
    heading.focus()
  }
  const existing = find()
  if (existing !== null) {
    focus(existing)
    return
  }
  const observer = new MutationObserver(() => {
    const heading = find()
    if (heading !== null) {
      observer.disconnect()
      focus(heading)
    }
  })
  observer.observe(document.body, { childList: true, subtree: true })
  window.setTimeout(() => observer.disconnect(), timeoutMs)
}

router.afterEach((to, from) => {
  if (from.matched.length === 0) return
  void nextTick(() => focusHeadingWhenReady())
})
