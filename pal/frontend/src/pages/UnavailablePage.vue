<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { useSessionStore } from '@/stores/session'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const retrying = ref(false)

const returnTo = computed(() => {
  const value = route.query.returnTo
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : '/'
})

async function retry(): Promise<void> {
  retrying.value = true
  await session.load()
  retrying.value = false
  if (session.status !== 'unreachable') await router.replace(returnTo.value)
}
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="flex flex-center q-pa-md">
        <section
          class="pal-card pal-signin-card"
          aria-labelledby="unavailable-heading"
        >
          <div class="text-h6 q-mb-md">
            <BrandMark />
          </div>
          <h1
            id="unavailable-heading"
            class="text-h5 q-mt-none q-mb-sm"
          >
            PAL can't be reached
          </h1>
          <p
            class="pal-muted"
            role="alert"
          >
            The service did not respond. Check your connection, then try again. If this continues, contact your administrator.
          </p>
          <q-btn
            color="primary"
            unelevated
            no-caps
            class="full-width"
            icon="refresh"
            label="Try again"
            :loading="retrying"
            @click="retry"
          />
        </section>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
