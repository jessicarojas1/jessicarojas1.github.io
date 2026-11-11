<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { loadReference, type ReferenceItem, type TemplateDetail, type TemplateSummary } from '@/api/documents'
import RichTextEditor from '@/components/RichTextEditor.vue'
import { useLeaveGuard } from '@/composables/useLeaveGuard'
import { formatDateTime } from '@/format'
import { isEmptyDocument, type RichTextNode } from '@/richText'
import { useSessionStore } from '@/stores/session'

const $q = useQuasar()
const session = useSessionStore()
const templates = ref<TemplateSummary[]>([])
const types = ref<ReferenceItem[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const filterType = ref<string | null>(null)
const search = ref('')

// The template being edited: null when nothing is open, 'new' for a new one.
const editingId = ref<string | null>(null)
const editorKey = ref(0)
const formTypeId = ref<string | null>(null)
const formName = ref('')
const formDescription = ref('')
const formBody = ref<RichTextNode | null>(null)
const formInitial = ref<RichTextNode | null>(null)
const formRowVersion = ref(0)
const formActive = ref(true)
const baseline = ref('')
const fieldErrors = ref<Record<string, string>>({})
const formError = ref<string | null>(null)
const busy = ref(false)

const text = (t: string): RichTextNode => ({ type: 'text', text: t })
const heading = (t: string): RichTextNode => ({ type: 'heading', attrs: { level: 2 }, content: [text(t)] })
const para = (t: string): RichTextNode => ({ type: 'paragraph', content: [text(t)] })

/** A conventional procedure outline, so a new template starts with structure to adapt. */
const STARTER: RichTextNode = {
  type: 'doc',
  content: [
    heading('1. Purpose'), para('State why this document exists and what it achieves.'),
    heading('2. Scope'), para('Say what and who this applies to, and any exclusions.'),
    heading('3. Definitions'), para('Define terms and abbreviations used in this document.'),
    heading('4. Responsibilities'),
    {
      type: 'table',
      content: [
        { type: 'tableRow', content: [{ type: 'tableHeader', content: [para('Role')] }, { type: 'tableHeader', content: [para('Responsibility')] }] },
        { type: 'tableRow', content: [{ type: 'tableCell', content: [para('Owner')] }, { type: 'tableCell', content: [para('Keeps this document current.')] }] },
      ],
    },
    heading('5. Procedure'),
    { type: 'orderedList', content: [{ type: 'listItem', content: [para('First step.')] }, { type: 'listItem', content: [para('Next step.')] }] },
    heading('6. Records'), para('List the records this produces and where they are kept.'),
    heading('7. References'), para('Related policies, standards and forms.'),
  ],
}

const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  return templates.value.filter((t) => (filterType.value === null || t.documentType.id === filterType.value)
    && (q === '' || t.name.toLowerCase().includes(q) || t.documentType.name.toLowerCase().includes(q)))
})

function snapshot(): string {
  return JSON.stringify([formTypeId.value, formName.value, formDescription.value, formBody.value === null || isEmptyDocument(formBody.value) ? null : formBody.value])
}
const dirty = computed(() => editingId.value !== null && snapshot() !== baseline.value)
useLeaveGuard(dirty)

async function load(): Promise<void> {
  loadError.value = null
  try {
    const [list, reference] = await Promise.all([api<{ templates: TemplateSummary[] }>('/api/admin/templates'), loadReference()])
    templates.value = list.templates
    types.value = reference.documentTypes
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Templates could not be loaded.'
  } finally {
    loading.value = false
  }
}

