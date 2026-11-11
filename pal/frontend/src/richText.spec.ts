import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RichTextView from '@/components/RichTextView.vue'
import { isEmptyDocument, isSafeHref, normalizeHref, plainText, type RichTextNode } from '@/richText'

const p = (...content: RichTextNode[]): RichTextNode => ({ type: 'paragraph', content })
const t = (text: string, marks?: RichTextNode['marks']): RichTextNode => (marks ? { type: 'text', text, marks } : { type: 'text', text })

describe('link rules', () => {
  it.each([
    'https://example.test/a?b=c#d',
    'http://intranet.example.test',
    'mailto:qa@example.test',
  ])('allows %s', (href) => {
    expect(isSafeHref(href)).toBe(true)
  })

  it.each([
    'javascript:alert(1)',
    ' JaVaScRiPt:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'vbscript:x',
    '/api/admin/users',
    '//evil.test/',
    'https://intranet.example.test@evil.test/',
    'http://user:pass@evil.test/',
    'https://example.test/"onmouseover=x',
    'https://',
    '',
    42,
  ])('refuses %s', (href) => {
    expect(isSafeHref(href)).toBe(false)
  })

  it('adds https:// to a bare address and refuses other schemes', () => {
    expect(normalizeHref('example.test/page')).toBe('https://example.test/page')
    expect(normalizeHref('  mailto:a@b.test ')).toBe('mailto:a@b.test')
    expect(normalizeHref('javascript:alert(1)')).toBeNull()
    expect(normalizeHref('')).toBeNull()
  })
})

describe('empty documents', () => {
  it('treats blank paragraphs and headings as empty, rules and tables as content', () => {
    expect(isEmptyDocument(null)).toBe(true)
    expect(isEmptyDocument({ type: 'doc', content: [p(), { type: 'heading', attrs: { level: 1 } }] })).toBe(true)
    expect(isEmptyDocument({ type: 'doc', content: [{ type: 'horizontalRule' }] })).toBe(false)
    expect(isEmptyDocument({ type: 'doc', content: [p(t('x'))] })).toBe(false)
    expect(plainText({ type: 'doc', content: [p(t('a'), t('b')), p(t('c'))] })).toBe('ab\nc')
  })
})

describe('RichTextView', () => {
  it('renders the allowed structure with headings below the page h2', () => {
    const doc: RichTextNode = {
      type: 'doc',
      content: [
        { type: 'heading', attrs: { level: 1 }, content: [t('Purpose')] },
        p(t('Bold', [{ type: 'bold' }]), t(' and '), t('link', [{ type: 'link', attrs: { href: 'https://example.test' } }])),
        { type: 'orderedList', attrs: { start: 3 }, content: [{ type: 'listItem', content: [p(t('Step'))] }] },
        { type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableHeader', attrs: { colspan: 2 }, content: [p(t('Role'))] }] }] },
      ],
    }
    const wrapper = mount(RichTextView, { props: { doc, label: 'Content' } })

    expect(wrapper.find('h3.pal-rt-h1').text()).toBe('Purpose')
    expect(wrapper.find('strong').text()).toBe('Bold')
    const a = wrapper.find('a')
    expect(a.attributes('href')).toBe('https://example.test')
    expect(a.attributes('rel')).toBe('noopener noreferrer nofollow')
    expect(wrapper.find('ol').attributes('start')).toBe('3')
    expect(wrapper.find('th').attributes('colspan')).toBe('2')
    expect(wrapper.find('[role="region"]').attributes('aria-label')).toBe('Content')
  })

  it('never turns content into markup, script or unsafe links', () => {
    const doc: RichTextNode = {
      type: 'doc',
      content: [
        p(t('<img src=x onerror=alert(1)>')),
        p(t('click', [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }])),
        { type: 'html', content: [t('<script>alert(1)</script>')] },
        { type: 'image', attrs: { src: 'https://evil.test/x.png' } },
        p(t('styled', [{ type: 'textStyle', attrs: { style: 'color:red' } }])),
      ],
    }
    const wrapper = mount(RichTextView, { props: { doc } })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('style=')
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
    expect(wrapper.text()).toContain('click')
    expect(wrapper.text()).toContain('styled')
  })
})
