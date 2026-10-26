import { describe, expect, it } from 'vitest'
import { formatDateTime, roleLabel } from './format'

describe('formatDateTime', () => {
  it('formats in the requested PAL timezone, not the browser timezone, and labels it', () => {
    // 2026-11-05T07:00Z is midnight in Denver (MST, after the 1 Nov DST change).
    const denver = formatDateTime('2026-11-05T07:00:00Z', 'America/Denver')
    const berlin = formatDateTime('2026-11-05T07:00:00Z', 'Europe/Berlin')

    expect(denver).toMatch(/12:00\s?AM|00:00/)
    expect(denver).toMatch(/MST|GMT-7/)
    expect(berlin).toMatch(/8:00|08:00/)
    expect(denver).not.toBe(berlin)
  })
})

describe('roleLabel', () => {
  it('maps known roles and passes unknown ones through', () => {
    expect(roleLabel('PAL_ADMINISTRATOR')).toBe('PAL Administrator')
    expect(roleLabel('SOMETHING_NEW')).toBe('SOMETHING_NEW')
  })
})
