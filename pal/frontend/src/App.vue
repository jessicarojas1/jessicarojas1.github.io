<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useBrandingStore } from '@/stores/branding'

const branding = useBrandingStore()
const route = useRoute()

onMounted(() => {
  void branding.load()
})

// Page title: "<page> · <organisation display name>".
watch(
  () => [route.meta.title, branding.branding.displayName] as const,
  ([title, name]) => {
    document.title = title !== undefined ? `${title} · ${name}` : name
  },
  { immediate: true },
)
</script>

<template>
  <router-view />
</template>
