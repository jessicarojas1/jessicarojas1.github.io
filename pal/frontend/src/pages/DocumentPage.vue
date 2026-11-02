<script setup lang="ts">
import { useLeaveGuard } from '@/composables/useLeaveGuard'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import { api, ApiError } from '@/api/client'
import { formatBytes, GRANT_ROLE_LABELS, type DocumentDetail, type Grant, type Person, type RevisionView } from '@/api/documents'
import FileUploadField from '@/components/FileUploadField.vue'
import PeoplePicker from '@/components/PeoplePicker.vue'
import RevisionStateChip from '@/components/RevisionStateChip.vue'
import { formatDateTime } from '@/format'
import { useSessionStore } from '@/stores/session'

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const session = useSessionStore()

const doc = ref<DocumentDetail | null>(null)
const notFound = ref(false)
const loadError = ref<string | null>(null)
const busy = ref(false)
const sectionError = ref<Record<string, string | null>>({})
const fieldErrors = ref<Record<string, string>>({})

// Draft editing
const editTitle = ref('')
const editDescription = ref('')
const editKeywords = ref<string[]>([])
const editSummary = ref('')
// Submission
const approvers = ref<Person[]>([])
const approversError = ref<string | null>(null)
const approverId = ref<string | null>(null)
const submitComment = ref('')
// Decision
const decision = ref<'APPROVE' | 'REQUEST_CHANGES' | 'REJECT'>('APPROVE')
const decisionComment = ref('')
// Access
const grantRole = ref<Grant['role']>('READER')
const grantKind = ref<'user' | 'group'>('user')
const grantUserId = ref<string | null>(null)
const grantGroupId = ref('')

const id = computed(() => String(route.params.id))
const open = computed(() => doc.value?.openRevision ?? null)
const heading = computed(() => doc.value?.current?.title ?? open.value?.title ?? doc.value?.awaitingRelease?.title ?? '')
const tz = computed(() => session.effectiveTimezone)

function setError(section: string, e: unknown, fallback: string): void {
  if (e instanceof ApiError) {
    fieldErrors.value = e.fields
    sectionError.value = { ...sectionError.value, [section]: Object.keys(e.fields).length > 0 ? 'Fix the highlighted fields.' : e.message }
  } else {
    sectionError.value = { ...sectionError.value, [section]: fallback }
  }
}

