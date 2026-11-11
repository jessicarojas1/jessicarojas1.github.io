// Content written in PAL's editor (ADR-0009). The server validates and canonicalizes it
// (backend RichTextDocument); the client renders it node by node and never as HTML.

export interface RichTextMark {
  type: string
  attrs?: Record<string, unknown>
}

export interface RichTextNode {
  type: string
  attrs?: Record<string, unknown>
  content?: RichTextNode[]
  marks?: RichTextMark[]
  text?: string
}

/** Heading levels the editor offers; the server accepts exactly these. */
export const HEADING_LEVELS = [1, 2, 3, 4] as const

/**
 * Links may only point at web pages or mail addresses (mirrors the server rule). Anything
 * else, including javascript:, data: and relative URLs, is refused.
 */
export function isSafeHref(href: unknown): href is string {
  if (typeof href !== 'string') return false
  const value = href.trim()
  if (value === '' || value.length > 2048 || /[\u0000-\u0020\u007f<>"\\]/.test(value)) return false
  if (/^mailto:[^@\s]+@[^@\s]+$/i.test(value)) return true
  if (!/^https?:\/\/[^/?#@]+/i.test(value)) return false
  try {
    const url = new URL(value)
    // No user@host forms: they hide the real destination behind a familiar-looking name.
    return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '' && url.username === '' && url.password === ''
  } catch {
    return false
  }
}

/**
 * Turns what a person typed into a link address: adds https:// to a bare host such as
 * "example.com/page", and returns null when the result is not an allowed link.
 */
export function normalizeHref(input: string): string | null {
  const value = input.trim()
  if (value === '') return null
  const candidate = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : `https://${value}`
  return isSafeHref(candidate) ? candidate : null
}

/** Plain text of a document, for counts and empty checks. */
export function plainText(node: RichTextNode | null | undefined): string {
  if (node === null || node === undefined) return ''
  if (node.type === 'text') return node.text ?? ''
  return (node.content ?? []).map(plainText).join(node.type === 'doc' ? '\n' : '')
}

/** True when a document has no text, table or rule (the server stores no body then). */
export function isEmptyDocument(node: RichTextNode | null | undefined): boolean {
  if (node === null || node === undefined) return true
  if (plainText(node).trim() !== '') return false
  const stack: RichTextNode[] = [node]
  while (stack.length > 0) {
    const current = stack.pop() as RichTextNode
    if (current.type === 'table' || current.type === 'horizontalRule') return false
    stack.push(...(current.content ?? []))
  }
  return true
}
