<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, uploadFile, type UploadResult } from '@/api/client'
import { formatBytes, loadReference } from '@/api/documents'

const props = defineProps<{ id: string; label: string; disabled?: boolean }>()
const emit = defineEmits<{
  uploaded: [result: UploadResult]
  // Fired when a previous result no longer applies (new attempt started, or the user removed it).
  cleared: []
  uploading: [active: boolean]
}>()

const uploading = ref(false)
const error = ref<string | null>(null)
const done = ref<UploadResult | null>(null)
const maxBytes = ref<number | null>(null)

onMounted(async () => {
  try {
    maxBytes.value = (await loadReference()).maxUploadBytes
  } catch {
    // The key falls back to generic wording; the server still enforces the limit.
  }
})

function setUploading(active: boolean): void {
  uploading.value = active
  emit('uploading', active)
}

async function onChange(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file === undefined || uploading.value) return
  error.value = null
  if (done.value !== null) {
    done.value = null
    emit('cleared')
  }
  if (maxBytes.value !== null && file.size > maxBytes.value) {
    error.value = `This file is ${formatBytes(file.size)}; files can be up to ${formatBytes(maxBytes.value)}.`
    return
  }
  setUploading(true)
  try {
    done.value = await uploadFile(file)
    emit('uploaded', done.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'The upload failed.'
  } finally {
    setUploading(false)
  }
}

function remove(): void {
  done.value = null
  error.value = null
  emit('cleared')
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
    <q-btn
      v-if="done && !uploading"
      flat
      dense
      no-caps
      icon="close"
      :label="`Remove ${done.filename}`"
      :disable="props.disabled"
      @click="remove"
    />
    <dl
      :id="`${props.id}-key`"
      class="pal-field-key"
    >
      <dt>Accepted files</dt>
      <dd>PDF, Word (.docx), Excel (.xlsx), PowerPoint (.pptx) or plain text (.txt), up to {{ maxBytes !== null ? formatBytes(maxBytes) : 'the configured size limit' }}.</dd>
      <dt>Checked on upload</dt>
      <dd>The real file type is read from its contents and must match the extension. Every file is scanned for malware; infected or disguised files are refused and the attempt is recorded.</dd>
      <dt>Not accepted</dt>
      <dd>Macro-enabled files (.docm, .xlsm) and older binary Office formats (.doc, .xls, .ppt), which can hide macros. Save as .docx/.xlsx/.pptx or PDF instead.</dd>
    </dl>
  </div>
</template>
