<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useSessionStore } from '@/stores/session'
import { formatDateTime, roleLabel } from '@/format'

const session = useSessionStore()
const health = ref<'checking' | 'ready' | 'degraded'>('checking')

const roles = computed(() => (session.me?.roles ?? []).map(roleLabel))

const sessionEnds = computed(() => {
  const at = session.me?.session.absoluteExpiresAt
  if (at === undefined || at === null) return null
  return formatDateTime(at, session.effectiveTimezone)
})

onMounted(async () => {
  try {
    const response = await fetch('/health/ready', { headers: { Accept: 'application/json' } })
    health.value = response.ok ? 'ready' : 'degraded'
  } catch {
    health.value = 'degraded'
  }
})
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Welcome, {{ session.displayName }}</h1>
      <p>Your account and the state of this PAL installation.</p>
    </header>

    <div class="row q-col-gutter-lg">
      <section
        class="col-12 col-md-6"
        aria-labelledby="account-heading"
      >
        <div class="pal-card">
          <h2
            id="account-heading"
            class="text-subtitle1 text-weight-medium q-mt-none"
          >
            Your access
          </h2>
          <div class="q-gutter-sm q-mb-md">
            <q-chip
              v-for="role in roles"
              :key="role"
              outline
              class="pal-role-chip"
            >
              {{ role }}
            </q-chip>
          </div>
          <dl class="q-ma-none">
            <dt class="text-weight-medium">
              Timezone
            </dt>
            <dd class="q-ml-none q-mb-sm pal-muted">
              {{ session.effectiveTimezone }}
              <span v-if="session.me?.user.timezone === null">(organisation default)</span>
            </dd>
            <dt class="text-weight-medium">
              Session ends
            </dt>
            <dd class="q-ml-none pal-muted">
              {{ sessionEnds ?? 'Unknown' }}
            </dd>
          </dl>
        </div>
      </section>

      <section
        class="col-12 col-md-6"
        aria-labelledby="system-heading"
      >
        <div class="pal-card">
          <h2
            id="system-heading"
            class="text-subtitle1 text-weight-medium q-mt-none"
          >
            System status
          </h2>
          <p
            class="q-mb-none"
            role="status"
          >
            <q-icon
              :name="health === 'ready' ? 'check_circle' : health === 'checking' ? 'hourglass_empty' : 'error'"
              :color="health === 'ready' ? 'positive' : health === 'checking' ? 'grey' : 'negative'"
              size="20px"
              aria-hidden="true"
            />
            {{ health === 'ready' ? 'All services are ready.' : health === 'checking' ? 'Checking…' : 'Some services are not ready. Contact your administrator.' }}
          </p>
          <p class="pal-muted q-mb-none q-mt-md">
            The document library, workflows and publishing arrive in the next releases.
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
