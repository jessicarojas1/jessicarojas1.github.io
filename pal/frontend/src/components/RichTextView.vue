<script lang="ts">
import { defineComponent, h, type PropType, type VNode, type VNodeArrayChildren } from 'vue'
import { isSafeHref, type RichTextNode } from '@/richText'

/**
 * Read-only rendering of editor content. Every node becomes a Vue element chosen from a
 * fixed list; unknown nodes and marks are dropped and link addresses are re-checked, so
 * nothing in the content can ever become script or markup (no v-html, no innerHTML).
 *
 * Headings 1–4 in the content render as <h3>–<h6>, because the page already uses <h1>
 * for the document title and <h2> for its sections; classes keep their visual size.
 */
export default defineComponent({
  name: 'RichTextView',
  props: {
    doc: { type: Object as PropType<RichTextNode>, required: true },
    label: { type: String, default: 'Document content' },
  },
  setup(props) {
    const children = (node: RichTextNode): VNodeArrayChildren => (node.content ?? []).map(render)

    function int(value: unknown, min: number, max: number): number | undefined {
      return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max ? value : undefined
    }

    function text(node: RichTextNode): VNode | string {
      let out: VNode | string = node.text ?? ''
      // Apply marks innermost-first in a fixed order so nesting is stable.
      for (const mark of [...(node.marks ?? [])].reverse()) {
        switch (mark.type) {
          case 'bold': out = h('strong', [out]); break
          case 'italic': out = h('em', [out]); break
          case 'underline': out = h('u', [out]); break
          case 'strike': out = h('s', [out]); break
          case 'link': {
            const href = mark.attrs?.href
            if (isSafeHref(href)) {
              out = h('a', { href: href.trim(), target: '_blank', rel: 'noopener noreferrer nofollow', class: 'pal-text-primary' }, [out])
            }
            break
          }
          default: break
        }
      }
      return out
    }

    function render(node: RichTextNode): VNode | string | null {
      switch (node.type) {
        case 'paragraph': return h('p', children(node))
        case 'heading': {
          const level = int(node.attrs?.level, 1, 4) ?? 1
          return h(`h${Math.min(level + 2, 6)}`, { class: `pal-rt-h${level}` }, children(node))
        }
        case 'bulletList': return h('ul', children(node))
        case 'orderedList': return h('ol', { start: int(node.attrs?.start, 1, 100000) }, children(node))
        case 'listItem': return h('li', children(node))
        case 'blockquote': return h('blockquote', children(node))
        case 'horizontalRule': return h('hr')
        case 'hardBreak': return h('br')
        case 'table': return h('div', { class: 'pal-rt-table-wrap' }, [h('table', [h('tbody', children(node))])])
        case 'tableRow': return h('tr', children(node))
        case 'tableHeader':
        case 'tableCell':
          return h(node.type === 'tableHeader' ? 'th' : 'td', {
            colspan: int(node.attrs?.colspan, 1, 20),
            rowspan: int(node.attrs?.rowspan, 1, 50),
          }, children(node))
        case 'text': return text(node)
        default: return null
      }
    }

    return () => h('div', { class: 'pal-richtext', role: 'region', 'aria-label': props.label }, children(props.doc))
  },
})
</script>
