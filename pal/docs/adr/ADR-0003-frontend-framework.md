# ADR-0003: Frontend: Vue 3 + TypeScript + Quasar (gated by an accessibility spike)

- **Status:** Proposed
- **Date:** 2026-10-25

## Decision
Vue 3 (Composition API) + TypeScript (strict) + Quasar + Pinia + Vue Router, built with Vite. PAL wraps Quasar in its own thin component layer (`PalTable`, `PalSelect`, `PalStatusChip`, …) with design tokens on top of it.

## Gate
A Phase 1 spike tests the 8 most-used components (table, select, date input, dialog, tree, tabs, menu, file upload) with axe-core plus manual keyboard and screen-reader checks against WCAG 2.2 AA. If more than two require replacement, switch to PrimeVue (unstyled mode) before Phase 2.

## Rules
- No `v-html` with user data (enforced by an ESLint rule).
- The frontend never makes authorization decisions. It renders `allowedActions` returned by the API.
- No tokens in the browser (see ADR-0004).
