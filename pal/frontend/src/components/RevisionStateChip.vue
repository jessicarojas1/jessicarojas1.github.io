<script setup lang="ts">
import { computed } from 'vue'
import { STATE_LABELS, type RevisionView } from '@/api/documents'

const props = defineProps<{ state: RevisionView['state'] }>()

// Text always carries the meaning; the icon only reinforces it (never colour alone).
const icon = computed(() => ({
  DRAFT: 'edit_note',
  IN_WORKFLOW: 'hourglass_top',
  CHANGES_REQUESTED: 'rate_review',
  REJECTED: 'block',
  APPROVED: 'verified',
  WITHDRAWN: 'remove_circle_outline',
})[props.state])
</script>

<template>
  <span class="pal-state-chip">
    <q-icon
      :name="icon"
      size="16px"
      aria-hidden="true"
    />
    {{ STATE_LABELS[state] }}
  </span>
</template>
