<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { mergeAttributes, Node } from '@tiptap/core'
import { Heading } from '@tiptap/extension-heading'
import StarterKit from '@tiptap/starter-kit'
import { TableCell, TableHeader, TableKit } from '@tiptap/extension-table'
import { ApiError, uploadFile } from '@/api/client'
import { HEADING_LEVELS, IMAGE_ACCEPT, imageUrl, isImageRef, isSafeHref, normalizeHref, type ImageContext, type RichTextNode } from '@/richText'

/**
 * PAL's in-browser editor (ADR-0009, native editor v1): headings, emphasis, lists, quotes,
 * rules, links, tables and images. Only what the server schema accepts is offered; code and
 * colours are deliberately absent. Emits the ProseMirror JSON on every change; saving is the
 * parent's job. `initial` is read once: remount the component (with a new key) to reload.
 */
const props = withDefaults(defineProps<{
  initial: RichTextNode | null
  label: string
  disabled?: boolean
  /** Where this content lives, so its images load through the right access check. */
  imageContext?: ImageContext
}>(), { disabled: false, imageContext: undefined })

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
      return attributes
    },
  }) as T
}

type Level = (typeof HEADING_LEVELS)[number]
const isLevel = (value: unknown): value is Level => HEADING_LEVELS.includes(value as Level)

// The page already has <h1> (title) and <h2> (sections), so content heading N is an
// <h(N+2)> while editing too, matching the read-only view; data-level keeps copy and
// paste inside the editor from shifting levels.
const PageHeading = Heading.extend({
  parseHTML() {
    return [
      ...HEADING_LEVELS.map((level) => ({ tag: `h${level + 2}[data-level="${level}"]`, priority: 60, attrs: { level } })),
      ...HEADING_LEVELS.map((level) => ({ tag: `h${level}`, attrs: { level } })),
    ]
  },
  renderHTML({ node, HTMLAttributes }) {
    const level = isLevel(node.attrs.level) ? node.attrs.level : 1
    return [`h${Math.min(level + 2, 6)}`, mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, { class: `pal-rt-h${level}`, 'data-level': String(level) }), 0]
  },
}).configure({ levels: [...HEADING_LEVELS] })

// Images are stored files referenced by SHA-256 (decision #29); there is no URL attribute,
// and only images PAL rendered itself (img[data-sha256]) are recognised when pasting, so
// pasted content can never pull in an outside image.
const ContentImage = Node.create({
  name: 'image',
  group: 'block',
  atom: true,
  draggable: true,
  addAttributes() {
    return {
      sha256: { default: null, parseHTML: (el) => el.getAttribute('data-sha256'), renderHTML: () => ({}) },
      alt: { default: '', parseHTML: (el) => el.getAttribute('alt') ?? '', renderHTML: () => ({}) },
    }
  },
  parseHTML() {
    return [{ tag: 'img[data-sha256]' }]
  },
  renderHTML({ node }) {
    const sha = node.attrs.sha256 as unknown
    const alt = typeof node.attrs.alt === 'string' ? node.attrs.alt : ''
    return ['img', isImageRef(sha)
      ? { src: imageUrl(sha, props.imageContext), alt, 'data-sha256': sha, class: 'pal-rt-image' }
      : { alt, class: 'pal-rt-image' }]
  },
})

const emit = defineEmits<{ change: [doc: RichTextNode] }>()
const $q = useQuasar()

