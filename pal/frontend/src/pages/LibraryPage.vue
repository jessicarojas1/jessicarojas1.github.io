<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { LibraryItem, ReferenceItem } from '@/api/documents'
import { formatDateTime } from '@/format'
import { useSessionStore } from '@/stores/session'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const typeId = ref<string | null>(typeof route.query.type === 'string' ? route.query.type : null)
const departmentId = ref<string | null>(typeof route.query.department === 'string' ? route.query.department : null)
const page = ref(1)
const filtersOpen = ref(typeId.value !== null || departmentId.value !== null)
const items = ref<LibraryItem[]>([])
const total = ref(0)
const pageSize = ref(25)
const loading = ref(false)
const error = ref<string | null>(null)
const types = ref<ReferenceItem[]>([])
const departments = ref<ReferenceItem[]>([])

const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const activeFilters = computed(() => (typeId.value !== null ? 1 : 0) + (departmentId.value !== null ? 1 : 0))

// Only the newest request may update the page; a slow older response is ignored.
let latestRequest = 0

async function load(): Promise<void> {
  const request = ++latestRequest
  loading.value = true
  error.value = null
  const params = new URLSearchParams()
  if (query.value.trim() !== '') params.set('q', query.value.trim())
  if (typeId.value !== null) params.set('type', typeId.value)
  if (departmentId.value !== null) params.set('department', departmentId.value)
  params.set('page', String(page.value))
  try {
    const data = await api<{ items: LibraryItem[]; total: number; pageSize: number }>(`/api/library?${params.toString()}`)
    if (request !== latestRequest) return
    items.value = data.items
    total.value = data.total
    pageSize.value = data.pageSize
  } catch (e) {
    if (request === latestRequest) error.value = e instanceof ApiError ? e.message : 'The library could not be loaded.'
  } finally {
    if (request === latestRequest) loading.value = false
  }
}

function search(): void {
  const pageChanges = page.value !== 1
  page.value = 1
  void router.replace({ query: { ...(query.value.trim() !== '' ? { q: query.value.trim() } : {}), ...(typeId.value !== null ? { type: typeId.value } : {}), ...(departmentId.value !== null ? { department: departmentId.value } : {}) } })
  // Changing the page already triggers a load through the watcher.
  if (!pageChanges) void load()
}

function clearFilters(): void {
  typeId.value = null
  departmentId.value = null
  search()
}

watch(page, () => void load())

onMounted(async () => {
  try {
    const ref = await api<{ documentTypes: ReferenceItem[]; departments: ReferenceItem[] }>('/api/reference')
    types.value = ref.documentTypes
    departments.value = ref.departments
  } catch {
    // Filters stay empty; search still works.
  }
  await load()
})
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header row items-start justify-between q-gutter-sm">
      <div>
        <h1>Document library</h1>
        <p>Current, approved versions of every document you can access.</p>
      </div>
      <q-btn
        v-if="session.can('document.create')"
        color="primary"
        unelevated
        no-caps
        icon="add"
        label="New document"
        to="/documents/new"
      />
    </header>

    <form
      class="pal-card"
      role="search"
      @submit.prevent="search"
    >
      <div class="row q-col-gutter-sm items-start">
        <q-input
          v-model="query"
          class="col"
          outlined
          label="Search by title, keyword or document number"
          clearable
          @clear="search"
        >
          <template #prepend>
            <q-icon
              name="search"
              aria-hidden="true"
            />
          </template>
        </q-input>
        <div class="col-auto row q-gutter-sm">
          <q-btn
            type="submit"
            color="primary"
            unelevated
            no-caps
            class="pal-field-height"
            label="Search"
          />
          <q-btn
            outline
            no-caps
            class="pal-field-height filter-btn"
            icon="filter_alt"
            :label="activeFilters > 0 ? `Filters (${activeFilters})` : 'Filters'"
            :aria-expanded="filtersOpen ? 'true' : 'false'"
            aria-controls="library-filters"
            @click="filtersOpen = !filtersOpen"
          />
        </div>
      </div>
      <div
        v-show="filtersOpen"
        id="library-filters"
        class="pal-filter-panel row q-col-gutter-sm"
      >
        <q-select
          v-model="typeId"
          class="col-12 col-sm-5"
          :options="types.map((t) => ({ value: t.id, label: t.name }))"
          emit-value
          map-options
          clearable
          outlined
          label="Document type"
          @update:model-value="search"
        />
        <q-select
          v-model="departmentId"
          class="col-12 col-sm-5"
          :options="departments.map((d) => ({ value: d.id, label: d.name }))"
          emit-value
          map-options
          clearable
          outlined
          label="Department"
          @update:model-value="search"
        />
        <div class="col-12 col-sm-2">
          <q-btn
            flat
            no-caps
            label="Clear filters"
            class="pal-field-height"
            :disable="activeFilters === 0"
            @click="clearFilters"
          />
        </div>
      </div>
    </form>

    <section
      class="pal-section"
      aria-labelledby="results-heading"
    >
      <h2
        id="results-heading"
        class="text-subtitle1 text-weight-medium"
      >
        <span aria-live="polite">{{ loading ? 'Searching…' : `${total} document${total === 1 ? '' : 's'}` }}</span>
      </h2>
      <q-banner
        v-if="error"
        class="bg-negative text-white"
        rounded
        role="alert"
      >
        {{ error }}
        <template #action>
          <q-btn
            flat
            no-caps
            color="white"
            label="Try again"
            @click="load"
          />
        </template>
      </q-banner>
      <div
        v-else-if="!loading && items.length === 0"
        class="pal-card empty-state-sm"
      >
        <p class="q-mb-none">
          No published documents match. Try fewer words, or clear the filters.
        </p>
      </div>
      <ul
        v-else
        class="pal-card pal-plain-list"
      >
        <li
          v-for="item in items"
          :key="item.id"
        >
          <router-link
            :to="`/documents/${item.id}`"
            class="pal-list-item"
          >
            <div class="row items-baseline q-gutter-x-sm">
              <span class="pal-doc-number">{{ item.documentNumber }}</span>
              <span class="text-subtitle1 text-weight-medium">{{ item.title }}</span>
            </div>
            <div class="pal-muted text-body2">
              {{ item.type.name }} · {{ item.department.name }} · Revision {{ item.revision }} · Released {{ formatDateTime(item.releasedAt, session.effectiveTimezone) }}
              <span v-if="item.restricted"> · Restricted</span>
            </div>
            <div
              v-if="item.description"
              class="text-body2 q-mt-xs"
            >
              {{ item.description }}
            </div>
          </router-link>
        </li>
      </ul>
      <div
        v-if="pages > 1"
        class="row justify-center q-mt-md"
      >
        <q-pagination
          v-model="page"
          :max="pages"
          direction-links
          :max-pages="7"
        />
      </div>
    </section>
  </div>
</template>
