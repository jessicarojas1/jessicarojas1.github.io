<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { loadReference, type ReferenceItem, type TemplateDetail, type TemplateSummary, type TemplateVersionDetail, type TemplateVersionSummary } from '@/api/documents'
import RichTextEditor from '@/components/RichTextEditor.vue'
import RichTextView from '@/components/RichTextView.vue'
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
const formVersion = ref(0)
// Version history of the open template (decision #27).
const history = ref<TemplateVersionSummary[]>([])
const historyError = ref<string | null>(null)
const viewing = ref<TemplateVersionDetail | null>(null)
const viewingOpen = ref(false)
const historyLoading = ref(false)
const viewingVersion = ref<number | null>(null)
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

async function loadHistory(): Promise<void> {
  const id = editingId.value
  history.value = []
  historyError.value = null
  if (id === null || id === 'new') return
  historyLoading.value = true
  try {
    const data = await api<{ versions: TemplateVersionSummary[] }>(`/api/admin/templates/${encodeURIComponent(id)}/versions`)
    if (editingId.value === id) history.value = data.versions
  } catch (e) {
    if (editingId.value === id) historyError.value = e instanceof ApiError ? e.message : 'The version history could not be loaded.'
  } finally {
    if (editingId.value === id) historyLoading.value = false
  }
}

async function viewVersion(v: TemplateVersionSummary): Promise<void> {
  const id = editingId.value
  if (id === null || id === 'new' || viewingVersion.value !== null) return
  viewingVersion.value = v.version
  try {
    const detail = await api<TemplateVersionDetail>(`/api/admin/templates/${encodeURIComponent(id)}/versions/${v.version}`)
    // Another template may have been opened while this loaded: never restore across templates.
    if (editingId.value !== id) return
    viewing.value = detail
    viewingOpen.value = true
  } catch (e) {
    $q.notify({ type: 'negative', message: e instanceof ApiError ? e.message : `Version ${v.version} could not be opened. Try again.` })
  } finally {
    viewingVersion.value = null
  }
}

/** Copies an earlier version into the form; saving it makes it the newest version. */
function restoreVersion(): void {
  const v = viewing.value
  if (v === null) return
  viewingOpen.value = false
  if (!dirty.value) {
    applyVersion(v)
    return
  }
  $q.dialog({
    title: 'Replace your unsaved changes?',
    message: `The template has changes you have not saved. Restoring version ${v.version} replaces them.`,
    cancel: { flat: true, noCaps: true, label: 'Keep my changes' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: `Restore version ${v.version}` },
    focus: 'cancel',
    persistent: true,
  }).onOk(() => applyVersion(v))
}

function applyVersion(v: TemplateVersionDetail): void {
  formName.value = v.name
  formDescription.value = v.description ?? ''
  formInitial.value = v.body
  formBody.value = v.body
  editorKey.value++
  $q.notify({ type: 'info', message: `Version ${v.version} copied into the editor. Save the template to make it the current version.` })
}

