<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { useSessionStore } from '@/stores/session'

const $q = useQuasar()
const session = useSessionStore()

const allZones: string[] = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []
const options = ref<string[]>(allZones)
const timezone = ref<string | null>(session.me?.user.timezone ?? null)
const saving = ref(false)
const error = ref<string | null>(null)

const dirty = computed(() => timezone.value !== (session.me?.user.timezone ?? null))

function filter(value: string, update: (fn: () => void) => void): void {
  update(() => {
    const needle = value.toLowerCase()
    options.value = needle === '' ? allZones : allZones.filter((zone) => zone.toLowerCase().includes(needle))
  })
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    await api('/api/me/preferences', { method: 'PATCH', body: { timezone: timezone.value } })
    await session.load()
    $q.notify({ type: 'positive', message: 'Preferences saved.' })
  } catch (e) {
    error.value = e instanceof ApiError ? (e.fields.timezone ?? e.message) : 'Preferences could not be saved.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Preferences</h1>
      <p>Dates and times in PAL are shown in your timezone.</p>
    </header>

    <form
      class="pal-card pal-form-narrow"
      novalidate
      @submit.prevent="save"
    >
      <q-select
        v-model="timezone"
        :options="options"
        label="Your timezone"
        use-input
        fill-input
        hide-selected
        input-debounce="0"
        clearable
        outlined
        :hint="`Leave empty to use the organisation default (${session.me?.defaultTimezone ?? 'UTC'}).`"
        :error="error !== null"
        :error-message="error ?? ''"
        @filter="filter"
      />
      <div class="q-mt-lg row justify-end">
        <q-btn
          type="submit"
          color="primary"
          unelevated
          no-caps
          :loading="saving"
          :disable="!dirty"
          :label="dirty ? 'Save changes' : 'Saved'"
        />
      </div>
    </form>
  </div>
</template>
