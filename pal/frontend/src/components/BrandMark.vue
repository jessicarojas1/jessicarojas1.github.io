<script setup lang="ts">
import { useBrandingStore } from '@/stores/branding'

const branding = useBrandingStore()
</script>

<template>
  <span class="row items-center no-wrap q-gutter-sm">
    <img
      v-if="branding.logo"
      :src="branding.logo"
      alt=""
      class="pal-brand-logo"
      @error="branding.markLogoBroken()"
    >
    <q-icon
      v-else
      name="library_books"
      size="28px"
      aria-hidden="true"
    />
    <span class="text-weight-bold">{{ branding.branding.displayName }}</span>
  </span>
</template>
