// Client-side mirror of the server's branding rules, for immediate feedback. The server
// re-validates everything; this never replaces that.

export const MAX_LOGO_BYTES = 300 * 1024
export const ALLOWED_LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const

export function isSafeLogoUrl(value: string): boolean {
  if (value === '') return true
  if (value.startsWith('data:')) {
    return /^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$/.test(value)
  }
  try {
    const url = new URL(value)
    return (url.protocol === 'https:' || url.protocol === 'http:') && url.username === '' && url.password === ''
  } catch {
    return false
  }
}

export function isHexColour(value: string): boolean {
  return /^#[0-9a-fA-F]{6}$/.test(value)
}

export function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    if (!(ALLOWED_LOGO_TYPES as readonly string[]).includes(file.type)) {
      reject(new Error('Choose a PNG, JPEG, GIF or WebP image.'))
      return
    }
    if (file.size > MAX_LOGO_BYTES) {
      reject(new Error('The image must be 300 KB or smaller.'))
      return
    }
    const reader = new FileReader()
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '')
    reader.onerror = () => reject(new Error('The file could not be read.'))
    reader.readAsDataURL(file)
  })
}

// ---------------------------------------------------------------------------
// Contrast (WCAG 2.x). Organisations choose any accent colour, so readable text
// colours are derived from it instead of being assumed.
// ---------------------------------------------------------------------------

const AA_NORMAL_TEXT = 4.5
// Worst-case (lightest) surfaces for each theme; see --pal-surface tokens in app.scss.
export const LIGHT_SURFACE = '#f5f7fb'
export const DARK_SURFACE = '#111a2e'
const DARK_TEXT = '#111827'

function channels(hex: string): [number, number, number] {
  const n = parseInt(hex.slice(1), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

function toHex([r, g, b]: [number, number, number]): string {
  return '#' + [r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')
}

export function relativeLuminance(hex: string): number {
  const [r, g, b] = channels(hex).map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrastRatio(a: string, b: string): number {
  const [hi, lo] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x) as [number, number]
  return (hi + 0.05) / (lo + 0.05)
}

/** Text colour to place ON the accent (header, filled buttons). */
export function onAccent(accent: string): string {
  return contrastRatio('#ffffff', accent) >= contrastRatio(DARK_TEXT, accent) ? '#ffffff' : DARK_TEXT
}

/** The accent, lightened or darkened just enough to read as text on the given surface. */
export function accentForText(accent: string, surface: string): string {
  const towards: [number, number, number] = relativeLuminance(surface) < 0.5 ? [255, 255, 255] : [0, 0, 0]
  const start = channels(accent)
  for (let step = 0; step <= 20; step++) {
    const t = step / 20
    const mixed = toHex([0, 1, 2].map((i) => start[i]! + (towards[i]! - start[i]!) * t) as [number, number, number])
    if (contrastRatio(mixed, surface) >= AA_NORMAL_TEXT) return mixed
  }
  return toHex(towards)
}
