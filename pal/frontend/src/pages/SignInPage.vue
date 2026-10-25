<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { useSessionStore } from '@/stores/session'

const route = useRoute()
const session = useSessionStore()

const MESSAGES: Record<string, string> = {
  sign_in_cancelled: 'Sign-in was cancelled. You can try again.',
  sign_in_expired: 'The sign-in attempt expired. Please try again.',
  sign_in_failed: 'Sign-in did not complete. Please try again, and contact your administrator if it keeps happening.',
  sign_in_unavailable: 'Sign-in is temporarily unavailable. Please try again later.',
  account_disabled: 'Your account is disabled. Contact your administrator.',
  too_many_attempts: 'Too many sign-in attempts. Wait a minute and try again.',
}

const errorMessage = computed(() => {
  const code = route.query.auth_error
  return typeof code === 'string' ? (MESSAGES[code] ?? MESSAGES.sign_in_failed) : null
})

const returnTo = computed(() => {
  const value = route.query.returnTo
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : '/'
})
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
          <p class="pal-muted">
            Use your organisation account to open the Process Asset Library.
          </p>
          <q-banner
            v-if="errorMessage"
            class="bg-negative text-white q-mb-md"
            rounded
            role="alert"
          >
            {{ errorMessage }}
          </q-banner>
          <q-btn
            color="primary"
            unelevated
            no-caps
            class="full-width"
            size="lg"
            icon="login"
            label="Sign in with your organisation account"
            @click="session.signIn(returnTo)"
          />
        </section>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
