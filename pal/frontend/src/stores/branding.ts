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

interface BrandingState {
  branding: Branding
  logoFailed: boolean
  status: 'idle' | 'loading' | 'loaded' | 'failed'
  pending: Promise<void> | null
}

export const useBrandingStore = defineStore('branding', {
  state: (): BrandingState => ({
    branding: { ...DEFAULT_BRANDING },
    logoFailed: false,
    status: 'idle',
    pending: null,
  }),
  getters: {
    logo: (state): string | null => (state.logoFailed ? null : state.branding.logoUrl),
  },
  actions: {
    /** Loads once; concurrent callers share the same request. */
    load(force = false): Promise<void> {
      if (this.pending !== null && !force) return this.pending
      this.status = 'loading'
      this.pending = (async () => {
        try {
          this.apply(await api<Branding>('/api/branding'))
          this.status = 'loaded'
        } catch {
          // Cosmetic for viewers: defaults stay in place. The editor refuses to save in this state.
          this.apply(DEFAULT_BRANDING)
          this.status = 'failed'
          this.pending = null
        }
      })()
      return this.pending
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
      this.status = 'loaded'
    },
  },
})
