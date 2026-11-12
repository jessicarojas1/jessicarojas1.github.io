<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import type { RevisionComparison } from '@/api/documents'
import RichTextView from '@/components/RichTextView.vue'
import type { RichTextNode } from '@/richText'

/**
 * "What changed" between a revision and the one it was based on. Loaded on demand. Changes
 * are marked with words and <ins>/<del> as well as colour, so they never rely on colour alone.
 */
const props = defineProps<{
  revisionId: string
  revisionLabel: string
  previousLabel: string
  /** Changes whenever the revision is saved (e.g. its row version), so a stale diff is dropped. */
  version?: number | null
}>()

const open = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const result = ref<RevisionComparison | null>(null)
const showUnchanged = ref(false)
const panelId = useId()
// Per-block "show before and after" toggles for edited blocks.
const expanded = ref<Set<number>>(new Set())
let request = 0

const FIELD_LABELS: Record<string, string> = { title: 'Title', description: 'Description', keywords: 'Keywords', changeSummary: 'What changed' }

watch(() => props.revisionId, () => {
  open.value = false
  reset()
})
// The draft was saved: what was shown may be out of date, so fetch again if open.
watch(() => props.version, () => {
  reset()
  if (open.value) void fetchComparison()
})

function reset(): void {
  request++
  result.value = null
  error.value = null
  loading.value = false
  expanded.value = new Set()
}

async function fetchComparison(): Promise<void> {
  const mine = ++request
  loading.value = true
  error.value = null
  try {
    const data = await api<RevisionComparison>(`/api/revisions/${encodeURIComponent(props.revisionId)}/compare`)
    if (mine === request) result.value = data
  } catch (e) {
    if (mine === request) error.value = e instanceof ApiError ? e.message : 'The comparison could not be loaded.'
  } finally {
    if (mine === request) loading.value = false
  }
}

function toggle(): void {
  open.value = !open.value
  // Always fresh when opened: the draft may have changed since the last look.
  if (open.value && !loading.value) {
    result.value = null
    void fetchComparison()
  }
}

function toggleBlock(i: number): void {
  const next = new Set(expanded.value)
  if (next.has(i)) next.delete(i)
  else next.add(i)
  expanded.value = next
}

const isTextBlock = (node: RichTextNode): boolean => node.type === 'paragraph' || node.type === 'heading'
const wordingSame = (words: { op: string }[] | undefined): boolean => (words ?? []).every((w) => w.op === 'same')

function retry(): void {
  void fetchComparison()
}

function show(value: unknown): string {
  if (Array.isArray(value)) return value.length === 0 ? '(none)' : value.join(', ')
  return typeof value === 'string' && value !== '' ? value : '(empty)'
}

const asDoc = (node: RichTextNode): RichTextNode => ({ type: 'doc', content: [node] })

interface Group { kind: 'unchanged'; count: number; nodes: RichTextNode[] }
type Item = Group | { kind: 'block'; op: 'added' | 'removed' | 'changed'; node: RichTextNode; before?: RichTextNode; words?: { op: string; text: string }[]; links?: { before: string[]; after: string[] } }

/** Runs of unchanged blocks are folded so the changes stand out. */
const items = computed<Item[]>(() => {
  const out: Item[] = []
  for (const block of result.value?.body.blocks ?? []) {
    if (block.op === 'same') {
      const last = out[out.length - 1]
      if (last?.kind === 'unchanged') {
        last.count++
        last.nodes.push(block.node)
      } else {
        out.push({ kind: 'unchanged', count: 1, nodes: [block.node] })
      }
    } else {
      out.push(block.op === 'changed'
        ? { kind: 'block', op: 'changed', node: block.node, before: block.before, words: block.words, links: block.links }
        : { kind: 'block', op: block.op, node: block.node })
    }
  }
  return out
})

const changeCount = computed(() => {
  const r = result.value
  if (r === null) return 0
  return r.fields.length + (r.file.changed ? 1 : 0) + r.body.blocks.filter((b) => b.op !== 'same').length
})

const OP_LABELS = { added: 'Added', removed: 'Removed', changed: 'Edited' } as const
</script>

