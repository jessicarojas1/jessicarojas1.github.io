<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'

interface Mapping {
  id: string
  role: string
  group: { objectId: string; displayName: string; memberCount: number; syncedAt: string | null; syncError: string | null }
  createdAt: string
}

const ROLE_LABELS: Record<string, string> = {
  SYSTEM_ADMINISTRATOR: 'System Administrator',
  PAL_ADMINISTRATOR: 'PAL Administrator',
  PUBLISHER: 'Publisher',
  AUTHOR: 'Author',
}

const $q = useQuasar()
const loading = ref(true)
const loadError = ref<string | null>(null)
const mappings = ref<Mapping[]>([])
const roles = ref<string[]>([])
const directoryConfigured = ref(true)
const newRole = ref<string | null>(null)
const newGroup = ref('')
const fieldErrors = ref<Record<string, string>>({})
const adding = ref(false)

const columns = [
  { name: 'role', label: 'Role', field: (row: Mapping) => ROLE_LABELS[row.role] ?? row.role, align: 'left' as const, sortable: true },
  { name: 'group', label: 'Entra group', field: (row: Mapping) => row.group.displayName, align: 'left' as const, sortable: true },
  { name: 'members', label: 'Members', field: (row: Mapping) => row.group.memberCount, align: 'right' as const },
  { name: 'sync', label: 'Last sync', field: (row: Mapping) => row.group.syncedAt, align: 'left' as const },
  { name: 'actions', label: 'Actions', field: 'id', align: 'right' as const },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const data = await api<{ roles: string[]; directoryConfigured: boolean; mappings: Mapping[] }>('/api/admin/role-mappings')
    mappings.value = data.mappings
    roles.value = data.roles
    directoryConfigured.value = data.directoryConfigured
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Role mappings could not be loaded.'
  } finally {
    loading.value = false
  }
}

async function add(): Promise<void> {
  adding.value = true
  fieldErrors.value = {}
  try {
    await api('/api/admin/role-mappings', { method: 'POST', body: { role: newRole.value, groupObjectId: newGroup.value.trim() } })
    $q.notify({ type: 'positive', message: 'Mapping added. Members were synced from the directory.' })
    newRole.value = null
    newGroup.value = ''
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      fieldErrors.value = e.fields
      if (Object.keys(e.fields).length === 0) $q.notify({ type: 'negative', message: e.message })
    } else {
      $q.notify({ type: 'negative', message: 'The mapping could not be added.' })
    }
  } finally {
    adding.value = false
  }
}

function confirmRemove(mapping: Mapping): void {
  $q.dialog({
    title: 'Remove role mapping?',
    message: `Members of "${mapping.group.displayName}" will lose the ${ROLE_LABELS[mapping.role] ?? mapping.role} role unless another mapping grants it.`,
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Remove mapping' },
    persistent: true,
  }).onOk(() => {
    void (async () => {
      try {
        await api(`/api/admin/role-mappings/${encodeURIComponent(mapping.id)}`, { method: 'DELETE' })
        $q.notify({ type: 'positive', message: 'Mapping removed.' })
        await load()
      } catch (e) {
        $q.notify({ type: 'negative', message: e instanceof ApiError ? e.message : 'The mapping could not be removed.' })
      }
    })()
  })
}

function formatTime(value: string | null): string {
  return value === null ? 'Never' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

onMounted(load)
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Role mappings</h1>
      <p>Grant organisation-wide roles to Entra groups. Everyone who can sign in is a Reader; membership syncs every 15 minutes and at each sign-in.</p>
    </header>

    <q-banner
      v-if="!directoryConfigured"
      class="bg-warning text-white q-mb-md"
      rounded
      role="status"
    >
      Directory sync is not configured, so groups cannot be resolved yet. See the deployment guide.
    </q-banner>

    <form
      class="pal-card q-mb-lg"
      novalidate
      @submit.prevent="add"
    >
      <h2 class="text-subtitle1 text-weight-medium q-mt-none">
        Add a mapping
      </h2>
      <div class="row q-col-gutter-md items-start">
        <q-select
          v-model="newRole"
          class="col-12 col-sm-4"
          :options="roles.map((value) => ({ value, label: ROLE_LABELS[value] ?? value }))"
          emit-value
          map-options
          label="Role"
          outlined
          :error="fieldErrors.role !== undefined"
          :error-message="fieldErrors.role"
        />
        <q-input
          v-model="newGroup"
          class="col-12 col-sm-6"
          label="Entra group object ID"
          outlined
          hint="Found on the group's overview page in the Entra admin centre."
          :error="fieldErrors.groupObjectId !== undefined"
          :error-message="fieldErrors.groupObjectId"
        />
        <div class="col-12 col-sm-2">
          <q-btn
            type="submit"
            color="primary"
            unelevated
            no-caps
            class="full-width pal-field-height"
            label="Add"
            :loading="adding"
            :disable="!directoryConfigured || newRole === null || newGroup.trim() === ''"
          />
        </div>
      </div>
    </form>

    <q-banner
      v-if="loadError"
      class="bg-negative text-white"
      rounded
      role="alert"
    >
      {{ loadError }}
    </q-banner>
    <q-table
      v-else
      flat
      bordered
      title="Current mappings"
      :rows="mappings"
      :columns="columns"
      row-key="id"
      :loading="loading"
      :pagination="{ rowsPerPage: 25 }"
      no-data-label="No role mappings yet. Until one exists, only bootstrap administrators can manage PAL."
    >
      <template #body-cell-group="props">
        <q-td :props="props">
          <div>{{ props.row.group.displayName }}</div>
          <div class="text-caption pal-muted">
            {{ props.row.group.objectId }}
          </div>
        </q-td>
      </template>
      <template #body-cell-sync="props">
        <q-td :props="props">
          <span v-if="props.row.group.syncError">
            <q-icon
              name="error"
              color="negative"
              aria-hidden="true"
            /> Failed: {{ props.row.group.syncError }}
          </span>
          <span v-else>{{ formatTime(props.row.group.syncedAt) }}</span>
        </q-td>
      </template>
      <template #body-cell-actions="props">
        <q-td :props="props">
          <q-btn
            flat
            dense
            no-caps
            color="negative"
            icon="delete"
            :label="'Remove'"
            :aria-label="`Remove mapping for ${props.row.group.displayName}`"
            @click="confirmRemove(props.row)"
          />
        </q-td>
      </template>
    </q-table>
  </div>
</template>
