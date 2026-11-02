<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { safeReturnTo } from '@/returnTo'
import { useSessionStore } from '@/stores/session'

interface Method { id: string; label: string; connected: boolean }

const route = useRoute()
const session = useSessionStore()

const MESSAGES: Record<string, string> = {
  sign_in_cancelled: 'Sign-in was cancelled. You can try again.',
  sign_in_expired: 'Your session ended. Please sign in again.',
  sign_in_failed: 'Sign-in did not complete. Please try again, and contact your administrator if it keeps happening.',
  sign_in_unavailable: 'Single sign-on is temporarily unavailable. Please try again later.',
  account_disabled: 'Your account is disabled. Contact your administrator.',
  too_many_attempts: 'Too many sign-in attempts. Wait a few minutes and try again.',
}

const methods = ref<Method[]>([])
const csrf = ref<string | null>(null)
const username = ref('')
const password = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const formError = ref<string | null>(null)
const loadFailed = ref(false)

const ssoError = computed(() => {
  const code = route.query.auth_error
  return typeof code === 'string' ? (MESSAGES[code] ?? MESSAGES.sign_in_failed) : null
})
const returnTo = computed(() => safeReturnTo(route.query.returnTo))
const sso = computed(() => methods.value.filter((m) => m.id !== 'password'))

async function loadMethods(): Promise<void> {
  try {
    const response = await fetch('/auth/methods', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error('methods unavailable')
    const data = (await response.json()) as { csrfToken: string; methods: Method[] }
    csrf.value = data.csrfToken
    methods.value = data.methods
    loadFailed.value = false
  } catch {
    loadFailed.value = true
  }
}

async function signIn(): Promise<void> {
  if (submitting.value) return
  formError.value = null
  if (username.value.trim() === '' || password.value === '') {
    formError.value = 'Enter your username and password.'
    return
  }
  submitting.value = true
  try {
    const response = await fetch('/auth/password', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf.value ?? '' },
      body: JSON.stringify({ username: username.value, password: password.value }),
    })
    const data = (await response.json().catch(() => ({}))) as { mustChangePassword?: boolean; message?: string }
    if (response.ok) {
      // Full navigation so the app starts fresh with the new session.
      window.location.assign(data.mustChangePassword === true ? '/change-password' : returnTo.value)
      return
    }
    formError.value = data.message ?? 'Sign-in failed.'
    password.value = ''
    await loadMethods()
  } catch {
    formError.value = 'PAL could not be reached. Check your connection and try again.'
  } finally {
    submitting.value = false
  }
}

onMounted(loadMethods)
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="flex flex-center q-pa-md">
        <section
          class="pal-card pal-signin-card"
          aria-labelledby="sign-in-heading"
        >
          <div class="text-h6 q-mb-md">
            <BrandMark />
          </div>
          <h1
            id="sign-in-heading"
            class="text-h5 q-mt-none q-mb-sm"
          >
            Sign in
          </h1>
          <q-banner
            v-if="ssoError"
            class="bg-negative text-white q-mb-md"
            rounded
            role="alert"
          >
            {{ ssoError }}
          </q-banner>

          <form
            novalidate
            class="q-gutter-y-md"
            @submit.prevent="signIn"
          >
            <q-banner
              v-if="formError"
              class="bg-negative text-white"
              rounded
              role="alert"
            >
              {{ formError }}
            </q-banner>
            <q-input
              v-model="username"
              outlined
              label="Username"
              autocomplete="username"
              autocapitalize="none"
              spellcheck="false"
              :disable="loadFailed"
            />
            <q-input
              v-model="password"
              outlined
              label="Password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              :disable="loadFailed"
            >
              <template #append>
                <q-btn
                  flat
                  round
                  dense
                  :icon="showPassword ? 'visibility_off' : 'visibility'"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  :aria-pressed="showPassword ? 'true' : 'false'"
                  @click="showPassword = !showPassword"
                />
              </template>
            </q-input>
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              class="full-width"
              size="md"
              label="Sign in"
              :loading="submitting"
              :disable="loadFailed"
            />
          </form>

          <p
            v-if="loadFailed"
            class="pal-text-danger q-mt-md"
            role="alert"
          >
            PAL could not be reached. Reload the page to try again.
          </p>

          <div
            v-if="sso.length > 0"
            class="pal-divider q-my-lg"
            role="separator"
            aria-label="or use single sign-on"
          >
            <span>or use single sign-on</span>
          </div>

          <ul
            v-if="sso.length > 0"
            class="pal-plain-list q-gutter-y-sm"
          >
            <li
              v-for="method in sso"
              :key="method.id"
            >
              <q-btn
                v-if="method.id === 'entra' && method.connected"
                outline
                no-caps
                class="full-width"
                icon="login"
                :label="`Sign in with ${method.label}`"
                :href="`/auth/login?returnTo=${encodeURIComponent(returnTo)}`"
              />
              <div
                v-else
                class="pal-sso-unavailable"
              >
                <span>{{ method.label }}</span>
                <span class="pal-muted">Not connected</span>
              </div>
            </li>
          </ul>
          <p
            v-if="session.status === 'unreachable'"
            class="pal-muted q-mt-md q-mb-none"
          >
            Some services are unavailable.
          </p>
        </section>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