async function load(): Promise<void> {
  loadError.value = null
  try {
    doc.value = await api<DocumentDetail>(`/api/documents/${encodeURIComponent(id.value)}`)
    notFound.value = false
    const draft = doc.value.openRevision
    // Re-seed the edit fields only for a different draft or when nothing is unsaved, so a
    // reload after another action (attaching a file, granting access) never discards edits.
    if (draft !== null && (seeded.value === null || seeded.value.id !== draft.id || !draftDirty.value)) {
      editTitle.value = draft.title
      editDescription.value = draft.description ?? ''
      editKeywords.value = [...draft.keywords]
      editSummary.value = draft.changeSummary ?? ''
      seeded.value = snapshot(draft.id)
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true
    else loadError.value = e instanceof ApiError ? e.message : 'The document could not be loaded.'
    return
  }
  await loadApprovers()
}

// A failed approver lookup is shown on the submit section, not as a page-level failure.
async function loadApprovers(): Promise<void> {
  approversError.value = null
  const draft = doc.value?.openRevision ?? null
  if (draft === null || !draft.actions.includes('submit')) return
  try {
    approvers.value = (await api<{ approvers: Person[] }>(`/api/documents/${encodeURIComponent(id.value)}/approvers`)).approvers
  } catch (e) {
    approversError.value = e instanceof ApiError ? e.message : 'Approvers could not be loaded.'
  }
}

interface DraftSnapshot { id: string; title: string; description: string; keywords: string; summary: string }
const seeded = ref<DraftSnapshot | null>(null)

function snapshot(revisionId: string): DraftSnapshot {
  return { id: revisionId, title: editTitle.value, description: editDescription.value, keywords: JSON.stringify(editKeywords.value), summary: editSummary.value }
}

const draftDirty = computed(() => {
  const base = seeded.value
  // Only an editable draft can hold unsaved work (it may have been withdrawn or submitted).
  if (base === null || open.value?.id !== base.id || !open.value.actions.includes('edit')) return false
  const now = snapshot(base.id)
  return now.title !== base.title || now.description !== base.description || now.keywords !== base.keywords || now.summary !== base.summary
})

useLeaveGuard(draftDirty)

function goBack(): void {
  // A deep-linked visitor has no in-app history; going "back" would leave PAL.
  if (window.history.state?.back) router.back()
  else void router.push('/library')
}

async function run(section: string, action: () => Promise<unknown>, success: string): Promise<void> {
  if (busy.value) return
  busy.value = true
  sectionError.value = { ...sectionError.value, [section]: null }
  fieldErrors.value = {}
  try {
    await action()
    $q.notify({ type: 'positive', message: success })
    await load()
  } catch (e) {
    setError(section, e, 'That did not work. Try again.')
  } finally {
    busy.value = false
  }
}

function saveDraft(rev: RevisionView): Promise<void> {
  return run('draft', async () => {
    await api(`/api/revisions/${rev.id}`, { method: 'PATCH', body: { title: editTitle.value, description: editDescription.value, keywords: editKeywords.value, changeSummary: editSummary.value, rowVersion: rev.rowVersion } })
    // Saved: the reload below takes the server's values as the new baseline.
    seeded.value = null
  }, 'Draft saved.')
}

function attach(rev: RevisionView, uploadId: string): Promise<void> {
  return run('draft', () => api(`/api/revisions/${rev.id}/file`, { method: 'PUT', body: { uploadId } }), 'File attached to the draft.')
}

function submit(rev: RevisionView): Promise<void> {
  if (draftDirty.value) {
    sectionError.value = { ...sectionError.value, draft: 'Save your changes to the draft before submitting it.' }
    return Promise.resolve()
  }
  return run('draft', () => api(`/api/revisions/${rev.id}/submit`, { method: 'POST', body: { approverId: approverId.value, comment: submitComment.value } }), 'Submitted for approval.')
}

function reopen(rev: RevisionView): Promise<void> {
  return run('draft', () => api(`/api/revisions/${rev.id}/reopen`, { method: 'POST' }), 'The revision is editable again.')
}

function withdraw(rev: RevisionView): void {
  $q.dialog({
    title: 'Withdraw this revision?',
    message: 'It will be closed and kept in the record. The published version (if any) is not affected.',
    prompt: { model: '', type: 'text', label: 'Reason (required)', isValid: (v: string) => v.trim().length > 0 },
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Withdraw' },
    focus: 'cancel',
    persistent: true,
  }).onOk((reason: string) => {
    void run('draft', () => api(`/api/revisions/${rev.id}/withdraw`, { method: 'POST', body: { reason } }), 'Revision withdrawn.')
  })
}

function decide(rev: RevisionView): Promise<void> {
  const taskId = rev.workflow?.taskId ?? ''
  return run('decision', () => api(`/api/tasks/${taskId}/decision`, { method: 'POST', body: { decision: decision.value, comment: decisionComment.value } }), 'Decision recorded.')
}

function release(rev: RevisionView): void {
  $q.dialog({
    title: `Release revision ${rev.label}?`,
    message: 'It becomes the current version for everyone with access, immediately. The previous version is superseded and kept in the history.',
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'primary', unelevated: true, noCaps: true, label: 'Release now' },
    focus: 'cancel',
    persistent: true,
  }).onOk(() => {
    void run('release', () => api(`/api/revisions/${rev.id}/release`, { method: 'POST' }), `Revision ${rev.label} released.`)
  })
}

function newRevision(): Promise<void> {
  return run('current', () => api(`/api/documents/${id.value}/revisions`, { method: 'POST' }), 'New draft started from the published version.')
}

function addGrant(): Promise<void> {
  const body = grantKind.value === 'user' ? { role: grantRole.value, userId: grantUserId.value } : { role: grantRole.value, groupObjectId: grantGroupId.value.trim() }
  return run('access', async () => {
    await api(`/api/documents/${id.value}/grants`, { method: 'POST', body })
    grantUserId.value = null
    grantGroupId.value = ''
  }, 'Access granted.')
}