function confirmDiscard(then: () => void): void {
  if (!dirty.value) {
    then()
    return
  }
  $q.dialog({
    title: 'Discard your changes?',
    message: 'The template you are editing has unsaved changes.',
    cancel: { flat: true, noCaps: true, label: 'Keep editing' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Discard' },
    focus: 'cancel',
    persistent: true,
  }).onOk(then)
}

function fill(id: string, typeId: string | null, name: string, description: string, body: RichTextNode | null, rowVersion: number, active: boolean): void {
  editingId.value = id
  formTypeId.value = typeId
  formName.value = name
  formDescription.value = description
  formInitial.value = body
  formBody.value = body
  formRowVersion.value = rowVersion
  formActive.value = active
  fieldErrors.value = {}
  formError.value = null
  editorKey.value++
  baseline.value = snapshot()
}

function startNew(): void {
  if (busy.value) return
  confirmDiscard(() => {
    fill('new', filterType.value, '', '', STARTER, 0, true)
    // The starter outline counts as a change, so leaving asks first.
    baseline.value = JSON.stringify([formTypeId.value, '', '', null])
  })
}

function open(t: TemplateSummary): void {
  if (busy.value) return
  confirmDiscard(() => void openNow(t))
}

async function openNow(t: TemplateSummary): Promise<void> {
  busy.value = true
  formError.value = null
  try {
    const detail = await api<TemplateDetail>(`/api/admin/templates/${encodeURIComponent(t.id)}`)
    fill(detail.id, detail.documentType.id, detail.name, detail.description ?? '', detail.body, detail.rowVersion, detail.isActive)
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'The template could not be opened.'
  } finally {
    busy.value = false
  }
}

function close(): void {
  confirmDiscard(() => {
    editingId.value = null
  })
}

async function save(): Promise<void> {
  if (busy.value || editingId.value === null) return
  busy.value = true
  fieldErrors.value = {}
  formError.value = null
  const body = formBody.value === null || isEmptyDocument(formBody.value) ? null : formBody.value
  const target = editingId.value
  try {
    if (editingId.value === 'new') {
      const created = await api<{ id: string; rowVersion: number }>('/api/admin/templates', {
        method: 'POST',
        body: { documentTypeId: formTypeId.value, name: formName.value, description: formDescription.value, body },
      })
      if (editingId.value === target) {
        editingId.value = created.id
        formRowVersion.value = created.rowVersion
      }
    } else {
      const updated = await api<{ rowVersion: number }>(`/api/admin/templates/${encodeURIComponent(editingId.value)}`, {
        method: 'PATCH',
        body: { name: formName.value, description: formDescription.value, body, rowVersion: formRowVersion.value },
      })
      if (editingId.value === target) formRowVersion.value = updated.rowVersion
    }
    if (editingId.value === target || target === 'new') baseline.value = snapshot()
    $q.notify({ type: 'positive', message: 'Template saved. Documents already started from it are not changed.' })
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      fieldErrors.value = e.fields
      formError.value = Object.keys(e.fields).length > 0 ? 'Fix the highlighted fields.' : e.message
    } else {
      formError.value = 'The template could not be saved.'
    }
  } finally {
    busy.value = false
  }
}

function toggleActive(): void {
  if (editingId.value === null || editingId.value === 'new') return
  if (dirty.value) {
    formError.value = 'Save or discard your changes first.'
    return
  }
  const activate = !formActive.value
  $q.dialog({
    title: activate ? 'Reactivate this template?' : 'Deactivate this template?',
    message: activate
      ? 'Authors can choose it again for new documents.'
      : 'Authors can no longer choose it for new documents. Documents already started from it are not affected. You can reactivate it later.',
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: activate ? 'primary' : 'negative', unelevated: true, noCaps: true, label: activate ? 'Reactivate' : 'Deactivate' },
    focus: 'cancel',
    persistent: true,
  }).onOk(() => void applyActive(activate))
}

async function applyActive(active: boolean): Promise<void> {
  if (busy.value || editingId.value === null) return
  busy.value = true
  formError.value = null
  try {
    const updated = await api<{ rowVersion: number }>(`/api/admin/templates/${encodeURIComponent(editingId.value)}`, {
      method: 'PATCH',
      body: { isActive: active, rowVersion: formRowVersion.value },
    })
    formRowVersion.value = updated.rowVersion
    formActive.value = active
    $q.notify({ type: 'positive', message: active ? 'Template reactivated.' : 'Template deactivated.' })
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'That could not be saved.'
  } finally {
    busy.value = false
  }
}

const canEdit = computed(() => session.can('admin.templates'))
const typeOptions = computed(() => types.value.map((t) => ({ value: t.id, label: `${t.name} (${t.code})` })))
const editingTypeName = computed(() => {
  const t = types.value.find((x) => x.id === formTypeId.value)
  return t === undefined ? templates.value.find((x) => x.id === editingId.value)?.documentType.name ?? '' : `${t.name} (${t.code})`
})

onMounted(load)
</script>

