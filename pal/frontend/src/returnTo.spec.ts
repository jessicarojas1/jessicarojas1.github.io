import { describe, expect, it } from 'vitest'
import { safeReturnTo } from './returnTo'

describe('safeReturnTo', () => {
  it.each(['/', '/library', '/documents/abc?tab=history', '/library?q=a%20b#top'])('keeps the local path %s', (value) => {
    expect(safeReturnTo(value)).toBe(value)
  })

  it.each([
    '//evil.example',
    '/\\evil.example',
    '/\t/evil.example',
    '/\n/evil.example',
    '\\\\evil.example',
    'https://evil.example',
    'javascript:alert(1)',
    'library',
    '',
    undefined,
    null,
    ['/library'],
  ])('falls back to the dashboard for %j', (value) => {
    expect(safeReturnTo(value)).toBe('/')
  })

  it('never resolves to another origin', () => {
    for (const value of ['//evil.example', '/\\evil.example', '/\t/evil.example']) {
      expect(new URL(safeReturnTo(value), 'https://pal.example').origin).toBe('https://pal.example')
    }
  })
})
