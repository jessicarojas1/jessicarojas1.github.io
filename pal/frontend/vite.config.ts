import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'

// The PHP backend serves /api, /auth and /health. In development Vite proxies them so
// the browser sees one origin, exactly as in production behind nginx (the BFF model).
const backend = process.env.PAL_BACKEND_URL ?? 'http://127.0.0.1:8000'
const proxy = {
  '/api': { target: backend, changeOrigin: false },
  '/auth': { target: backend, changeOrigin: false },
  '/health': { target: backend, changeOrigin: false },
}

// Mirrors the production CSP set by nginx, so `npm run preview` surfaces violations.
const productionCsp = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self'",
  "img-src 'self' data: https:",
  "font-src 'self'",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'none'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ')

export default defineConfig({
  plugins: [
    vue({ template: { transformAssetUrls } }),
    quasar({ sassVariables: fileURLToPath(new URL('./src/css/quasar.variables.scss', import.meta.url)) }),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: { port: 5173, strictPort: true, proxy },
  preview: { port: 4174, strictPort: true, proxy, headers: { 'Content-Security-Policy': productionCsp } },
  build: { outDir: 'dist', sourcemap: false },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
  },
})
