<script setup lang="ts">
import { useLeaveGuard } from '@/composables/useLeaveGuard'
import { computed, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { formatDateTime, roleLabel } from '@/format'
import { useSessionStore } from '@/stores/session'

interface AdminUser {
  id: string
  displayName: string
  email: string | null
  username: string | null
  signInMethod: 'password' | 'entra'
  isActive: boolean
  lastSignInAt: string | null
  lockedUntil: string | null
  mustChangePassword: boolean
  explicitRoles: string[]
  groupRoles: string[]
}
interface RoleInfo { value: string; permissions: string[] }

const MODULES: Record<string, string> = {
  library: 'Library',
  document: 'Documents',
  workflow: 'Approvals',
  publication: 'Publication',
  admin: 'Administration',
  audit: 'Audit',
  report: 'Reports',
  system: 'System',
}

const $q = useQuasar()
const session = useSessionStore()
const users = ref<AdminUser[]>([])
const roles = ref<RoleInfo[]>([])
const search = ref('')
const selectedId = ref<string | null>(null)
const draftRoles = ref<string[]>([])
const saving = ref(false)
const loadError = ref<string | null>(null)
const loading = ref(true)
const activating = ref(false)
const actionError = ref<string | null>(null)
const openModules = ref<string[]>([])
const creating = ref(false)
const newUser = ref({ username: '', displayName: '', email: '', roles: [] as string[] })
const createErrors = ref<Record<string, string>>({})

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return needle === '' ? users.value : users.value.filter((u) => [u.displayName, u.email ?? '', u.username ?? ''].some((v) => v.toLowerCase().includes(needle)))
})
const selected = computed(() => users.value.find((u) => u.id === selectedId.value) ?? null)
const dirty = computed(() => selected.value !== null && [...draftRoles.value].sort().join() !== [...selected.value.explicitRoles].sort().join())
const allPermissions = computed(() => [...new Set(roles.value.flatMap((r) => r.permissions))].sort())
const effective = computed(() => {
  if (selected.value === null) return new Set<string>()
  const held = new Set([...draftRoles.value, ...selected.value.groupRoles])
  return new Set(roles.value.filter((r) => held.has(r.value)).flatMap((r) => r.permissions).concat('library.read'))
})
const modules = computed(() => {
  const groups: Record<string, string[]> = {}
  for (const p of [...allPermissions.value, 'library.read']) {
    const key = p.split('.')[0] ?? p
    const list = (groups[key] ??= [])
    if (!list.includes(p)) list.push(p)
  }
  return Object.entries(groups).map(([key, perms]) => ({ key, label: MODULES[key] ?? key, perms, granted: perms.filter((p) => effective.value.has(p)).length }))
})
const totalGranted = computed(() => effective.value.size)

function initials(name: string): string {
  return name.split(/\s+/).map((p) => p[0] ?? '').join('').slice(0, 2).toUpperCase()
}

function roleState(role: string): 'group' | 'explicit' | 'none' {
  if (selected.value?.groupRoles.includes(role) === true) return 'group'
  return draftRoles.value.includes(role) ? 'explicit' : 'none'
}

async function load(): Promise<void> {
  loadError.value = null
  try {
    const data = await api<{ users: AdminUser[]; roles: RoleInfo[] }>('/api/admin/users')
    users.value = data.users
    roles.value = data.roles
    if (selectedId.value === null && data.users[0] !== undefined) selectedId.value = data.users[0].id
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Users could not be loaded.'
  } finally {
    loading.value = false
  }
}

watch(selected, (user, previous) => {
  const sameUser = user !== null && user.id === previous?.id
  // A refresh of the same user (after a reset, say) must not discard unsaved role edits.
  if (sameUser && [...draftRoles.value].sort().join() !== [...previous.explicitRoles].sort().join()) return
  draftRoles.value = user === null ? [] : [...user.explicitRoles]
  if (sameUser) return
  actionError.value = null
  openModules.value = modules.value.slice(0, 3).map((m) => m.key)
})

useLeaveGuard(dirty)

