import { describe, expect, it } from 'vitest'
import { accentForText, contrastRatio, DARK_SURFACE, isHexColour, isSafeLogoUrl, LIGHT_SURFACE, onAccent } from './branding'

describe('branding validation', () => {
  it.each([
    ['', true],
    ['https://cdn.example.test/logo.png', true],
    ['data:image/png;base64,iVBORw0KGgo=', true],
    ['javascript:alert(1)', false],
    ['data:image/svg+xml;base64,PHN2Zz4=', false],
    ['data:text/html;base64,PGgxPg==', false],
    ['https://user:pw@example.test/logo.png', false],
    ['http://cdn.example.test/logo.png', false],
    ['ftp://example.test/logo.png', false],
  ])('logo %s -> %s', (value, expected) => {
    expect(isSafeLogoUrl(value)).toBe(expected)
  })

  it.each([
    ['#2f5bd3', true],
    ['#ABCDEF', true],
    ['#abc', false],
    ['red', false],
    ['#123456;color:red', false],
  ])('colour %s -> %s', (value, expected) => {
    expect(isHexColour(value)).toBe(expected)
  })
})

describe('accent contrast', () => {
  it('matches known WCAG ratios', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1)
    expect(contrastRatio('#2f5bd3', '#111a2e')).toBeCloseTo(2.93, 1)
  })

  it.each(['#2f5bd3', '#ffd400', '#00ff00', '#111111', '#7c3aed', '#ffffff', '#000000'])('derives readable colours for accent %s', (accent) => {
    expect(contrastRatio(onAccent(accent), accent)).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(accentForText(accent, LIGHT_SURFACE), LIGHT_SURFACE)).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(accentForText(accent, DARK_SURFACE), DARK_SURFACE)).toBeGreaterThanOrEqual(4.5)
  })

  it('text on the accent meets 4.5:1 across the whole colour space', () => {
    let worst = 21
    for (let r = 0; r <= 255; r += 15) {
      for (let g = 0; g <= 255; g += 15) {
        for (let b = 0; b <= 255; b += 15) {
          const accent = '#' + [r, g, b].map((c) => c.toString(16).padStart(2, '0')).join('')
          worst = Math.min(worst, contrastRatio(onAccent(accent), accent))
        }
      }
    }
    expect(worst).toBeGreaterThanOrEqual(4.5)
  })

  it('leaves an already-readable accent unchanged', () => {
    expect(accentForText('#1d3f9e', LIGHT_SURFACE)).toBe('#1d3f9e')
  })
})