<template>
  <div class="pal-page pal-page-wide">
    <header class="pal-page-header">
      <h1>Document templates</h1>
      <p>Controlled starting content for each document type: headings, guidance text, lists and tables. Authors pick a template when they create a document and edit their own copy; changing a template never changes documents already started from it.</p>
    </header>

    <q-banner
      v-if="loadError"
      class="bg-negative text-white q-mb-md"
      rounded
      role="alert"
    >
      {{ loadError }}
      <template #action>
        <q-btn
          flat
          no-caps
          label="Try again"
          @click="load"
        />
      </template>
    </q-banner>
    <div
      v-if="loading"
      class="q-pa-md"
      role="status"
    >
      <q-spinner size="24px" /> Loading…
    </div>

    <div
      v-else-if="!loadError"
      class="row q-col-gutter-lg"
    >
      <section
        class="col-12 col-md-4"
        aria-labelledby="template-list-heading"
      >
        <div class="pal-card">
          <div class="row items-center justify-between q-mb-sm">
            <h2
              id="template-list-heading"
              class="text-h6 q-my-none"
            >
              Templates
            </h2>
            <q-btn
              v-if="canEdit"
              color="primary"
              unelevated
              no-caps
              icon="add"
              label="New template"
              :disable="types.length === 0 || busy"
              @click="startNew"
            />
          </div>
          <q-select
            v-model="filterType"
            outlined
            dense
            clearable
            emit-value
            map-options
            :options="typeOptions"
            label="Document type"
            class="q-mb-sm"
          />
          <q-input
            v-model="search"
            outlined
            dense
            clearable
            label="Search templates"
            class="q-mb-md"
          />
          <ul
            v-if="visible.length > 0"
            class="pal-plain-list"
          >
            <li
              v-for="t in visible"
              :key="t.id"
            >
              <button
                type="button"
                class="pal-list-item pal-template-item full-width"
                :disabled="busy"
                :class="{ 'pal-template-item--active': editingId === t.id }"
                :aria-current="editingId === t.id ? 'true' : undefined"
                @click="open(t)"
              >
                <span class="text-weight-medium">{{ t.name }}</span>
                <span class="pal-muted"> · {{ t.documentType.name }}<span v-if="!t.isActive"> · inactive</span></span>
                <span class="block pal-muted text-caption">Updated {{ formatDateTime(t.updatedAt, session.effectiveTimezone) }} by {{ t.updatedBy }}</span>
              </button>
            </li>
          </ul>
          <p
            v-else
            class="empty-state-sm"
          >
            {{ templates.length === 0 ? 'No templates yet. Create one to give authors a standard structure.' : 'No templates match.' }}
          </p>
        </div>
      </section>

      <section
        class="col-12 col-md-8"
        aria-labelledby="template-editor-heading"
      >
        <div class="pal-card">
          <p
            v-if="editingId === null"
            id="template-editor-heading"
            class="pal-muted q-mb-none"
          >
            Choose a template to edit, or create a new one.
          </p>
          <form
            v-else
            class="q-gutter-y-md"
            novalidate
            @submit.prevent="save"
          >
            <div class="row items-center justify-between">
              <h2
                id="template-editor-heading"
                class="text-h6 q-my-none"
              >
                {{ editingId === 'new' ? 'New template' : formName || 'Template' }}
                <q-chip
                  v-if="editingId !== 'new' && !formActive"
                  dense
                >
                  Inactive
                </q-chip>
              </h2>
              <q-btn
                flat
                round
                dense
                icon="close"
                aria-label="Close the template"
                @click="close"
              />
            </div>
            <q-banner
              v-if="formError"
              class="bg-negative text-white"
              rounded
              role="alert"
            >
              {{ formError }}
            </q-banner>
            <div class="row q-col-gutter-md">
              <q-select
                v-if="editingId === 'new'"
                v-model="formTypeId"
                class="col-12 col-sm-6"
                outlined
                emit-value
                map-options
                :options="typeOptions"
                label="Document type"
                hint="Fixed once the template is created."
                :error="fieldErrors.documentTypeId !== undefined"
                :error-message="fieldErrors.documentTypeId"
              />
              <q-input
                v-else
                class="col-12 col-sm-6"
                outlined
                readonly
                :model-value="editingTypeName"
                label="Document type"
              />
              <q-input
                v-model="formName"
                class="col-12 col-sm-6"
                outlined
                label="Name"
                maxlength="120"
                :readonly="!canEdit"
                :error="fieldErrors.name !== undefined"
                :error-message="fieldErrors.name"
              />
            </div>
            <q-input
              v-model="formDescription"
              outlined
              type="textarea"
              autogrow
              label="Description for authors (optional)"
              hint="Shown when an author chooses a template."
              maxlength="1000"
              :readonly="!canEdit"
              :error="fieldErrors.description !== undefined"
              :error-message="fieldErrors.description"
            />
            <div>
              <p class="text-weight-medium q-mb-xs">
                Content
              </p>
              <RichTextEditor
                :key="editorKey"
                :initial="formInitial"
                :disabled="!canEdit"
                label="Template content"
                @change="formBody = $event"
              />
              <p
                v-if="fieldErrors.body"
                class="pal-text-danger q-mt-xs"
                role="alert"
              >
                {{ fieldErrors.body }}
              </p>
            </div>
            <div
              v-if="canEdit"
              class="row justify-between q-gutter-sm"
            >
              <q-btn
                v-if="editingId !== 'new'"
                flat
                no-caps
                :class="formActive ? 'pal-text-danger' : ''"
                :label="formActive ? 'Deactivate' : 'Reactivate'"
                :disable="busy"
                @click="toggleActive"
              />
              <span v-else />
              <div class="row q-gutter-sm">
                <q-btn
                  flat
                  no-caps
                  label="Cancel"
                  @click="close"
                />
                <q-btn
                  type="submit"
                  color="primary"
                  unelevated
                  no-caps
                  :label="dirty ? 'Save template (unsaved changes)' : 'Save template'"
                  :loading="busy"
                />
              </div>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>
