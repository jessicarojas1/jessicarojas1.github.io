<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { STATE_LABELS, type RevisionView } from '@/api/documents'
import { formatDateTime } from '@/format'
import { useSessionStore } from '@/stores/session'

interface WorkspaceDoc {
  id: string
  documentNumber: string
  type: string
  title: string
  openRevision: { id: string; state: RevisionView['state']; proposedLabel: string } | null
  published: string | null
  awaitingRelease: string | null
}
interface Task { taskId: string; documentId: string; documentNumber: string; title: string; proposedLabel: string; submittedBy: string; submittedAt: string }
interface Release { documentId: string; documentNumber: string; title: string; label: string; approvedAt: string }

const session = useSessionStore()
const documents = ref<WorkspaceDoc[]>([])
const tasks = ref<Task[]>([])
const releases = ref<Release[] | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const data = await api<{ documents: WorkspaceDoc[]; tasks: Task[]; awaitingRelease: Release[] | null }>('/api/workspace')
    documents.value = data.documents
    tasks.value = data.tasks
    releases.value = data.awaitingRelease
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Your workspace could not be loaded.'
  } finally {
    loading.value = false
  }
}

function status(doc: WorkspaceDoc): string {
  const parts: string[] = []
  if (doc.published !== null) parts.push(`Published ${doc.published}`)
  if (doc.awaitingRelease !== null) parts.push(`${doc.awaitingRelease} approved, awaiting release`)
  if (doc.openRevision !== null) parts.push(`${doc.openRevision.proposedLabel}: ${STATE_LABELS[doc.openRevision.state]}`)
  return parts.join(' · ')
}

onMounted(load)
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>My workspace</h1>
      <p>Approvals waiting for you, releases waiting for a publisher, and the documents you own or author.</p>
    </header>
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
          color="white"
          label="Try again"
          @click="load"
        />
      </template>
    </q-banner>
    <div
      v-if="loading"
      role="status"
      class="row justify-center q-pa-lg"
    >
      <q-spinner size="32px" /><span class="pal-visually-hidden">Loading…</span>
    </div>

    <template v-else-if="!error">
      <section
        class="pal-section"
        aria-labelledby="tasks-heading"
      >
        <h2
          id="tasks-heading"
          class="text-h6"
        >
          Approvals assigned to me ({{ tasks.length }})
        </h2>
        <div
          v-if="tasks.length === 0"
          class="pal-card empty-state-sm"
        >
          Nothing is waiting for your approval.
        </div>
        <ul
          v-else
          class="pal-card pal-plain-list"
        >
          <li
            v-for="task in tasks"
            :key="task.taskId"
          >
            <router-link
              :to="`/documents/${task.documentId}`"
              class="pal-list-item"
            >
              <span class="pal-doc-number">{{ task.documentNumber }}</span> {{ task.title }} (revision {{ task.proposedLabel }})
              <div class="pal-muted text-body2">
                Submitted by {{ task.submittedBy }}, {{ formatDateTime(task.submittedAt, session.effectiveTimezone) }}
              </div>
            </router-link>
          </li>
        </ul>
      </section>

      <section
        v-if="releases !== null"
        class="pal-section"
        aria-labelledby="releases-heading"
      >
        <h2
          id="releases-heading"
          class="text-h6"
        >
          Approved, waiting to be released ({{ releases.length }})
        </h2>
        <div
          v-if="releases.length === 0"
          class="pal-card empty-state-sm"
        >
          No approved revisions are waiting.
        </div>
        <ul
          v-else
          class="pal-card pal-plain-list"
        >
          <li
            v-for="release in releases"
            :key="release.documentId + release.label"
          >
            <router-link
              :to="`/documents/${release.documentId}`"
              class="pal-list-item"
            >
              <span class="pal-doc-number">{{ release.documentNumber }}</span> {{ release.title }} (revision {{ release.label }})
              <div class="pal-muted text-body2">
                Approved {{ formatDateTime(release.approvedAt, session.effectiveTimezone) }}
              </div>
            </router-link>
          </li>
        </ul>
      </section>

      <section
        class="pal-section"
        aria-labelledby="docs-heading"
      >
        <div class="row items-center justify-between">
          <h2
            id="docs-heading"
            class="text-h6"
          >
            My documents ({{ documents.length }})
          </h2>
          <q-btn
            v-if="session.can('document.create')"
            color="primary"
            unelevated
            no-caps
            icon="add"
            label="New document"
            to="/documents/new"
          />
        </div>
        <div
          v-if="documents.length === 0"
          class="pal-card empty-state-sm"
        >
          You don't own or author any documents yet.
        </div>
        <ul
          v-else
          class="pal-card pal-plain-list"
        >
          <li
            v-for="doc in documents"
            :key="doc.id"
          >
            <router-link
              :to="`/documents/${doc.id}`"
              class="pal-list-item"
            >
              <span class="pal-doc-number">{{ doc.documentNumber }}</span> {{ doc.title }}
              <div class="pal-muted text-body2">
                {{ doc.type }} · {{ status(doc) }}
              </div>
            </router-link>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
