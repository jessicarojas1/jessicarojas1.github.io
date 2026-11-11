<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { useQuasar } from 'quasar'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { TableCell, TableHeader, TableKit } from '@tiptap/extension-table'
import { HEADING_LEVELS, isSafeHref, normalizeHref, type RichTextNode } from '@/richText'

/**
 * PAL's in-browser editor (ADR-0009, native editor v1): headings, emphasis, lists, quotes,
 * rules, links and tables. Only what the server schema accepts is offered; code, images and
 * colours are deliberately absent. Emits the ProseMirror JSON on every change; saving is the
 * parent's job. `initial` is read once: remount the component (with a new key) to reload.
 */
const props = withDefaults(defineProps<{
  initial: RichTextNode | null
  label: string
  disabled?: boolean
}>(), { disabled: false })

const editorAttributes = {
  class: 'pal-richtext pal-richtext--editing',
  role: 'textbox',
  'aria-multiline': 'true',
  'aria-label': props.label,
}

// Cells without column widths or alignment: PAL does not store them, and TipTap would apply
// them as inline style attributes, which the CSP (style-src 'self') refuses.
function withoutCellLayout<T extends typeof TableCell | typeof TableHeader>(cell: T): T {
  return cell.extend({
    addAttributes() {
      const attributes: Record<string, unknown> = { ...(this.parent?.() ?? {}) }
      delete attributes.colwidth
      delete attributes.align
      return attributes as ReturnType<NonNullable<typeof this.parent>>
    },
  }) as T
}

const emit = defineEmits<{ change: [doc: RichTextNode] }>()
const $q = useQuasar()

const editor = useEditor({
  content: props.initial ?? '',
  editable: !props.disabled,
  // TipTap would inject a <style> element, which PAL's CSP refuses; styles live in app.scss.
  injectCSS: false,
  extensions: [
    StarterKit.configure({
      heading: { levels: [...HEADING_LEVELS] },
      code: false,
      codeBlock: false,
      link: {
        openOnClick: false,
        autolink: true,
        defaultProtocol: 'https',
        isAllowedUri: (url) => isSafeHref(url),
        shouldAutoLink: (url) => isSafeHref(url),
        HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
      },
    }),
    // Column resizing stores pixel widths the server does not keep; leave it off.
    TableKit.configure({ table: { resizable: false }, tableCell: false, tableHeader: false }),
    withoutCellLayout(TableCell),
    withoutCellLayout(TableHeader),
  ],
  editorProps: { attributes: { ...editorAttributes, 'aria-readonly': String(props.disabled) } },
  onUpdate: ({ editor: e }) => emit('change', e.getJSON() as RichTextNode),
})

watch(() => props.disabled, (disabled) => {
  editor.value?.setEditable(!disabled)
  editor.value?.setOptions({ editorProps: { attributes: { ...editorAttributes, 'aria-readonly': String(disabled) } } })
})
onBeforeUnmount(() => editor.value?.destroy())

const inTable = computed(() => editor.value?.isActive('table') ?? false)
const blockLabel = computed(() => {
  for (const level of HEADING_LEVELS) {
    if (editor.value?.isActive('heading', { level })) return `Heading ${level}`
  }
  return 'Normal text'
})

function chain() {
  // Every toolbar action returns focus to the text so keyboard users keep their place.
  return (editor.value as NonNullable<typeof editor.value>).chain().focus()
}

function setBlock(level: number | null): void {
  if (level === null) chain().setParagraph().run()
  else chain().setHeading({ level: level as (typeof HEADING_LEVELS)[number] }).run()
}

function editLink(): void {
  const current = editor.value?.getAttributes('link').href
  $q.dialog({
    title: 'Link',
    message: 'Web address (https://…) or email (mailto:…). Leave empty to remove the link.',
    prompt: {
      model: typeof current === 'string' ? current : '',
      type: 'text',
      label: 'Address',
      isValid: (v: string) => v.trim() === '' || normalizeHref(v) !== null,
    },
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'primary', unelevated: true, noCaps: true, label: 'Apply' },
  }).onOk((value: string) => {
    const href = normalizeHref(value)
    if (href === null) chain().extendMarkRange('link').unsetLink().run()
    else chain().extendMarkRange('link').setLink({ href }).run()
  })
}

interface ToolButton { icon: string; label: string; active?: () => boolean; enabled?: () => boolean; run: () => void }