function revokeGrant(grant: Grant): void {
  $q.dialog({
    title: `Remove access for ${grant.principal.displayName}?`,
    message: `They lose the ${GRANT_ROLE_LABELS[grant.role]} access on this document. The change is recorded and can be granted again.`,
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Remove access' },
    focus: 'cancel',
    persistent: true,
  }).onOk(() => {
    void run('access', () => api(`/api/documents/${id.value}/grants/${grant.id}`, { method: 'DELETE' }), 'Access removed.')
  })
}

watch(id, () => void load(), { immediate: true })
</script>

<template>
  <div class="pal-page">
    <template v-if="notFound">
      <header class="pal-page-header">
        <h1>Document not found</h1>
        <p>It does not exist, or you do not have access to it.</p>
      </header>
      <q-btn
        color="primary"
        unelevated
        no-caps
        label="Back to the library"
        to="/library"
      />
    </template>

    <q-banner
      v-else-if="loadError"
      class="bg-negative text-white"
      rounded
      role="alert"
    >
      {{ loadError }}
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
      v-else-if="doc === null"
      class="row justify-center q-pa-lg"
      role="status"
    >
      <q-spinner size="32px" /><span class="pal-visually-hidden">Loading document…</span>
    </div>

    <template v-else>
      <header class="pal-page-header">
        <p class="pal-doc-number q-mb-xs">
          {{ doc.documentNumber }}
        </p>
        <h1>{{ heading }}</h1>
        <p>
          {{ doc.type.name }} · {{ doc.department.name }} · Owner: {{ doc.owner.displayName }}
          <span v-if="doc.audience === 'RESTRICTED'"> · Restricted</span>
        </p>
      </header>

      <!-- Current published version -->
      <section
        class="pal-section pal-card"
        aria-labelledby="current-heading"
      >
        <h2
          id="current-heading"
          class="text-h6 q-mt-none"
        >
          Current version
        </h2>
        <p
          v-if="doc.current === null"
          class="pal-muted q-mb-none"
        >
          Not published yet.
        </p>
        <template v-else>
          <p class="q-mb-sm">
            <strong>Revision {{ doc.current.label }}</strong>, released {{ formatDateTime(doc.current.releasedAt, tz) }}, effective from {{ formatDateTime(doc.current.effectiveFrom, tz) }}.
          </p>
          <p v-if="doc.current.description">
            {{ doc.current.description }}
          </p>
          <p
            v-if="doc.current.changeSummary"
            class="pal-muted"
          >
            What changed: {{ doc.current.changeSummary }}
          </p>
          <div
            v-if="doc.current.keywords.length > 0"
            class="q-mb-sm"
          >
            <q-chip
              v-for="k in doc.current.keywords"
              :key="k"
              dense
            >
              {{ k }}
            </q-chip>
          </div>
          <q-btn
            v-if="doc.current.file"
            color="primary"
            unelevated
            no-caps
            icon="download"
            :href="`/api/revisions/${doc.current.id}/file`"
            :label="`Download ${doc.current.file.filename} (${formatBytes(doc.current.file.sizeBytes)})`"
          />
        </template>
        <q-banner
          v-if="sectionError.current"
          class="bg-negative text-white q-mt-md"
          rounded
          role="alert"
        >
          {{ sectionError.current }}
        </q-banner>
        <div
          v-if="doc.actions.includes('createRevision')"
          class="q-mt-md"
        >
          <q-btn
            outline
            no-caps
            icon="edit_document"
            label="Start a new revision"
            :loading="busy"
            @click="newRevision"
          />
        </div>
      </section>

      <!-- Approval task for the assigned approver -->
      <section
        v-if="open && open.actions.includes('decide')"
        class="pal-section pal-card"
        aria-labelledby="decision-heading"
      >
        <h2
          id="decision-heading"
          class="text-h6 q-mt-none"
        >
          Your approval is needed: revision {{ open.label }}
        </h2>
        <p>You are approving exactly this content. If anything changes after submission, the approval is void and it must be resubmitted.</p>
        <dl class="q-mb-md">
          <dt class="text-weight-medium">
            Title
          </dt>
          <dd class="q-ml-none q-mb-sm">
            {{ open.title }}
          </dd>
          <dt
            v-if="open.changeSummary"
            class="text-weight-medium"
          >
            What changed
          </dt>
          <dd
            v-if="open.changeSummary"
            class="q-ml-none q-mb-sm"
          >
            {{ open.changeSummary }}
          </dd>
          <dt class="text-weight-medium">
            Content fingerprint
          </dt>
          <dd class="q-ml-none q-mb-sm pal-doc-number">
            {{ open.manifestHash?.slice(0, 16) }}…
          </dd>
        </dl>
        <q-btn
          v-if="open.file"
          outline
          no-caps
          icon="download"
          class="q-mb-md"
          :href="`/api/revisions/${open.id}/file`"
          :label="`Download ${open.file.filename} to review`"
        />
        <form
          novalidate
          @submit.prevent="decide(open)"
        >
          <q-banner
            v-if="sectionError.decision"
            class="bg-negative text-white q-mb-md"
            rounded
            role="alert"
          >
            {{ sectionError.decision }}
          </q-banner>
          <fieldset class="q-pa-none q-ma-none q-mb-md pal-plain-fieldset">
            <legend class="text-weight-medium q-mb-xs">
              Decision
            </legend>
            <q-option-group
              v-model="decision"
              :options="[{ label: 'Approve', value: 'APPROVE' }, { label: 'Request changes', value: 'REQUEST_CHANGES' }, { label: 'Reject', value: 'REJECT' }]"
            />
          </fieldset>
          <q-input
            v-model="decisionComment"
            outlined
            type="textarea"
            autogrow
            :label="decision === 'APPROVE' ? 'Comment (optional)' : 'What needs to change (required)'"
            :error="fieldErrors.comment !== undefined"
            :error-message="fieldErrors.comment"
          />
          <div class="row justify-end q-mt-sm">
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              label="Record decision"
              :loading="busy"
            />
          </div>
        </form>
      </section>

      <!-- Work in progress for owners/authors -->
      <section
        v-if="open && !open.actions.includes('decide')"
        class="pal-section pal-card"
        aria-labelledby="draft-heading"
      >
        <div class="row items-center q-gutter-sm">
          <h2
            id="draft-heading"
            class="text-h6 q-my-none"
          >
            Revision {{ open.label }} in progress
          </h2>
          <RevisionStateChip :state="open.state" />
        </div>
        <q-banner
          v-if="sectionError.draft"
          class="bg-negative text-white q-mt-md"
          rounded
          role="alert"
        >
          {{ sectionError.draft }}
        </q-banner>
        <div
          v-if="open.workflow && open.workflow.decision"
          class="q-mt-md"
        >
          <p class="q-mb-xs">
            <strong>{{ open.workflow.approver }}</strong> {{ open.workflow.decision === 'APPROVE' ? 'approved' : open.workflow.decision === 'REJECT' ? 'rejected' : 'requested changes' }}<span v-if="open.workflow.decidedAt">, {{ formatDateTime(open.workflow.decidedAt, tz) }}</span>.
          </p>
          <blockquote
            v-if="open.workflow.comment"
            class="q-ma-none q-pl-md pal-muted"
          >
            {{ open.workflow.comment }}
          </blockquote>
        </div>
        <p
          v-else-if="open.state === 'IN_WORKFLOW' && open.workflow"
          class="q-mt-md"
        >
          Waiting for {{ open.workflow.approver }} to decide. The content is locked until then.
        </p>

        <form
          v-if="open.actions.includes('edit')"
          class="q-gutter-y-md q-mt-md"
          novalidate
          @submit.prevent="saveDraft(open)"
        >
          <q-input
            v-model="editTitle"
            outlined
            label="Title"
            maxlength="300"
            :error="fieldErrors.title !== undefined"
            :error-message="fieldErrors.title"
          />
          <q-input
            v-model="editDescription"
            outlined
            type="textarea"
            autogrow
            label="Description"
            maxlength="4000"
          />
          <q-select
            v-model="editKeywords"
            outlined
            label="Keywords"
            multiple
            use-chips
            use-input
            hide-dropdown-icon
            new-value-mode="add-unique"
            input-debounce="0"
          />
          <q-input
            v-model="editSummary"
            outlined
            type="textarea"
            autogrow
            label="What changed in this revision"
            maxlength="2000"
          />
          <div class="row justify-end">
            <q-btn
              type="submit"
              outline
              no-caps
              label="Save draft"
              :loading="busy"
            />
          </div>
        </form>

        <div
          v-if="open.actions.includes('attachFile')"
          class="q-mt-md"
        >
          <p class="q-mb-xs">
            <strong>File:</strong> {{ open.file ? `${open.file.filename} (${formatBytes(open.file.sizeBytes)})` : 'none attached yet' }}
            <a
              v-if="open.file"
              :href="`/api/revisions/${open.id}/file`"
              class="q-ml-sm pal-text-primary"
            >Download</a>
          </p>
          <FileUploadField
            id="draft-file"
            :label="open.file ? 'Replace the file' : 'Attach the file'"
            :disabled="busy"
            @uploaded="attach(open, $event.id)"
          />
        </div>

        <form
          v-if="open.actions.includes('submit')"
          class="q-mt-lg q-gutter-y-md"
          novalidate
          @submit.prevent="submit(open)"
        >
          <h3 class="text-subtitle1 text-weight-medium q-my-none">
            Submit for approval
          </h3>
          <q-select
            v-model="approverId"
            outlined
            :options="approvers.map((a) => ({ value: a.id, label: a.displayName }))"
            emit-value
            map-options
            label="Approver"
            :hint="approvers.length === 0 ? 'No approvers yet: the owner adds them under Access below.' : 'Only people with approver access to this document are listed.'"
            :error="fieldErrors.approverId !== undefined"
            :error-message="fieldErrors.approverId"
          />
          <q-input
            v-model="submitComment"
            outlined
            label="Note to the approver (optional)"
          />
          <p
            v-if="fieldErrors.file"
            class="pal-text-danger"
            role="alert"
          >
            {{ fieldErrors.file }}
          </p>
          <div class="row justify-end">
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              label="Submit for approval"
              :loading="busy"
              :disable="approverId === null"
            />
          </div>
        </form>

        <div class="row q-gutter-sm q-mt-md">
          <q-btn
            v-if="open.actions.includes('reopen')"
            color="primary"
            unelevated
            no-caps
            label="Revise and resubmit"
            :loading="busy"
            @click="reopen(open)"
          />
          <q-btn
            v-if="open.actions.includes('withdraw')"
            flat
            no-caps
            class="pal-text-danger"
            label="Withdraw revision"
            @click="withdraw(open)"
          />
        </div>
      </section>

      <!-- Approved, waiting for a publisher -->
      <section
        v-if="doc.awaitingRelease"
        class="pal-section pal-card"
        aria-labelledby="release-heading"
      >
        <h2
          id="release-heading"
          class="text-h6 q-mt-none"
        >
          Revision {{ doc.awaitingRelease.label }} approved, awaiting release
        </h2>
        <p v-if="doc.awaitingRelease.workflow">
          Approved by {{ doc.awaitingRelease.workflow.approver }}<span v-if="doc.awaitingRelease.workflow.decidedAt">, {{ formatDateTime(doc.awaitingRelease.workflow.decidedAt, tz) }}</span>.
        </p>
        <q-banner
          v-if="sectionError.release"
          class="bg-negative text-white q-mb-md"
          rounded
          role="alert"
        >
          {{ sectionError.release }}
        </q-banner>
        <q-btn
          v-if="doc.awaitingRelease.actions.includes('release')"
          color="primary"
          unelevated
          no-caps
          icon="publish"
          label="Release now"
          :loading="busy"
          @click="release(doc.awaitingRelease)"
        />
        <p
          v-else
          class="pal-muted q-mb-none"
        >
          A publisher will release it.
        </p>
      </section>

      <!-- History -->
      <section
        v-if="doc.history.length > 0"
        class="pal-section"
        aria-labelledby="history-heading"
      >
        <h2
          id="history-heading"
          class="text-h6"
        >
          Revision history
        </h2>
        <q-markup-table
          flat
          bordered
          class="pal-card q-pa-none"
        >
          <thead>
            <tr>
              <th
                class="text-left"
                scope="col"
              >
                Revision
              </th>
              <th
                class="text-left"
                scope="col"
              >
                Effective
              </th>
              <th
                class="text-left"
                scope="col"
              >
                What changed
              </th>
              <th
                class="text-left"
                scope="col"
              >
                Released by
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="h in doc.history"
              :key="h.revisionId"
            >
              <td>{{ h.label }}<span v-if="h.isCurrent"> (current)</span></td>
              <td>{{ formatDateTime(h.effectiveFrom, tz) }} – {{ h.effectiveTo ? formatDateTime(h.effectiveTo, tz) : 'now' }}</td>
              <td>{{ h.changeSummary ?? '—' }}</td>
              <td>{{ h.releasedBy ?? '—' }}</td>
            </tr>
          </tbody>
        </q-markup-table>
      </section>

      <!-- Access -->
      <section
        v-if="doc.grants !== null"
        class="pal-section pal-card"
        aria-labelledby="access-heading"
      >
        <h2
          id="access-heading"
          class="text-h6 q-mt-none"
        >
          Access
        </h2>
        <p class="pal-muted">
          {{ doc.audience === 'RESTRICTED' ? 'Restricted: only the people and groups below can read the published version.' : 'Every employee can read the published version. Grants below add authors and approvers.' }}
        </p>
        <q-banner
          v-if="sectionError.access"
          class="bg-negative text-white q-mb-md"
          rounded
          role="alert"
        >
          {{ sectionError.access }}
        </q-banner>
        <ul
          v-if="doc.grants.length > 0"
          class="pal-plain-list q-mb-md"
        >
          <li
            v-for="g in doc.grants"
            :key="g.id"
            class="row items-center justify-between q-py-xs"
          >
            <span>{{ g.principal.displayName }} <span class="pal-muted">({{ g.principal.type === 'group' ? 'group' : 'person' }}) · {{ GRANT_ROLE_LABELS[g.role] }}</span></span>
            <q-btn
              flat
              dense
              no-caps
              class="pal-text-danger"
              label="Remove"
              :aria-label="`Remove ${GRANT_ROLE_LABELS[g.role]} access for ${g.principal.displayName}`"
              @click="revokeGrant(g)"
            />
          </li>
        </ul>
        <p
          v-else
          class="empty-state-sm"
        >
          No one has been given access yet.
        </p>
        <form
          class="row q-col-gutter-sm items-start"
          novalidate
          @submit.prevent="addGrant"
        >
          <q-select
            v-model="grantRole"
            class="col-12 col-sm-3"
            outlined
            :options="[{ value: 'READER', label: 'Reader' }, { value: 'AUTHOR', label: 'Author' }, { value: 'APPROVER', label: 'Approver' }]"
            emit-value
            map-options
            label="Role"
          />
          <q-select
            v-model="grantKind"
            class="col-12 col-sm-3"
            outlined
            :options="[{ value: 'user', label: 'Person' }, { value: 'group', label: 'Entra group' }]"
            emit-value
            map-options
            label="Grant to"
          />
          <div class="col-12 col-sm-4">
            <PeoplePicker
              v-if="grantKind === 'user'"
              v-model="grantUserId"
              label="Person"
              :error="fieldErrors.principal"
            />
            <q-input
              v-else
              v-model="grantGroupId"
              outlined
              label="Group object ID"
              :error="fieldErrors.principal !== undefined"
              :error-message="fieldErrors.principal"
            />
          </div>
          <div class="col-12 col-sm-2">
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              class="full-width pal-field-height"
              label="Add"
              :loading="busy"
            />
          </div>
        </form>
      </section>

      <div class="q-mt-lg">
        <q-btn
          flat
          no-caps
          icon="arrow_back"
          label="Back"
          @click="goBack"
        />
      </div>
    </template>
  </div>
</template>
