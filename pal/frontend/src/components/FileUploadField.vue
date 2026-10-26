<script setup lang="ts">
import { ref } from 'vue'
import { ApiError, uploadFile, type UploadResult } from '@/api/client'
import { formatBytes } from '@/api/documents'

const props = defineProps<{ id: string; label: string; disabled?: boolean }>()
const emit = defineEmits<{ uploaded: [result: UploadResult] }>()

const uploading = ref(false)
const error = ref<string | null>(null)
const done = ref<UploadResult | null>(null)

async function onChange(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file === undefined || uploading.value) return
  uploading.value = true
  error.value = null
  done.value = null
  try {
    done.value = await uploadFile(file)
    emit('uploaded', done.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'The upload failed.'
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div>
    <label
      :for="props.id"
      class="text-weight-medium"
    >{{ props.label }}</label>
    <input
      :id="props.id"
      type="file"
      class="block q-mt-xs"
      accept=".pdf,.docx,.xlsx,.pptx,.txt"
      :disabled="props.disabled || uploading"
      :aria-invalid="error !== null ? 'true' : undefined"
      :aria-describedby="`${props.id}-status ${props.id}-key`"
      @change="onChange"
    >
    <p
      :id="`${props.id}-status`"
      class="q-mt-xs q-mb-none"
      aria-live="polite"
    >
      <span v-if="uploading"><q-spinner size="16px" /> Uploading and scanning for malware…</span>
      <span
        v-else-if="error"
        class="pal-text-danger"
        role="alert"
      >{{ error }}</span>
      <span
        v-else-if="done"
        class="pal-muted"
      ><q-icon
        name="task_alt"
        aria-hidden="true"
      /> {{ done.filename }} ({{ formatBytes(done.sizeBytes) }}) scanned and ready.</span>
    </p>
    <dl
      :id="`${props.id}-key`"
      class="pal-field-key"
    >
      <dt>Accepted files</dt>
      <dd>PDF, Word (.docx), Excel (.xlsx), PowerPoint (.pptx) or plain text (.txt), up to 50 MB.</dd>
      <dt>Checked on upload</dt>
      <dd>The real file type is read from its contents and must match the extension. Every file is scanned for malware; infected or disguised files are refused and the attempt is recorded.</dd>
      <dt>Not accepted</dt>
      <dd>Macro-enabled files (.docm, .xlsm) and older binary Office formats (.doc, .xls, .ppt), which can hide macros. Save as .docx/.xlsx/.pptx or PDF instead.</dd>
    </dl>
  </div>
</template>