const marks: ToolButton[] = [
  { icon: 'format_bold', label: 'Bold', active: () => editor.value?.isActive('bold') ?? false, run: () => chain().toggleBold().run() },
  { icon: 'format_italic', label: 'Italic', active: () => editor.value?.isActive('italic') ?? false, run: () => chain().toggleItalic().run() },
  { icon: 'format_underlined', label: 'Underline', active: () => editor.value?.isActive('underline') ?? false, run: () => chain().toggleUnderline().run() },
  { icon: 'strikethrough_s', label: 'Strikethrough', active: () => editor.value?.isActive('strike') ?? false, run: () => chain().toggleStrike().run() },
  { icon: 'link', label: 'Link', active: () => editor.value?.isActive('link') ?? false, run: editLink },
]
const blocks: ToolButton[] = [
  { icon: 'format_list_bulleted', label: 'Bulleted list', active: () => editor.value?.isActive('bulletList') ?? false, run: () => chain().toggleBulletList().run() },
  { icon: 'format_list_numbered', label: 'Numbered list', active: () => editor.value?.isActive('orderedList') ?? false, run: () => chain().toggleOrderedList().run() },
  { icon: 'format_quote', label: 'Quote', active: () => editor.value?.isActive('blockquote') ?? false, run: () => chain().toggleBlockquote().run() },
  { icon: 'horizontal_rule', label: 'Divider line', run: () => chain().setHorizontalRule().run() },
  { icon: 'table_chart', label: 'Insert table', enabled: () => !inTable.value, run: () => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() },
]
const history: ToolButton[] = [
  { icon: 'undo', label: 'Undo', enabled: () => editor.value?.can().undo() ?? false, run: () => chain().undo().run() },
  { icon: 'redo', label: 'Redo', enabled: () => editor.value?.can().redo() ?? false, run: () => chain().redo().run() },
]
const tableActions: { label: string; run: () => void }[] = [
  { label: 'Add row below', run: () => chain().addRowAfter().run() },
  { label: 'Add column right', run: () => chain().addColumnAfter().run() },
  { label: 'Delete row', run: () => chain().deleteRow().run() },
  { label: 'Delete column', run: () => chain().deleteColumn().run() },
  { label: 'Header row on/off', run: () => chain().toggleHeaderRow().run() },
  { label: 'Delete table', run: () => chain().deleteTable().run() },
]
</script>

<template>
  <div
    class="pal-editor"
    :class="{ 'pal-editor--disabled': disabled }"
  >
    <div
      v-if="editor"
      class="pal-editor-toolbar"
      role="toolbar"
      :aria-label="`${label} formatting`"
    >
      <q-btn-dropdown
        flat
        dense
        no-caps
        :label="blockLabel"
        :aria-label="`Text style: ${blockLabel}`"
        class="pal-editor-block"
        :disable="disabled"
      >
        <q-list dense>
          <q-item
            v-close-popup
            clickable
            @click="setBlock(null)"
          >
            <q-item-section>Normal text</q-item-section>
          </q-item>
          <q-item
            v-for="level in HEADING_LEVELS"
            :key="level"
            v-close-popup
            clickable
            @click="setBlock(level)"
          >
            <q-item-section :class="`pal-rt-h${level}`">
              Heading {{ level }}
            </q-item-section>
          </q-item>
        </q-list>
      </q-btn-dropdown>
      <span
        class="pal-editor-sep"
        aria-hidden="true"
      />
      <template
        v-for="(group, gi) in [marks, blocks, history]"
        :key="gi"
      >
        <q-btn
          v-for="tool in group"
          :key="tool.label"
          flat
          dense
          round
          :icon="tool.icon"
          :aria-label="tool.label"
          :aria-pressed="tool.active ? String(tool.active()) : undefined"
          :class="{ 'pal-editor-active': tool.active?.() }"
          :disable="disabled || (tool.enabled ? !tool.enabled() : false)"
          @click="tool.run"
        >
          <q-tooltip>{{ tool.label }}</q-tooltip>
        </q-btn>
        <span
          v-if="gi < 2"
          class="pal-editor-sep"
          aria-hidden="true"
        />
      </template>
    </div>
    <div
      v-if="editor && inTable"
      class="pal-editor-toolbar pal-editor-toolbar--table"
      role="toolbar"
      aria-label="Table"
    >
      <q-btn
        v-for="action in tableActions"
        :key="action.label"
        flat
        dense
        no-caps
        size="sm"
        :label="action.label"
        :disable="disabled"
        @click="action.run"
      />
    </div>
    <EditorContent
      :editor="editor"
      class="pal-editor-content"
    />
  </div>
</template>
