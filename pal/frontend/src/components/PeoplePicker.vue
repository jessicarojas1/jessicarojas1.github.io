<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/api/client'
import type { Person } from '@/api/documents'

const model = defineModel<string | null>({ required: true })
defineProps<{ label: string; hint?: string; error?: string | undefined }>()

const options = ref<{ value: string; label: string }[]>([])

function filter(value: string, update: (fn: () => void) => void, abort: () => void): void {
  if (value.trim().length < 2) {
    abort()
    return
  }
  void api<{ users: Person[] }>(`/api/users?q=${encodeURIComponent(value.trim())}`)
    .then((data) => {
      update(() => {
        options.value = data.users.map((u) => ({ value: u.id, label: u.email === null ? u.displayName : `${u.displayName} (${u.email})` }))
      })
    })
    .catch(() => abort())
}
</script>

<template>
  <q-select
    v-model="model"
    :options="options"
    :label="label"
    :hint="hint ?? 'Type at least two letters of a name or email.'"
    :error="error !== undefined"
    :error-message="error"
    emit-value
    map-options
    use-input
    input-debounce="250"
    outlined
    clearable
    @filter="filter"
  />
</template>
