<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { ApiError } from '@/api/client'
import { isHexColour, isSafeLogoUrl, onAccent, readFileAsDataUrl } from '@/branding'
import { DEFAULT_BRANDING, useBrandingStore } from '@/stores/branding'

const $q = useQuasar()
const store = useBrandingStore()

const current = store.branding
const displayName = ref(current.isDefault.displayName ? '' : current.displayName)
const logoUrl = ref(current.logoUrl ?? '')
const accentColor = ref(current.isDefault.accentColor ? '' : current.accentColor)
const fieldErrors = ref<Record<string, string>>({})
const saving = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const logoError = computed(() => (isSafeLogoUrl(logoUrl.value) ? null : 'Use an http(s) image URL, or upload an image.'))
const colourError = computed(() => (accentColor.value === '' || isHexColour(accentColor.value) ? null : 'Use a hex colour such as #2f5bd3.'))
const previewName = computed(() => (displayName.value.trim() === '' ? DEFAULT_BRANDING.displayName : displayName.value.trim()))
const previewColour = computed(() => (isHexColour(accentColor.value) ? accentColor.value : DEFAULT_BRANDING.accentColor))
const isDataUrl = computed(() => logoUrl.value.startsWith('data:'))

async function onFileChosen(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file === undefined) return
  try {
    logoUrl.value = await readFileAsDataUrl(file)
  } catch (e) {
    $q.notify({ type: 'negative', message: e instanceof Error ? e.message : 'The file could not be used.' })
  }
}

async function save(): Promise<void> {
  if (logoError.value !== null || colourError.value !== null) return
  saving.value = true
  fieldErrors.value = {}
  try {
    await store.save({
      displayName: displayName.value.trim() === '' ? null : displayName.value.trim(),
      logoUrl: logoUrl.value === '' ? null : logoUrl.value,
      accentColor: accentColor.value === '' ? null : accentColor.value,
    })
    $q.notify({ type: 'positive', message: 'Branding saved and applied.' })
  } catch (e) {
    if (e instanceof ApiError) {
      fieldErrors.value = e.fields
      $q.notify({ type: 'negative', message: e.message })
    } else {
      $q.notify({ type: 'negative', message: 'Branding could not be saved.' })
    }
  } finally {
    saving.value = false
  }
}

function resetAll(): void {
  displayName.value = ''
  logoUrl.value = ''
  accentColor.value = ''
}
</script>

<template>
  <div class="pal-page">
    <header class="pal-page-header">
      <h1>Branding</h1>
      <p>Your organisation's name, logo and accent colour, shown to everyone across PAL, on the sign-in screen and in printed output.</p>
    </header>

    <div class="row q-col-gutter-lg">
      <form
        class="col-12 col-md-7"
        novalidate
        @submit.prevent="save"
      >
        <div class="pal-card q-gutter-y-md">
          <q-input
            v-model="displayName"
            label="Display name"
            outlined
            maxlength="80"
            counter
            :placeholder="DEFAULT_BRANDING.displayName"
            hint="Replaces the product name in the header and browser tab. Leave empty for the default."
            :error="fieldErrors.displayName !== undefined"
            :error-message="fieldErrors.displayName"
          />

          <div>
            <q-input
              v-model="logoUrl"
              label="Logo URL"
              outlined
              :readonly="isDataUrl"
              :hint="isDataUrl ? 'Using an uploaded image.' : 'An https:// link to an image, or upload a file below.'"
              :error="logoError !== null || fieldErrors.logoUrl !== undefined"
              :error-message="logoError ?? fieldErrors.logoUrl"
            >
              <template
                v-if="logoUrl !== ''"
                #append
              >
                <q-btn
                  flat
                  round
                  dense
                  icon="close"
                  aria-label="Remove logo"
                  @click="logoUrl = ''"
                />
              </template>
            </q-input>
            <div class="q-mt-sm">
              <label
                for="pal-logo-file"
                class="text-weight-medium"
              >Upload a logo file</label>
              <input
                id="pal-logo-file"
                ref="fileInput"
                type="file"
                accept="image/png,image/jpeg,image/gif,image/webp"
                class="block q-mt-xs"
                @change="onFileChosen"
              >
            </div>
            <dl
              class="pal-field-key"
              aria-label="Logo field reference"
            >
              <dt>Logo URL</dt>
              <dd>An http:// or https:// image address. Links containing a username or password are rejected.</dd>
              <dt>Upload</dt>
              <dd>PNG, JPEG, GIF or WebP, up to 300 KB. Stored inside PAL, so it also works without internet access. SVG is not accepted because it can contain scripts.</dd>
              <dt>If the logo cannot load</dt>
              <dd>PAL falls back to its built-in mark automatically.</dd>
            </dl>
          </div>

          <div class="row items-start q-gutter-md">
            <q-input
              v-model="accentColor"
              class="col"
              label="Accent colour"
              outlined
              placeholder="#2f5bd3"
              hint="Hex colour used for the header, buttons and links. Leave empty for the default."
              :error="colourError !== null || fieldErrors.accentColor !== undefined"
              :error-message="colourError ?? fieldErrors.accentColor"
            />
            <label class="column items-center">
              <span class="text-caption">Pick</span>
              <input
                type="color"
                :value="previewColour"
                aria-label="Pick accent colour"
                @input="accentColor = ($event.target as HTMLInputElement).value"
              >
            </label>
          </div>

          <div class="row justify-between q-pt-sm">
            <q-btn
              flat
              no-caps
              label="Reset to defaults"
              @click="resetAll"
            />
            <q-btn
              type="submit"
              color="primary"
              unelevated
              no-caps
              label="Save branding"
              :loading="saving"
              :disable="logoError !== null || colourError !== null"
            />
          </div>
        </div>
      </form>

      <section
        class="col-12 col-md-5"
        aria-labelledby="preview-heading"
      >
        <div class="pal-card">
          <h2
            id="preview-heading"
            class="text-subtitle1 text-weight-medium q-mt-none"
          >
            Preview
          </h2>
          <div
            class="q-pa-md row items-center no-wrap q-gutter-sm"
            :style="{ background: previewColour, color: onAccent(previewColour), borderRadius: 'var(--pal-radius-sm)' }"
          >
            <img
              v-if="logoUrl !== '' && logoError === null"
              :src="logoUrl"
              alt=""
              class="pal-brand-logo"
            >
            <q-icon
              v-else
              name="library_books"
              size="28px"
              aria-hidden="true"
            />
            <span class="text-weight-bold">{{ previewName }}</span>
          </div>
          <p class="pal-muted q-mt-md q-mb-none">
            Header text switches between white and dark automatically to stay readable on your colour.
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
