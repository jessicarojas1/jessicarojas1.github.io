<script setup lang="ts">
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import BrandMark from '@/components/BrandMark.vue'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const current = ref('')
const next = ref('')
const confirm = ref('')
const fieldErrors = ref<Record<string, string>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

const forced = computed(() => session.me?.user.mustChangePassword === true)
const tooShort = computed(() => next.value !== '' && next.value.length < 12)
const mismatch = computed(() => confirm.value !== '' && confirm.value !== next.value)

async function save(): Promise<void> {
  if (saving.value) return
  fieldErrors.value = {}
  formError.value = null
  if (next.value.length < 12 || next.value !== confirm.value) {
    formError.value = 'Check the highlighted fields.'
    return
  }
  saving.value = true
  try {
    await api('/api/me/password', { method: 'POST', body: { currentPassword: current.value, newPassword: next.value } })
    window.location.assign('/')
  } catch (e) {
    if (e instanceof ApiError) {
      fieldErrors.value = e.fields
      formError.value = Object.keys(e.fields).length > 0 ? 'Check the highlighted fields.' : e.message
    } else {
      formError.value = 'Your password could not be changed.'
    }
  } finally {
    saving.value = false
  }
}

async function signOut(): Promise<void> {
  try {
    await session.signOut()
  } catch {
    formError.value = 'Sign-out could not be confirmed. Close the browser to end your session.'
  }
}
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="flex flex-center q-pa-md">
        <section
          class="pal-card pal-signin-card"
          aria-labelledby="change-heading"
        >
          <div class="text-h6 q-mb-md">
            <BrandMark />
          </div>
          <h1
            id="change-heading"
            class="text-h5 q-mt-none q-mb-sm"
          >
            {{ forced ? 'Choose a new password' : 'Change password' }}
          </h1>
          <p class="pal-muted">
            {{ forced ? 'Your password was set by an administrator. Choose your own before continuing.' : 'Use at least 12 characters. A passphrase of several words is easy to remember and hard to guess.' }}
          </p>
          <form
            novalidate
            class="q-gutter-y-md"
            @submit.prevent="save"
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
              v-model="current"
              outlined
              type="password"
              :label="forced ? 'Temporary password' : 'Current password'"
              autocomplete="current-password"
              :error="fieldErrors.currentPassword !== undefined"
              :error-message="fieldErrors.currentPassword"
            />
            <q-input
              v-model="next"
              outlined
              type="password"
              label="New password"
              autocomplete="new-password"
              hint="At least 12 characters; must not contain your username."
              :error="tooShort || fieldErrors.newPassword !== undefined"
              :error-message="tooShort ? 'Use at least 12 characters.' : fieldErrors.newPassword"
            />
            <q-input
              v-model="confirm"
              outlined
              type="password"
              label="Confirm new password"
              autocomplete="new-password"
              :error="mismatch"
              error-message="The passwords do not match."
            />
            <div class="row justify-between items-center">
              <q-btn
                v-if="!forced"
                flat
                no-caps
                label="Cancel"
                to="/"
              />
              <q-btn
                v-else
                flat
                no-caps
                label="Sign out"
                @click="signOut"
              />
              <q-btn
                type="submit"
                color="primary"
                unelevated
                no-caps
                label="Save password"
                :loading="saving"
              />
            </div>
          </form>
        </section>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
