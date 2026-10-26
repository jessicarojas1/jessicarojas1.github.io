<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { ReferenceItem } from '@/api/documents'
import FileUploadField from '@/components/FileUploadField.vue'

const router = useRouter()
const types = ref<ReferenceItem[]>([])
const departments = ref<ReferenceItem[]>([])
const typeId = ref<string | null>(null)
const departmentId = ref<string | null>(null)
const title = ref('')
const description = ref('')
const keywords = ref<string[]>([])
const restricted = ref(false)
const uploadId = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

onMounted(async () => {
  try {
    const data = await api<{ documentTypes: ReferenceItem[]; departments: ReferenceItem[] }>('/api/reference')
    types.value = data.documentTypes
    departments.value = data.departments
  } catch {
    formError.value = 'Document types and departments could not be loaded. Reload the page.'
  }
})

async function create(): Promise<void> {
  if (saving.value) return
  saving.value = true
  fieldErrors.value = {}
  formError.value = null
  try {
    const result = await api<{ id: string }>('/api/documents', {
      method: 'POST',
      body: { typeId: typeId.value, departmentId: departmentId.value, title: title.value, description: description.value, keywords: keywords.value, restricted: restricted.value, uploadId: uploadId.value },
    })
    await router.push(`/documents/${result.id}`)
  } catch (e) {
    if (e instanceof ApiError) {
      fieldErrors.value = e.fields
      formError.value = Object.keys(e.fields).length > 0 ? 'Fix the highlighted fields.' : e.message
    } else {
      formError.value = 'The document could not be created.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>New document</h1>
      <p>Creates the document and its first draft (revision 1.0). It stays private to you and the people you add until it is approved and released.</p>
    </header>

    <form
      class="pal-card q-gutter-y-md pal-form-narrow"
      novalidate
      @submit.prevent="create"
    >
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
          v-model="typeId"
          class="col-12 col-sm-6"
          :options="types.map((t) => ({ value: t.id, label: `${t.name} (${t.code})` }))"
          emit-value
          map-options
          outlined
          label="Document type"
          :error="fieldErrors.typeId !== undefined"
          :error-message="fieldErrors.typeId"
        />
        <q-select
          v-model="departmentId"
          class="col-12 col-sm-6"
          :options="departments.map((d) => ({ value: d.id, label: `${d.name} (${d.code})` }))"
          emit-value
          map-options
          outlined
          label="Owning department"
          :hint="departments.length === 0 ? 'No departments yet: a PAL administrator adds them.' : 'Sets the document number, e.g. SOP-QA-0001.'"
          :error="fieldErrors.departmentId !== undefined"
          :error-message="fieldErrors.departmentId"
        />
      </div>
      <q-input
        v-model="title"
        outlined
        label="Title"
        maxlength="300"
        :error="fieldErrors.title !== undefined"
        :error-message="fieldErrors.title"
      />
      <q-input
        v-model="description"
        outlined
        type="textarea"
        autogrow
        label="Description (optional)"
        maxlength="4000"
        :error="fieldErrors.description !== undefined"
        :error-message="fieldErrors.description"
      />
      <q-select
        v-model="keywords"
        outlined
        label="Keywords (optional)"
        hint="Press Enter after each keyword."
        multiple
        use-chips
        use-input
        hide-dropdown-icon
        new-value-mode="add-unique"
        input-debounce="0"
        :error="fieldErrors.keywords !== undefined"
        :error-message="fieldErrors.keywords"
      />
      <q-toggle
        v-model="restricted"
        label="Restricted: only people and groups given access can read it once published"
      />
      <FileUploadField
        id="new-doc-file"
        label="Document file (you can also add it later)"
        @uploaded="uploadId = $event.id"
      />
      <p
        v-if="fieldErrors.uploadId"
        class="pal-text-danger"
        role="alert"
      >
        {{ fieldErrors.uploadId }}
      </p>
      <div class="row justify-end q-gutter-sm">
        <q-btn
          flat
          no-caps
          label="Cancel"
          to="/library"
        />
        <q-btn
          type="submit"
          color="primary"
          unelevated
          no-caps
          label="Create draft"
          :loading="saving"
        />
      </div>
    </form>
  </div>
</template>
