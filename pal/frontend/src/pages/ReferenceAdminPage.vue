<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import type { ReferenceItem } from '@/api/documents'
import { useSessionStore } from '@/stores/session'

const $q = useQuasar()
const session = useSessionStore()
const types = ref<ReferenceItem[]>([])
const departments = ref<ReferenceItem[]>([])
const loadError = ref<string | null>(null)
const errors = ref<Record<string, string | null>>({})
const fields = ref<Record<string, Record<string, string>>>({})
const busy = ref(false)
const newDept = ref({ code: '', name: '' })
const newType = ref({ code: '', name: '' })

async function load(): Promise<void> {
  loadError.value = null
  try {
    const data = await api<{ documentTypes: ReferenceItem[]; departments: ReferenceItem[] }>('/api/admin/reference')
    types.value = data.documentTypes
    departments.value = data.departments
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Reference data could not be loaded.'
  }
}

async function create(kind: 'departments' | 'document-types'): Promise<void> {
  if (busy.value) return
  const model = kind === 'departments' ? newDept : newType
  busy.value = true
  errors.value = { ...errors.value, [kind]: null }
  fields.value = { ...fields.value, [kind]: {} }
  try {
    await api(`/api/admin/${kind}`, { method: 'POST', body: model.value })
    model.value = { code: '', name: '' }
    $q.notify({ type: 'positive', message: 'Added.' })
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      fields.value = { ...fields.value, [kind]: e.fields }
      errors.value = { ...errors.value, [kind]: Object.keys(e.fields).length > 0 ? null : e.message }
    } else {
      errors.value = { ...errors.value, [kind]: 'That could not be saved.' }
    }
  } finally {
    busy.value = false
  }
}

async function toggle(kind: 'departments' | 'document-types', item: ReferenceItem): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    await api(`/api/admin/${kind}/${item.id}`, { method: 'PATCH', body: { isActive: !item.isActive } })
    await load()
  } catch (e) {
    errors.value = { ...errors.value, [kind]: e instanceof ApiError ? e.message : 'That could not be saved.' }
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Document types and departments</h1>
      <p>Codes appear inside document numbers (for example SOP-QA-0001), so they cannot change once created. Deactivate instead of deleting.</p>
    </header>
    <q-banner
      v-if="loadError"
      class="bg-negative text-white q-mb-md"
      rounded
      role="alert"
    >
      {{ loadError }}
    </q-banner>

    <div class="row q-col-gutter-lg">
      <section
        v-for="block in [
          { kind: 'departments' as const, title: 'Departments', items: departments, model: newDept, codeHint: '2–10 capital letters or digits, e.g. QA', can: session.can('admin.departments') },
          { kind: 'document-types' as const, title: 'Document types', items: types, model: newType, codeHint: '2–6 capital letters, e.g. SOP', can: session.can('admin.types') },
        ]"
        :key="block.kind"
        class="col-12 col-md-6"
        :aria-labelledby="`${block.kind}-heading`"
      >
        <div class="pal-card">
          <h2
            :id="`${block.kind}-heading`"
            class="text-h6 q-mt-none"
          >
            {{ block.title }}
          </h2>
          <q-banner
            v-if="errors[block.kind]"
            class="bg-negative text-white q-mb-md"
            rounded
            role="alert"
          >
            {{ errors[block.kind] }}
          </q-banner>
          <ul
            v-if="block.items.length > 0"
            class="pal-plain-list q-mb-md"
          >
            <li
              v-for="item in block.items"
              :key="item.id"
              class="row items-center justify-between q-py-xs"
            >
              <span><span class="pal-doc-number">{{ item.code }}</span> {{ item.name }}<span
                v-if="!item.isActive"
                class="pal-muted"
              > (inactive)</span></span>
              <q-btn
                v-if="block.can"
                flat
                dense
                no-caps
                :label="item.isActive ? 'Deactivate' : 'Reactivate'"
                :aria-label="`${item.isActive ? 'Deactivate' : 'Reactivate'} ${item.name}`"
                @click="toggle(block.kind, item)"
              />
            </li>
          </ul>
          <p
            v-else
            class="empty-state-sm"
          >
            None yet.
          </p>
          <form
            v-if="block.can"
            class="row q-col-gutter-sm items-start"
            novalidate
            @submit.prevent="create(block.kind)"
          >
            <q-input
              v-model="block.model.code"
              class="col-4"
              outlined
              label="Code"
              :hint="block.codeHint"
              :error="fields[block.kind]?.code !== undefined"
              :error-message="fields[block.kind]?.code"
            />
            <q-input
              v-model="block.model.name"
              class="col-5"
              outlined
              label="Name"
              :error="fields[block.kind]?.name !== undefined"
              :error-message="fields[block.kind]?.name"
            />
            <div class="col-3">
              <q-btn
                type="submit"
                color="primary"
                unelevated
                no-caps
                class="full-width pal-field-height"
                label="Add"
                :loading="busy"
              />
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>
