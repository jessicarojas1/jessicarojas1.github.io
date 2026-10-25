<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import BrandMark from '@/components/BrandMark.vue'
import { useSessionStore } from '@/stores/session'
import { storeThemePreference } from '@/theme'

const $q = useQuasar()
const session = useSessionStore()
const drawerOpen = ref($q.screen.gt.sm)

interface NavItem {
  label: string
  icon: string
  to: string
  permission?: string
}

const sections = computed(() => {
  const all: { heading: string; items: NavItem[] }[] = [
    { heading: 'Home', items: [{ label: 'Dashboard', icon: 'space_dashboard', to: '/' }] },
    {
      heading: 'Administration',
      items: [{ label: 'Role mappings', icon: 'admin_panel_settings', to: '/admin/roles', permission: 'admin.roles' }],
    },
    {
      heading: 'Settings',
      items: [
        { label: 'Branding', icon: 'palette', to: '/settings/branding', permission: 'system.config' },
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
            aria-label="Go to dashboard"
          >
            <BrandMark />
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
    >
      <nav aria-label="Main">
        <q-list padding>
          <template
            v-for="section in sections"
            :key="section.heading"
          >
            <q-item-label header>
              {{ section.heading }}
            </q-item-label>
            <q-item
              v-for="item in section.items"
              :key="item.to"
              :to="item.to"
              exact
              clickable
              active-class="pal-text-primary text-weight-medium"
            >
              <q-item-section avatar>
                <q-icon
                  :name="item.icon"
                  aria-hidden="true"
                />
              </q-item-section>
              <q-item-section>{{ item.label }}</q-item-section>
            </q-item>
          </template>
        </q-list>
      </nav>
    </q-drawer>

    <q-page-container>
      <main
        id="main-content"
        tabindex="-1"
      >
        <router-view />
      </main>
    </q-page-container>
  </q-layout>
</template>
