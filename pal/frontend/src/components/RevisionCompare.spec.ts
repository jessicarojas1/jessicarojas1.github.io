import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { Quasar } from 'quasar'
import RevisionCompare from '@/components/RevisionCompare.vue'
import type { RevisionComparison } from '@/api/documents'

const p = (text: string) => ({ type: 'paragraph', content: [{ type: 'text', text }] })

const comparison: RevisionComparison = {
  before: { id: 'a', label: '1.0' },
  after: { id: 'b', label: '2.0', labelConfirmed: false },
  fields: [{ field: 'title', before: 'Old title', after: 'New title' }],
  file: { changed: false, before: null, after: null },
  body: {
    truncated: false,
    blocks: [
      { op: 'same', node: p('Kept one') },
      { op: 'same', node: p('Kept two') },
      { op: 'changed', node: p('Calibrate quarterly.'), before: p('Calibrate yearly.'), words: [{ op: 'same', text: 'Calibrate ' }, { op: 'removed', text: 'yearly.' }, { op: 'added', text: 'quarterly.' }] },
      { op: 'added', node: p('Brand new section') },
    ],
  },
}

vi.mock('@/api/client', () => ({
  api: vi.fn(() => Promise.resolve(comparison)),
  ApiError: class extends Error {},
}))

describe('RevisionCompare', () => {
  it('loads on demand and marks changes with words and ins/del, folding unchanged sections', async () => {
    const wrapper = mount(RevisionCompare, {
      props: { revisionId: 'b', revisionLabel: '2.0', previousLabel: '1.0' },
      global: { plugins: [[Quasar, {}]] },
    })
    expect(wrapper.text()).toContain('Compare with revision 1.0')
    expect(wrapper.find('[role="region"]').exists()).toBe(false)

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('3 changes since revision 1.0')
    expect(text).toContain('2 unchanged sections')
    expect(text).not.toContain('Kept one')
    expect(wrapper.find('del.pal-diff-del').text()).toBe('removed: Old title')
    expect(wrapper.findAll('ins.pal-diff-ins').map((n) => n.text())).toEqual(['added: New title', '[added: quarterly.]'])
    expect(text).toContain('Edited')
    expect(text).toContain('Added')
    expect(text).toContain('Brand new section')
    expect(wrapper.html()).not.toContain('<script')
    // Live announcement for screen readers, and a before/after view of the edited block.
    expect(wrapper.find('[role="status"]').text()).toBe('3 changes since revision 1.0.')
    const showBoth = wrapper.findAll('button').find((b) => b.text().includes('Show before and after'))
    await showBoth?.trigger('click')
    expect(wrapper.text()).toContain('Calibrate yearly.')
  })

  it('says when only formatting changed', async () => {
    const { api } = await import('@/api/client')
    vi.mocked(api).mockResolvedValueOnce({
      ...comparison,
      fields: [],
      body: { truncated: false, blocks: [{ op: 'changed', node: { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Scope' }] }, before: { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Scope' }] }, words: [{ op: 'same', text: 'Scope' }] }] },
    })
    const wrapper = mount(RevisionCompare, {
      props: { revisionId: 'c', revisionLabel: '2.0', previousLabel: '1.0' },
      global: { plugins: [[Quasar, {}]] },
    })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('The wording is the same')
    // Before and after are shown straight away, since nothing else would reveal the change.
    expect(wrapper.findAll('.pal-diff-block--changed .pal-richtext')).toHaveLength(2)
  })

  it('lists swapped link addresses even when the words are identical', async () => {
    const { api } = await import('@/api/client')
    const linked = (href: string) => ({ type: 'paragraph', content: [{ type: 'text', text: 'the procedure', marks: [{ type: 'link', attrs: { href } }] }] })
    vi.mocked(api).mockResolvedValueOnce({
      ...comparison,
      fields: [],
      body: { truncated: false, blocks: [{ op: 'changed', node: linked('https://evil.example/sop'), before: linked('https://good.example/sop'), words: [{ op: 'same', text: 'the procedure' }], links: { before: ['https://good.example/sop'], after: ['https://evil.example/sop'] } }] },
    })
    const wrapper = mount(RevisionCompare, {
      props: { revisionId: 'd', revisionLabel: '2.0', previousLabel: '1.0' },
      global: { plugins: [[Quasar, {}]] },
    })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Link addresses changed')
    expect(wrapper.find('del.pal-diff-del').text()).toBe('removed: https://good.example/sop')
    expect(wrapper.find('ins.pal-diff-ins').text()).toBe('added: https://evil.example/sop')
  })
})