function fill(id: string, typeId: string | null, name: string, description: string, body: RichTextNode | null, rowVersion: number, active: boolean, version = 0): void {
  editingId.value = id
  formTypeId.value = typeId
  formName.value = name
  formDescription.value = description
  formInitial.value = body
  formBody.value = body
  formRowVersion.value = rowVersion
  formVersion.value = version
  formActive.value = active
  history.value = []
  historyError.value = null
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
    fill(detail.id, detail.documentType.id, detail.name, detail.description ?? '', detail.body, detail.rowVersion, detail.isActive, detail.version)
    await loadHistory()
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
      const created = await api<{ id: string; rowVersion: number; version: number }>('/api/admin/templates', {
        method: 'POST',
        body: { documentTypeId: formTypeId.value, name: formName.value, description: formDescription.value, body },
      })
      if (editingId.value === target) {
        editingId.value = created.id
        formRowVersion.value = created.rowVersion
        formVersion.value = created.version
      }
    } else {
      const updated = await api<{ rowVersion: number; version: number }>(`/api/admin/templates/${encodeURIComponent(editingId.value)}`, {
        method: 'PATCH',
        body: { name: formName.value, description: formDescription.value, body, rowVersion: formRowVersion.value },
      })
      if (editingId.value === target) {
        formRowVersion.value = updated.rowVersion
        formVersion.value = updated.version
      }
    }
    if (editingId.value === target || target === 'new') baseline.value = snapshot()
    $q.notify({ type: 'positive', message: `Template saved as version ${formVersion.value}. Documents already started from it are not changed.` })
    await Promise.all([load(), loadHistory()])
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
                <span class="pal-muted"> · {{ t.documentType.name }} · v{{ t.version }}<span v-if="!t.isActive"> · inactive</span></span>
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
                  v-if="editingId !== 'new' && formVersion > 0"
                  dense
                >
                  Version {{ formVersion }}
                </q-chip>
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
              <h3 class="text-body1 text-weight-medium q-mt-none q-mb-xs">
                Content
              </h3>
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

          <section
            v-if="editingId !== null && editingId !== 'new'"
            class="q-mt-lg"
            aria-labelledby="template-history-heading"
          >
            <h3
              id="template-history-heading"
              class="text-subtitle1 text-weight-medium q-mb-xs"
            >
              Version history
            </h3>
            <p class="pal-muted q-mb-sm">
              Every saved change to the name, description or content is kept as a version and can never be changed. Documents record the version they started from.
            </p>
            <q-banner
              v-if="historyError"
              class="bg-negative text-white q-mb-sm"
              rounded
              role="alert"
            >
              {{ historyError }}
              <template #action>
                <q-btn
                  flat
                  no-caps
                  label="Try again"
                  @click="loadHistory"
                />
              </template>
            </q-banner>
            <div
              v-if="historyLoading"
              class="q-pa-sm"
              role="status"
            >
              <q-spinner size="20px" /> Loading versions…
            </div>
            <q-markup-table
              v-else-if="history.length > 0"
              flat
              bordered
              wrap-cells
              class="pal-card q-pa-none"
            >
              <thead>
                <tr>
                  <th
                    class="text-left"
                    scope="col"
                  >
                    Version
                  </th>
                  <th
                    class="text-left"
                    scope="col"
                  >
                    Saved
                  </th>
                  <th
                    class="text-left"
                    scope="col"
                  >
                    Name
                  </th>
                  <th
                    class="text-right"
                    scope="col"
                  >
                    <span class="pal-visually-hidden">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="v in history"
                  :key="v.version"
                >
                  <td>
                    v{{ v.version }}<span
                      v-if="v.version === formVersion"
                      class="pal-muted"
                    > (current)</span>
                  </td>
                  <td>{{ formatDateTime(v.createdAt, session.effectiveTimezone) }} by {{ v.createdBy }}</td>
                  <td>{{ v.name }}</td>
                  <td class="text-right">
                    <q-btn
                      flat
                      dense
                      no-caps
                      label="View"
                      :aria-label="`View version ${v.version}`"
                      :loading="viewingVersion === v.version"
                      @click="viewVersion(v)"
                    />
                  </td>
                </tr>
              </tbody>
            </q-markup-table>
            <p
              v-else-if="!historyError"
              class="empty-state-sm"
            >
              No saved versions yet.
            </p>
          </section>
        </div>
      </section>
    </div>

    <q-dialog
      v-model="viewingOpen"
      aria-labelledby="template-version-title"
    >
      <q-card
        v-if="viewing"
        class="pal-version-dialog"
      >
        <q-card-section class="row items-center justify-between q-pb-none">
          <h2
            id="template-version-title"
            class="text-h6 q-my-none"
          >
            {{ viewing.name }} · version {{ viewing.version }}
          </h2>
          <q-btn
            v-close-popup
            flat
            round
            dense
            icon="close"
            aria-label="Close"
          />
        </q-card-section>
        <q-card-section>
          <p class="pal-muted q-mb-sm">
            Saved {{ formatDateTime(viewing.createdAt, session.effectiveTimezone) }} by {{ viewing.createdBy }}<span v-if="viewing.description">. {{ viewing.description }}</span>
          </p>
          <h3 class="pal-visually-hidden">
            Content
          </h3>
          <RichTextView
            :doc="viewing.body"
            :label="`Content of version ${viewing.version}`"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            v-close-popup
            flat
            no-caps
            label="Close"
          />
          <q-btn
            v-if="canEdit && viewing.version !== formVersion"
            color="primary"
            unelevated
            no-caps
            label="Restore into the editor"
            @click="restoreVersion"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>
