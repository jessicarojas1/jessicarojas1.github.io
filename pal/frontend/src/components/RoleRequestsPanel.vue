<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { formatDateTime, roleLabel } from '@/format'
import { useSessionStore } from '@/stores/session'

/**
 * Role changes held by the two-person rule (decision #31), and changes that went through
 * without a second approver because only one administrator existed. Shown on the Users page.
 */
interface RoleRequest {
  id: string
  target: { id: string; displayName: string }
  requestedBy: { id: string; displayName: string }
  from: string[]
  to: string[]
  reason: string | null
  createdAt: string
  expiresAt: string
  canDecide: boolean
  canCancel: boolean
}
interface SoleChange { id: string; at: string; by: string; user: string; from: string[]; to: string[] }

export type { RoleRequest }

const emit = defineEmits<{ changed: []; loaded: [requests: RoleRequest[]] }>()
const $q = useQuasar()
const session = useSessionStore()
const requests = ref<RoleRequest[]>([])
const sole = ref<SoleChange[]>([])
const error = ref<string | null>(null)
const busy = ref<string | null>(null)

const list = (roles: string[]): string => (roles.length === 0 ? 'Reader only' : roles.map(roleLabel).join(', '))
const heading = ref<HTMLElement | null>(null)

async function load(): Promise<void> {
  error.value = null
  try {
    const data = await api<{ requests: RoleRequest[]; soleAdministratorChanges: SoleChange[] }>('/api/admin/role-requests')
    requests.value = data.requests
    sole.value = data.soleAdministratorChanges
    emit('loaded', data.requests)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Pending role changes could not be loaded.'
  }
}

async function decide(request: RoleRequest, action: 'approve' | 'reject', note = ''): Promise<void> {
  if (busy.value !== null) return
  busy.value = request.id
  try {
    await api(`/api/admin/role-requests/${request.id}/${action}`, { method: 'POST', body: action === 'reject' ? { note } : {} })
    const verb = action === 'approve' ? 'approved' : request.canCancel ? 'cancelled' : 'rejected'
    $q.notify({ type: 'positive', message: `Role change for ${request.target.displayName} ${verb}.` })
    emit('changed')
    await load()
    // The decided item is gone; keep keyboard and screen-reader users in the panel.
    heading.value?.focus()
  } catch (e) {
    $q.notify({ type: 'negative', message: e instanceof ApiError ? e.message : 'That did not work. Try again.' })
  } finally {
    busy.value = null
  }
}

function approve(request: RoleRequest): void {
  $q.dialog({
    title: `Approve the role change for ${request.target.displayName}?`,
    message: `${request.requestedBy.displayName} asked to change their roles from ${list(request.from)} to ${list(request.to)}. You are the second person confirming it.`,
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'primary', unelevated: true, noCaps: true, label: 'Approve' },
    focus: 'cancel',
    persistent: true,
  }).onOk(() => void decide(request, 'approve'))
}

function reject(request: RoleRequest): void {
  const cancel = request.canCancel
  $q.dialog({
    title: cancel ? 'Cancel your request?' : `Reject the role change for ${request.target.displayName}?`,
    message: cancel ? 'Nothing changes and the request is closed.' : 'Nothing changes. Optionally say why.',
    prompt: { model: '', type: 'text', label: cancel ? 'Note (optional)' : 'Reason (optional)' },
    cancel: { flat: true, noCaps: true, label: 'Back' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: cancel ? 'Cancel request' : 'Reject' },
    focus: 'cancel',
    persistent: true,
  }).onOk((note: string) => void decide(request, 'reject', note))
}

defineExpose({ load })
onMounted(load)
</script>

<template>
  <section
    v-if="error || requests.length > 0 || sole.length > 0"
    class="pal-card q-mb-md"
    aria-labelledby="role-requests-heading"
  >
    <h2
      id="role-requests-heading"
      ref="heading"
      tabindex="-1"
      class="text-h6 q-mt-none"
    >
      Role changes needing a second person
    </h2>
    <p class="pal-muted">
      Making someone an Owner, removing an Owner from someone else, and giving yourself System Administrator, PAL Administrator, Publisher or Author must be confirmed by a second Owner or System Administrator, who is neither the person asking nor the person changed.
    </p>
    <q-banner
      v-if="error"
      class="bg-negative text-white q-mb-md"
      rounded
      role="alert"
    >
      {{ error }}
      <template #action>
        <q-btn
          flat
          no-caps
          label="Try again"
          @click="load"
        />
      </template>
    </q-banner>
    <ul
      v-if="requests.length > 0"
      class="pal-plain-list"
    >
      <li
        v-for="r in requests"
        :key="r.id"
        class="pal-list-item"
      >
        <p class="q-mb-xs">
          <strong>{{ r.target.displayName }}</strong>: {{ list(r.from) }} → <strong>{{ list(r.to) }}</strong>
        </p>
        <p class="pal-muted q-mb-xs">
          Requested by {{ r.requestedBy.displayName }}, {{ formatDateTime(r.createdAt, session.effectiveTimezone) }}; expires {{ formatDateTime(r.expiresAt, session.effectiveTimezone) }}.
          <span v-if="r.reason"> Reason: {{ r.reason }}</span>
        </p>
        <div class="row q-gutter-sm">
          <q-btn
            v-if="r.canDecide"
            color="primary"
            unelevated
            dense
            no-caps
            label="Approve"
            :loading="busy === r.id"
            :disable="busy !== null && busy !== r.id"
            @click="approve(r)"
          />
          <q-btn
            v-if="r.canDecide || r.canCancel"
            flat
            dense
            no-caps
            class="pal-text-danger"
            :label="r.canCancel ? 'Cancel request' : 'Reject'"
            :loading="busy === r.id"
            :disable="busy !== null && busy !== r.id"
            @click="reject(r)"
          />
          <span
            v-if="!r.canDecide && !r.canCancel"
            class="pal-muted"
          >Waiting for another Owner or System Administrator.</span>
        </div>
      </li>
    </ul>
    <p
      v-else-if="!error"
      class="empty-state-sm"
    >
      No role changes are waiting.
    </p>
    <div
      v-if="sole.length > 0"
      class="q-mt-md"
    >
      <h3 class="text-subtitle1 text-weight-medium q-my-xs">
        Changes made without a second approver
      </h3>
      <p class="pal-muted q-mb-xs">
        Applied immediately because no other active Owner or System Administrator existed (last 90 days). Review them, and add a second administrator so future changes get two-person approval.
      </p>
      <ul class="pal-plain-list">
        <li
          v-for="c in sole"
          :key="c.id"
        >
          {{ formatDateTime(c.at, session.effectiveTimezone) }}: {{ c.by }} changed {{ c.user }} from {{ list(c.from) }} to <strong>{{ list(c.to) }}</strong>
        </li>
      </ul>
    </div>
  </section>
</template>
