// Per-viewer convenience only: the colour scheme preference lives in this browser.
const KEY = 'pal.theme'

export type ThemePreference = boolean | 'auto'

export function readThemePreference(): ThemePreference {
  try {
    const stored = window.localStorage.getItem(KEY)
    if (stored === 'dark') return true
    if (stored === 'light') return false
  } catch {
    // Storage can be unavailable (private mode, blocked site data); fall back to the system setting.
  }
  return 'auto'
}

export function storeThemePreference(value: ThemePreference): void {
  try {
    if (value === 'auto') window.localStorage.removeItem(KEY)
    else window.localStorage.setItem(KEY, value ? 'dark' : 'light')
  } catch {
    // Not persisted; the choice still applies for this page view.
  }
}