const editor = useEditor({
  content: props.initial ?? '',
  editable: !props.disabled,
  // TipTap would inject a <style> element, which PAL's CSP refuses; styles live in app.scss.
  injectCSS: false,
  extensions: [
    StarterKit.configure({
      heading: false,
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
    PageHeading,
    ContentImage,
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
const onImage = computed(() => editor.value?.isActive('image') ?? false)
const uploadingImage = ref(false)
const imageInput = ref<HTMLInputElement | null>(null)
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
  else chain().setHeading({ level: level as Level }).run()
}

function askImageDescription(current: string, onOk: (alt: string) => void): void {
  $q.dialog({
    title: 'Image description',
    message: 'Describe what the image shows for people who cannot see it. Leave it empty only if the image is purely decorative. (PAL removes hidden photo data such as location; animated GIFs become still images.)',
    prompt: { model: current, type: 'text', label: 'Description (up to 250 characters)', isValid: (v: string) => v.length <= 250 },
    cancel: { flat: true, noCaps: true, label: 'Cancel' },
    ok: { color: 'primary', unelevated: true, noCaps: true, label: 'Save description' },
  }).onOk((alt: string) => onOk(alt.trim()))
}

function pickImage(): void {
  if (!uploadingImage.value) imageInput.value?.click()
}

async function onImageChosen(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file === undefined) return
  uploadingImage.value = true
  try {
    const uploaded = await uploadFile(file, 'image')
    askImageDescription('', (alt) => {
      chain().insertContent({ type: 'image', attrs: { sha256: uploaded.sha256, alt } }).run()
    })
  } catch (e) {
    $q.notify({ type: 'negative', message: e instanceof ApiError ? e.message : 'The image could not be uploaded.' })
  } finally {
    uploadingImage.value = false
  }
}

function editImageDescription(): void {
  const current = editor.value?.getAttributes('image').alt
  askImageDescription(typeof current === 'string' ? current : '', (alt) => {
    chain().updateAttributes('image', { alt }).run()
  })
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
  { icon: 'image', label: 'Insert image', enabled: () => !uploadingImage.value, run: pickImage },
  { icon: 'notes', label: 'Image description', enabled: () => onImage.value, run: editImageDescription },
]
const history: ToolButton[] = [
  { icon: 'undo', label: 'Undo', enabled: () => editor.value?.can().undo() ?? false, run: () => chain().undo().run() },
  { icon: 'redo', label: 'Redo', enabled: () => editor.value?.can().redo() ?? false, run: () => chain().redo().run() },
]
const groups = [
  { tools: marks, offset: 1 },
  { tools: blocks, offset: 1 + marks.length },
  { tools: history, offset: 1 + marks.length + blocks.length },
]
const allTools = [...marks, ...blocks, ...history]

// WAI-ARIA toolbar pattern: each toolbar is one Tab stop; arrow keys, Home and End move
// within it. Index 0 of the main toolbar is the text-style menu.
const roving = reactive<{ main: number; table: number }>({ main: 0, table: 0 })
const mainStop = computed(() => (roving.main <= allTools.length ? roving.main : 0))

function isOff(tool: ToolButton): boolean {
  return props.disabled || (tool.enabled ? !tool.enabled() : false)
}

function runTool(tool: ToolButton): void {
  if (!isOff(tool)) tool.run()
}

function onToolbarKeydown(name: 'main' | 'table', event: KeyboardEvent): void {
  if (event.altKey || event.ctrlKey || event.metaKey || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return
  // Unavailable tools stay focusable (aria-disabled), as the WAI-ARIA toolbar pattern allows,
  // so focus is never lost when, say, Undo runs out of history under the keyboard.
  const items = [...(event.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('[data-roving]')]
    .filter((el) => !el.hasAttribute('disabled'))
  if (items.length === 0) return
  const current = items.findIndex((el) => el.contains(document.activeElement))
  const next = event.key === 'Home' ? 0
    : event.key === 'End' ? items.length - 1
      : (current + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length
  event.preventDefault()
  const target = items[next] as HTMLElement
  roving[name] = Number(target.dataset.roving)
  target.focus()
}

function onToolbarFocusin(name: 'main' | 'table', event: FocusEvent): void {
  const target = (event.target as HTMLElement).closest<HTMLElement>('[data-roving]')
  if (target !== null) roving[name] = Number(target.dataset.roving)
}

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
      @keydown="onToolbarKeydown('main', $event)"
      @focusin="onToolbarFocusin('main', $event)"
    >
      <q-btn-dropdown
        flat
        dense
        no-caps
        :label="blockLabel"
        :aria-label="`Text style: ${blockLabel}`"
        class="pal-editor-block"
        :disable="disabled"
        data-roving="0"
        :tabindex="mainStop === 0 ? 0 : -1"
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
        v-for="(group, gi) in groups"
        :key="gi"
      >
        <q-btn
          v-for="(tool, ti) in group.tools"
          :key="tool.label"
          flat
          dense
          round
          :icon="tool.icon"
          :aria-label="tool.label"
          :aria-pressed="tool.active ? String(tool.active()) : undefined"
          :aria-disabled="isOff(tool) ? 'true' : undefined"
          :class="{ 'pal-editor-active': tool.active?.(), 'pal-editor-off': isOff(tool) }"
          :data-roving="group.offset + ti"
          :tabindex="mainStop === group.offset + ti ? 0 : -1"
          @click="runTool(tool)"
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
      @keydown="onToolbarKeydown('table', $event)"
      @focusin="onToolbarFocusin('table', $event)"
    >
      <q-btn
        v-for="(action, ai) in tableActions"
        :key="action.label"
        flat
        dense
        no-caps
        size="sm"
        :label="action.label"
        :aria-disabled="disabled ? 'true' : undefined"
        :class="{ 'pal-editor-off': disabled }"
        :data-roving="ai"
        :tabindex="roving.table === ai ? 0 : -1"
        @click="disabled || action.run()"
      />
    </div>
    <input
      ref="imageInput"
      type="file"
      :accept="IMAGE_ACCEPT"
      class="pal-visually-hidden"
      tabindex="-1"
      aria-hidden="true"
      @change="onImageChosen"
    >
    <p
      v-if="uploadingImage"
      class="pal-muted q-ma-sm"
      role="status"
    >
      <q-spinner size="16px" /> Uploading and checking the image…
    </p>
    <EditorContent
      :editor="editor"
      class="pal-editor-content"
    />
  </div>
</template>
