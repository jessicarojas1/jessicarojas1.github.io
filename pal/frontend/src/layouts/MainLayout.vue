<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { useSessionStore } from '@/stores/session'
import { storeThemePreference } from '@/theme'

const $q = useQuasar()
const session = useSessionStore()
const route = useRoute()
const drawerOpen = ref($q.screen.gt.sm)

// In overlay (mobile) mode the drawer would otherwise stay open over the new page.
useRouter().afterEach(() => {
  if ($q.screen.lt.md) drawerOpen.value = false
})

interface NavItem {
  label: string
  icon: string
  to: string
  permission?: string
}

const sections = computed(() => {
  const all: { heading: string; items: NavItem[] }[] = [
    {
      heading: 'Documents',
      items: [
        { label: 'Dashboard', icon: 'space_dashboard', to: '/' },
        { label: 'Library', icon: 'local_library', to: '/library' },
        { label: 'My workspace', icon: 'work_outline', to: '/workspace' },
        { label: 'New document', icon: 'note_add', to: '/documents/new', permission: 'document.create' },
      ],
    },
    {
      heading: 'Administration',
      items: [
        { label: 'Users', icon: 'manage_accounts', to: '/admin/users', permission: 'admin.users' },
        { label: 'Role mappings', icon: 'admin_panel_settings', to: '/admin/roles', permission: 'admin.roles' },
        { label: 'Types and departments', icon: 'category', to: '/admin/reference', permission: 'admin.types' },
      ],
    },
    {
      heading: 'Settings',
      items: [
        { label: 'Branding', icon: 'palette', to: '/settings/branding', permission: 'system.config' },
        { label: 'Sign-in methods', icon: 'key', to: '/settings/sign-in', permission: 'system.config' },
        { label: 'Preferences', icon: 'tune', to: '/settings/preferences' },
      ],
    },
  ]
  return all
    .map((section) => ({ ...section, items: section.items.filter((item) => item.permission === undefined || session.can(item.permission)) }))
    .filter((section) => section.items.length > 0)
})

function toggleDark(): void {
  $q.dark.set(!$q.dark.isActive)
  storeThemePreference($q.dark.isActive)
}

// A document belongs to the Library section of the navigation.
function isCurrent(to: string): boolean {
  return route.path === to || (to === '/library' && route.path.startsWith('/documents/'))
}

async function signOut(): Promise<void> {
  try {
    await session.signOut()
  } catch {
    $q.notify({ type: 'negative', message: 'Sign-out could not be confirmed. Close the browser to end your session.' })
  }
}
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <a
      href="#main-content"
      class="pal-skip-link"
    >Skip to main content</a>
    <q-header
      bordered
      class="bg-primary pal-on-primary"
    >
      <q-toolbar>
        <q-btn
          flat
          dense
          round
          icon="menu"
          aria-label="Toggle navigation"
          :aria-expanded="drawerOpen ? 'true' : 'false'"
          aria-controls="pal-nav"
          @click="drawerOpen = !drawerOpen"
        />
        <q-toolbar-title class="pal-toolbar-title">
          <router-link
            to="/"
            class="pal-brand-link"
          >
            <BrandMark />
            <span class="pal-visually-hidden"> – dashboard</span>
          </router-link>
        </q-toolbar-title>
        <q-btn
          flat
          round
          dense
          :icon="$q.dark.isActive ? 'light_mode' : 'dark_mode'"
          :aria-label="$q.dark.isActive ? 'Switch to light theme' : 'Switch to dark theme'"
          @click="toggleDark"
        />
        <q-btn-dropdown
          flat
          no-caps
          :label="$q.screen.gt.xs ? session.displayName : undefined"
          icon="account_circle"
          :aria-label="`${session.displayName}, account menu`"
        >
          <q-list>
            <q-item
              clickable
              to="/settings/preferences"
            >
              <q-item-section avatar>
                <q-icon name="tune" />
              </q-item-section>
              <q-item-section>Preferences</q-item-section>
            </q-item>
            <q-item
              v-if="session.me?.user.signInMethod === 'password'"
              clickable
              to="/change-password"
            >
              <q-item-section avatar>
                <q-icon name="password" />
              </q-item-section>
              <q-item-section>Change password</q-item-section>
            </q-item>
            <q-item
              clickable
              @click="signOut"
            >
              <q-item-section avatar>
                <q-icon name="logout" />
              </q-item-section>
              <q-item-section>Sign out</q-item-section>
            </q-item>
          </q-list>
        </q-btn-dropdown>
      </q-toolbar>
    </q-header>

    <q-drawer
      id="pal-nav"
      v-model="drawerOpen"
      show-if-above
      bordered
      :width="248"
      aria-label="Navigation"
    >
      <nav
        aria-label="Main"
        class="q-py-sm"
      >
        <template
          v-for="section in sections"
          :key="section.heading"
        >
          <p
            :id="`nav-${section.heading}`"
            class="pal-nav-heading"
          >
            {{ section.heading }}
          </p>
          <ul
            class="pal-plain-list"
            :aria-labelledby="`nav-${section.heading}`"
          >
            <li
              v-for="item in section.items"
              :key="item.to"
            >
              <router-link
                :to="item.to"
                class="pal-nav-link"
                :class="{ 'pal-nav-link--active': isCurrent(item.to) }"
                :aria-current="isCurrent(item.to) ? 'page' : undefined"
              >
                <q-icon
                  :name="item.icon"
                  size="20px"
                  aria-hidden="true"
                />
                <span>{{ item.label }}</span>
              </router-link>
            </li>
          </ul>
        </template>
      </nav>
    </q-drawer>

    <q-page-container>
      <!-- The only <main> landmark: pages rendered here must not use q-page (it renders <main> too). -->
      <main
        id="main-content"
        tabindex="-1"
      >
        <nav
          v-if="route.name !== 'dashboard'"
          class="pal-breadcrumbs"
          aria-label="Breadcrumb"
        >
          <ol>
            <li>
              <router-link to="/">
                Dashboard
              </router-link>
            </li>
            <li v-if="route.meta.parent">
              <router-link
                v-if="route.meta.parent.to"
                :to="route.meta.parent.to"
              >
                {{ route.meta.parent.label }}
              </router-link>
              <span v-else>{{ route.meta.parent.label }}</span>
            </li>
            <li aria-current="page">
              {{ route.meta.title }}
            </li>
          </ol>
        </nav>
        <router-view />
      </main>
    </q-page-container>
  </q-layout>
</template>