<template>
  <div class="pal-compare">
    <q-btn
      outline
      no-caps
      icon="compare_arrows"
      :label="open ? `Hide changes since revision ${previousLabel}` : `Compare with revision ${previousLabel}`"
      :aria-expanded="String(open)"
      :aria-controls="panelId"
      :loading="loading"
      @click="toggle"
    />
    <span
      class="pal-visually-hidden"
      role="status"
    >{{ open && result ? `${changeCount === 0 ? 'No changes' : `${changeCount} change${changeCount === 1 ? '' : 's'}`} since revision ${result.before.label}.` : '' }}</span>
    <div
      v-if="open"
      :id="panelId"
      class="q-mt-md"
      role="region"
      :aria-label="`Changes in revision ${revisionLabel} since revision ${previousLabel}`"
    >
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
            label="Try again"
            @click="retry"
          />
        </template>
      </q-banner>
      <template v-else-if="result">
        <p
          class="q-mb-sm"
          aria-hidden="true"
        >
          <strong>{{ changeCount === 0 ? 'No changes' : `${changeCount} change${changeCount === 1 ? '' : 's'}` }}</strong>
          since revision {{ result.before.label }}.
        </p>

        <q-markup-table
          v-if="result.fields.length > 0"
          flat
          bordered
          wrap-cells
          class="q-mb-md pal-card q-pa-none"
        >
          <thead>
            <tr>
              <th
                class="text-left"
                scope="col"
              >
                Field
              </th>
              <th
                class="text-left"
                scope="col"
              >
                Revision {{ result.before.label }}
              </th>
              <th
                class="text-left"
                scope="col"
              >
                This revision
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="f in result.fields"
              :key="f.field"
            >
              <th
                scope="row"
                class="text-left"
              >
                {{ FIELD_LABELS[f.field] ?? f.field }}
              </th>
              <td><del class="pal-diff-del"><span class="pal-visually-hidden">removed: </span>{{ show(f.before) }}</del></td>
              <td><ins class="pal-diff-ins"><span class="pal-visually-hidden">added: </span>{{ show(f.after) }}</ins></td>
            </tr>
          </tbody>
        </q-markup-table>

        <p
          v-if="result.file.changed"
          class="q-mb-md"
        >
          <strong>File:</strong>
          <template v-if="result.file.before">
            <del class="pal-diff-del"><span class="pal-visually-hidden">removed: </span>{{ result.file.before.filename }}</del>
          </template>
          <span v-else> (none)</span>
          →
          <template v-if="result.file.after">
            <ins class="pal-diff-ins"><span class="pal-visually-hidden">added: </span>{{ result.file.after.filename }}</ins>
          </template>
          <span v-else> removed</span>
          <span
            v-if="result.file.before && result.file.after && result.file.before.filename === result.file.after.filename"
            class="pal-muted"
          > (same name, different content)</span>
        </p>

        <template v-if="result.body.blocks.length > 0">
          <div class="row items-center justify-between q-mb-xs">
            <h3 class="text-subtitle2 q-my-none">
              Changes to the content
            </h3>
            <q-toggle
              v-model="showUnchanged"
              dense
              label="Show unchanged sections"
            />
          </div>
          <p
            v-if="result.body.truncated"
            class="pal-muted"
          >
            The content is too long for a section-by-section comparison, so the old and new content are shown in full.
          </p>
          <template
            v-for="(item, i) in items"
            :key="i"
          >
            <div
              v-if="item.kind === 'unchanged'"
              class="pal-diff-block pal-diff-block--same"
            >
              <RichTextView
                v-if="showUnchanged"
                :doc="{ type: 'doc', content: item.nodes }"
                :image-context="{ revision: revisionId }"
                label="Unchanged content"
                :level-offset="3"
              />
              <p
                v-else
                class="pal-muted q-mb-none"
              >
                {{ item.count }} unchanged section{{ item.count === 1 ? '' : 's' }}
              </p>
            </div>
            <div
              v-else
              class="pal-diff-block"
              :class="`pal-diff-block--${item.op}`"
            >
              <p class="pal-diff-label">
                {{ OP_LABELS[item.op] }}
              </p>
              <template v-if="item.op === 'changed' && item.words">
                <p
                  v-if="wordingSame(item.words)"
                  class="pal-muted q-mb-xs"
                >
                  The wording is the same; the formatting, heading level or a link changed. Before and after are shown below.
                </p>
                <div
                  v-if="item.links"
                  class="q-mb-xs"
                >
                  <strong>Link addresses changed:</strong>
                  <ul class="q-my-xs">
                    <li
                      v-for="(href, h) in item.links.before"
                      :key="`b${h}`"
                    >
                      <del class="pal-diff-del"><span class="pal-visually-hidden">removed: </span>{{ href }}</del>
                    </li>
                    <li
                      v-for="(href, h) in item.links.after"
                      :key="`a${h}`"
                    >
                      <ins class="pal-diff-ins"><span class="pal-visually-hidden">added: </span>{{ href }}</ins>
                    </li>
                  </ul>
                </div>
                <p
                  v-else-if="!isTextBlock(item.node)"
                  class="pal-muted q-mb-xs"
                >
                  Shown as plain text; use “Show before and after” for the full layout.
                </p>
                <p class="pal-diff-words q-mb-xs">
                  <template
                    v-for="(run, r) in item.words"
                    :key="r"
                  >
                    <ins
                      v-if="run.op === 'added'"
                      class="pal-diff-ins"
                    ><span class="pal-visually-hidden">[added: </span>{{ run.text }}<span class="pal-visually-hidden">]</span></ins><del
                      v-else-if="run.op === 'removed'"
                      class="pal-diff-del"
                    ><span class="pal-visually-hidden">[removed: </span>{{ run.text }}<span class="pal-visually-hidden">]</span></del><span v-else>{{ run.text }}</span>
                  </template>
                </p>
                <q-btn
                  v-if="!wordingSame(item.words)"
                  flat
                  dense
                  no-caps
                  size="sm"
                  :label="expanded.has(i) ? 'Hide before and after' : 'Show before and after'"
                  :aria-expanded="String(expanded.has(i))"
                  @click="toggleBlock(i)"
                />
                <div
                  v-if="(expanded.has(i) || wordingSame(item.words)) && item.before"
                  class="row q-col-gutter-md q-mt-xs"
                >
                  <div class="col-12 col-md-6">
                    <p class="pal-diff-label">
                      Revision {{ result.before.label }}
                    </p>
                    <RichTextView
                      :doc="asDoc(item.before)"
                      :image-context="{ revision: result.before.id }"
                      :label="`Before, revision ${result.before.label}`"
                      :level-offset="3"
                    />
                  </div>
                  <div class="col-12 col-md-6">
                    <p class="pal-diff-label">
                      This revision
                    </p>
                    <RichTextView
                      :doc="asDoc(item.node)"
                      :image-context="{ revision: revisionId }"
                      label="After, this revision"
                      :level-offset="3"
                    />
                  </div>
                </div>
              </template>
              <RichTextView
                v-else
                :doc="asDoc(item.node)"
                :image-context="{ revision: item.op === 'removed' ? result.before.id : revisionId }"
                :label="`${OP_LABELS[item.op]} content`"
                :level-offset="3"
              />
            </div>
          </template>
        </template>
      </template>
    </div>
  </div>
</template>
