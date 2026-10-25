import { defineStore } from 'pinia'
import { setCssVar } from 'quasar'
import { api } from '@/api/client'
import { accentForText, DARK_SURFACE, isHexColour, isSafeLogoUrl, LIGHT_SURFACE, onAccent } from '@/branding'

export interface Branding {
  displayName: string
  logoUrl: string | null
  accentColor: string
  isDefault: { displayName: boolean; logoUrl: boolean; accentColor: boolean }
}

export const DEFAULT_BRANDING: Branding = {
  displayName: 'PAL',
  logoUrl: null,
  accentColor: '#2f5bd3',
  isDefault: { displayName: true, logoUrl: true, accentColor: true },
}

export const useBrandingStore = defineStore('branding', {
  state: () => ({
    branding: { ...DEFAULT_BRANDING },
    logoFailed: false,
  }),
  getters: {
    logo: (state): string | null => (state.logoFailed ? null : state.branding.logoUrl),
  },
  actions: {
    async load(): Promise<void> {
      try {
        this.apply(await api<Branding>('/api/branding'))
      } catch {
        // Branding is cosmetic: on failure the built-in defaults stay in place.
        this.apply(DEFAULT_BRANDING)
      }
    },

    apply(branding: Branding): void {
      this.branding = {
        ...branding,
        logoUrl: branding.logoUrl !== null && isSafeLogoUrl(branding.logoUrl) ? branding.logoUrl : null,
        accentColor: isHexColour(branding.accentColor) ? branding.accentColor : DEFAULT_BRANDING.accentColor,
      }
      this.logoFailed = false
      const accent = this.branding.accentColor
      setCssVar('primary', accent)
      const root = document.documentElement.style
      root.setProperty('--pal-on-primary', onAccent(accent))
      root.setProperty('--pal-primary-on-light', accentForText(accent, LIGHT_SURFACE))
      root.setProperty('--pal-primary-on-dark', accentForText(accent, DARK_SURFACE))
      document.title = this.branding.displayName
    },

    markLogoBroken(): void {
      this.logoFailed = true
    },

    async save(input: { displayName: string | null; logoUrl: string | null; accentColor: string | null }): Promise<void> {
      const result = await api<{ ok: boolean; branding: Branding }>('/api/admin/branding', { method: 'PUT', body: input })
      this.apply(result.branding)
    },
  },
})