function selectUser(id: string): void {
  if (dirty.value) {
    $q.dialog({ title: 'Discard unsaved changes?', message: 'The role changes for this user have not been saved.', cancel: { flat: true, noCaps: true, label: 'Keep editing' }, ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Discard' }, focus: 'cancel' })
      .onOk(() => { selectedId.value = id })
    return
  }
  selectedId.value = id
}

function toggleRole(role: string): void {
  draftRoles.value = draftRoles.value.includes(role) ? draftRoles.value.filter((r) => r !== role) : [...draftRoles.value, role]
}

async function saveRoles(): Promise<void> {
  if (selected.value === null || saving.value) return
  saving.value = true
  actionError.value = null
  try {
    await api(`/api/admin/users/${selected.value.id}/roles`, { method: 'PUT', body: { roles: draftRoles.value } })
    $q.notify({ type: 'positive', message: 'Roles saved.' })
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Roles could not be saved.'
  } finally {
    saving.value = false
  }
}

function showTemporary(name: string, password: string): void {
  $q.dialog({
    title: 'Temporary password',
    message: `Give this to ${name} through a secure channel. It is shown only once, and they must change it when they sign in:\n\n${password}`,
    ok: { color: 'primary', unelevated: true, noCaps: true, label: 'Done' },
    persistent: true,
    style: 'white-space: pre-line',
  })
}

function resetPassword(user: AdminUser): void {
  $q.dialog({ title: `Reset ${user.displayName}'s password?`, message: 'Their current password stops working immediately. You will see a temporary password once.', cancel: { flat: true, noCaps: true, label: 'Cancel' }, ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Reset password' }, focus: 'cancel', persistent: true })
    .onOk(() => {
      void (async () => {
        try {
          const result = await api<{ temporaryPassword: string }>(`/api/admin/users/${user.id}/reset-password`, { method: 'POST' })
          showTemporary(user.displayName, result.temporaryPassword)
          await load()
        } catch (e) {
          actionError.value = e instanceof ApiError ? e.message : 'The password could not be reset.'
        }
      })()
    })
}

function confirmSetActive(user: AdminUser, active: boolean): void {
  if (active) {
    void setActive(user, true)
    return
  }
  $q.dialog({ title: `Deactivate ${user.displayName}?`, message: 'They are signed out and cannot sign in until the account is reactivated. Their history is kept.', cancel: { flat: true, noCaps: true, label: 'Cancel' }, ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Deactivate' }, focus: 'cancel', persistent: true })
    .onOk(() => { void setActive(user, false) })
}

async function setActive(user: AdminUser, active: boolean): Promise<void> {
  if (activating.value) return
  activating.value = true
  actionError.value = null
  try {
    await api(`/api/admin/users/${user.id}/active`, { method: 'PUT', body: { isActive: active } })
    $q.notify({ type: 'positive', message: active ? 'Account reactivated.' : 'Account deactivated.' })
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'The account could not be updated.'
  } finally {
    activating.value = false
  }
}

async function createUser(): Promise<void> {
  if (saving.value) return
  saving.value = true
  createErrors.value = {}
  try {
    const result = await api<{ id: string; temporaryPassword: string }>('/api/admin/users', { method: 'POST', body: newUser.value })
    creating.value = false
    showTemporary(newUser.value.displayName, result.temporaryPassword)
    newUser.value = { username: '', displayName: '', email: '', roles: [] }
    await load()
    selectedId.value = result.id
  } catch (e) {
    createErrors.value = e instanceof ApiError ? { ...e.fields, ...(Object.keys(e.fields).length === 0 ? { form: e.message } : {}) } : { form: 'The account could not be created.' }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="pal-page pal-page-wide">
    <header class="pal-page-header row items-start justify-between q-gutter-sm">
      <div>
        <h1>Users</h1>
        <p>Local accounts and single sign-on users, and the organisation-wide roles they hold. Document-level access is managed on each document.</p>
      </div>
      <q-btn
        color="primary"
        unelevated
        no-caps
        icon="person_add"
        label="New local user"
        @click="creating = true"
      />
    </header>
    <q-banner
      v-if="loadError"
      class="bg-negative text-white q-mb-md"
      rounded
      role="alert"
    >
      {{ loadError }}
    </q-banner>

    <div class="pal-iam">
      <!-- User list -->
      <section
        class="pal-iam-list pal-card q-pa-none"
        aria-labelledby="user-list-heading"
      >
        <h2
          id="user-list-heading"
          class="pal-visually-hidden"
        >
          User list
        </h2>
        <div class="q-pa-md">
          <q-input
            v-model="search"
            outlined
            dense
            clearable
            label="Search users"
          >
            <template #prepend>
              <q-icon
                name="search"
                aria-hidden="true"
              />
            </template>
          </q-input>
        </div>
        <div
          v-if="loading"
          class="q-pa-md"
          role="status"
        >
          <q-spinner size="24px" /> Loading users…
        </div>
        <p
          v-else-if="filtered.length === 0 && !loadError"
          class="empty-state-sm q-pa-md"
        >
          No users match.
        </p>
        <ul
          v-else-if="filtered.length > 0"
          class="pal-plain-list"
        >
          <li
            v-for="user in filtered"
            :key="user.id"
          >
            <button
              type="button"
              class="pal-iam-user"
              :class="{ 'pal-iam-user--active': user.id === selectedId }"
              :aria-current="user.id === selectedId ? 'true' : undefined"
              @click="selectUser(user.id)"
            >
              <span
                class="pal-avatar"
                aria-hidden="true"
              >{{ initials(user.displayName) }}</span>
              <span class="pal-iam-user-text">
                <span class="text-weight-medium">{{ user.displayName }}<span
                  v-if="!user.isActive"
                  class="pal-muted"
                > (inactive)</span></span>
                <span class="pal-muted text-caption">{{ user.signInMethod === 'password' ? `Password · ${user.username}` : `Entra · ${user.email ?? ''}` }}</span>
                <span class="text-caption">{{ [...new Set([...user.explicitRoles, ...user.groupRoles])].map(roleLabel).join(', ') || 'Reader' }}</span>
              </span>
            </button>
          </li>
        </ul>
      </section>

      <!-- Editor -->
      <section
        v-if="selected"
        class="pal-iam-editor"
        aria-labelledby="editor-heading"
      >
        <div class="pal-card">
          <h2
            id="editor-heading"
            class="text-h6 q-mt-none q-mb-xs"
          >
            {{ selected.displayName }}
          </h2>
          <p class="pal-muted q-mb-sm">
            {{ selected.signInMethod === 'password' ? `Local account · username ${selected.username}` : `Microsoft Entra ID · ${selected.email ?? ''}` }}
            · Last sign-in: {{ selected.lastSignInAt ? formatDateTime(selected.lastSignInAt, session.effectiveTimezone) : 'never' }}
            <span v-if="selected.lockedUntil && new Date(selected.lockedUntil) > new Date()"> · Locked until {{ formatDateTime(selected.lockedUntil, session.effectiveTimezone) }}</span>
            <span v-if="selected.mustChangePassword"> · Must change password</span>
          </p>
          <q-banner
            v-if="actionError"
            class="bg-negative text-white q-mb-md"
            rounded
            role="alert"
          >
            {{ actionError }}
          </q-banner>
          <div class="row q-gutter-sm">
            <q-btn
              v-if="selected.signInMethod === 'password'"
              outline
              no-caps
              icon="lock_reset"
              label="Reset password"
              @click="resetPassword(selected)"
            />
            <q-btn
              v-if="selected.id !== session.me?.user.id"
              flat
              no-caps
              :class="selected.isActive ? 'pal-text-danger' : ''"
              :label="selected.isActive ? 'Deactivate account' : 'Reactivate account'"
              :loading="activating"
              @click="confirmSetActive(selected, !selected.isActive)"
            />
          </div>
        </div>

        <div class="pal-card q-mt-md">
          <div class="row items-center justify-between q-gutter-sm">
            <h3 class="text-subtitle1 text-weight-medium q-my-none">
              Roles
            </h3>
            <q-btn
              color="primary"
              unelevated
              no-caps
              :label="dirty ? 'Save changes' : 'Saved'"
              :loading="saving"
              :disable="!dirty || saving"
              @click="saveRoles"
            />
          </div>
          <p
            v-if="dirty"
            class="pal-text-primary q-mb-none q-mt-xs"
            role="status"
          >
            Unsaved changes
          </p>
          <ul
            class="pal-legend pal-plain-list q-my-sm"
            aria-label="Legend"
          >
            <li><span class="pal-dot pal-dot--group" /> From a directory group (change the group mapping or membership)</li>
            <li><span class="pal-dot pal-dot--explicit" /> Granted directly here</li>
            <li><span class="pal-dot pal-dot--none" /> Not held</li>
          </ul>
          <ul class="pal-plain-list">
            <li
              v-for="role in roles"
              :key="role.value"
              class="row items-center q-py-xs"
            >
              <span
                class="pal-dot q-mr-sm"
                :class="`pal-dot--${roleState(role.value)}`"
                aria-hidden="true"
              />
              <q-checkbox
                :model-value="draftRoles.includes(role.value) || selected.groupRoles.includes(role.value)"
                :disable="selected.groupRoles.includes(role.value)"
                :label="`${roleLabel(role.value)}${selected.groupRoles.includes(role.value) ? ' (from group)' : ''}`"
                @update:model-value="toggleRole(role.value)"
              />
            </li>
          </ul>
        </div>

        <div class="pal-card q-mt-md">
          <div class="row items-center justify-between q-gutter-sm">
            <h3 class="text-subtitle1 text-weight-medium q-my-none">
              Effective permissions <span class="pal-muted">({{ totalGranted }}/{{ allPermissions.length + (allPermissions.includes('library.read') ? 0 : 1) }})</span>
            </h3>
            <div class="row q-gutter-xs">
              <q-btn
                flat
                dense
                no-caps
                label="Expand all"
                @click="openModules = modules.map((m) => m.key)"
              />
              <q-btn
                flat
                dense
                no-caps
                label="Collapse all"
                @click="openModules = []"
              />
            </div>
          </div>
          <div class="q-mt-sm">
            <details
              v-for="mod in modules"
              :key="mod.key"
              class="pal-accordion"
              :open="openModules.includes(mod.key)"
              @toggle="(e: Event) => { const open = (e.target as HTMLDetailsElement).open; openModules = open ? [...new Set([...openModules, mod.key])] : openModules.filter((k) => k !== mod.key) }"
            >
              <summary>
                <span class="text-weight-medium">{{ mod.label }}</span>
                <span class="pal-muted">{{ mod.granted }}/{{ mod.perms.length }} granted</span>
              </summary>
              <ul class="pal-plain-list q-px-md q-pb-sm">
                <li
                  v-for="p in mod.perms"
                  :key="p"
                  class="row items-center q-gutter-sm"
                >
                  <q-icon
                    :name="effective.has(p) ? 'check' : 'close'"
                    size="16px"
                    aria-hidden="true"
                  />
                  <span :class="effective.has(p) ? '' : 'pal-muted'">{{ p }} <span class="pal-visually-hidden">{{ effective.has(p) ? 'granted' : 'not granted' }}</span></span>
                </li>
              </ul>
            </details>
          </div>
        </div>
      </section>
    </div>

    <q-dialog
      v-model="creating"
      aria-labelledby="create-heading"
    >
      <q-card class="pal-dialog-card">
        <form
          novalidate
          @submit.prevent="createUser"
        >
          <q-card-section>
            <h2
              id="create-heading"
              class="text-h6 q-my-none"
            >
              New local user
            </h2>
            <p class="pal-muted q-mb-none">
              They sign in with a username and a temporary password you give them, then choose their own.
            </p>
          </q-card-section>
          <q-card-section class="q-gutter-y-md">
            <q-banner
              v-if="createErrors.form"
              class="bg-negative text-white"
              rounded
              role="alert"
            >
              {{ createErrors.form }}
            </q-banner>
            <q-input
              v-model="newUser.username"
              outlined
              label="Username"
              autocapitalize="none"
              hint="Lowercase letters, digits, dots, hyphens or underscores."
              :error="createErrors.username !== undefined"
              :error-message="createErrors.username"
            />
            <q-input
              v-model="newUser.displayName"
              outlined
              label="Full name"
              :error="createErrors.displayName !== undefined"
              :error-message="createErrors.displayName"
            />
            <q-input
              v-model="newUser.email"
              outlined
              type="email"
              label="Email (optional)"
              :error="createErrors.email !== undefined"
              :error-message="createErrors.email"
            />
            <fieldset class="pal-plain-fieldset q-pa-none q-ma-none">
              <legend class="text-weight-medium">
                Roles (everyone is a Reader)
              </legend>
              <q-checkbox
                v-for="role in roles"
                :key="role.value"
                v-model="newUser.roles"
                :val="role.value"
                :label="roleLabel(role.value)"
              />
            </fieldset>
          </q-card-section>
          <q-card-actions align="right">
            <q-btn
              v-close-popup
              flat
              no-caps
              label="Cancel"
            />
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              label="Create user"
              :loading="saving"
            />
          </q-card-actions>
        </form>
      </q-card>
    </q-dialog>
  </div>
</template>
