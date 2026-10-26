<script setup lang="ts">
import { onMounted, ref } from 'vue'

interface Method { id: string; label: string; connected: boolean }

const methods = ref<Method[]>([])
const error = ref<string | null>(null)

const DETAILS: Record<string, string> = {
  password: 'Local accounts created under Administration › Users. Passwords are hashed (argon2id), throttled, and locked after repeated failures.',
  entra: 'Single sign-on with your Microsoft Entra ID tenant. Configured by the server administrator through OIDC_TENANT_ID, OIDC_CLIENT_ID and a client certificate or secret, with the redirect URI <PAL address>/auth/callback.',
  okta: 'Planned. Not available in this version.',
  google: 'Planned. Not available in this version.',
  saml: 'Planned. Not available in this version.',
}

onMounted(async () => {
  try {
    const response = await fetch('/auth/methods', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    methods.value = ((await response.json()) as { methods: Method[] }).methods
  } catch {
    error.value = 'Sign-in methods could not be loaded.'
  }
})
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Sign-in methods</h1>
      <p>How people can sign in to PAL, and whether each method is connected. Tenant and client settings are held in server configuration, not in the browser.</p>
    </header>
    <q-banner
      v-if="error"
      class="bg-negative text-white"
      rounded
      role="alert"
    >
      {{ error }}
    </q-banner>
    <ul class="pal-plain-list q-gutter-y-md">
      <li
        v-for="method in methods"
        :key="method.id"
        class="pal-card"
      >
        <div class="row items-center justify-between q-gutter-sm">
          <h2 class="text-subtitle1 text-weight-medium q-my-none">
            {{ method.label }}
          </h2>
          <span class="pal-state-chip">
            <q-icon
              :name="method.connected ? 'check_circle' : 'link_off'"
              size="16px"
              aria-hidden="true"
            />
            {{ method.connected ? 'Connected' : 'Not connected' }}
          </span>
        </div>
        <p class="pal-muted q-mb-none q-mt-sm">
          {{ DETAILS[method.id] ?? '' }}
        </p>
      </li>
    </ul>
  </div>
</template>
