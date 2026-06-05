# Sentinel QMS Frontend — Implementation Notes

This document summarizes what is fully implemented versus scaffolded, and the key
architectural decisions, so a reviewer can quickly orient.

## Status legend
- **Full** — list + detail + create/workflow actions wired end-to-end to react-query hooks.
- **List + Detail** — complete, navigable list and detail views against the API (no create form yet).
- **Reference** — static/configuration view.

## Module coverage

| Module | Status | Notes |
| --- | --- | --- |
| Auth / Login | **Full** | JWT login, `/auth/me` profile load, transparent refresh on 401, logout. |
| Dashboard | **Full** | 8 KPI cards + 5 charts (NCR trend, CAPA aging, calibration pie, findings-by-clause, supplier performance) via `/dashboard`. |
| Nonconformances | **Full** | List (search/sort/filter/paginate), create modal, detail, **MRB disposition with electronic signature**, close with confirm. |
| CAPA | **Full** | List + detail with **8D stepper**, root cause, effectiveness verification, **close + e-signature**. |
| Documents | **Full** | List + detail with revision history and **approve & release e-signature**. |
| Suppliers | **Full** | List with scorecards, detail with SCAR table, ASL status, ratings, certifications. |
| Calibration | **Full** | Equipment register with due/overdue badges, detail with calibration history. |
| Changes (ECN/ECO) | **List + Detail** | Detail includes **approve & sign** workflow. |
| Complaints / RMA | **List + Detail** | Detail shows RMA, resolution, linked NCR/CAPA. |
| Audits | **List + Detail** | Detail shows findings table by clause/type. |
| Inspections / FAI | **List + Detail** | Detail shows AS9102-style characteristics table. |
| Management Review | **List + Detail** | Detail shows inputs/outputs/action items/attendees. |
| Risk Register | **List + Heat Map** | Severity × Occurrence heat map + RPN-sorted table. |
| Training | **List + Matrix** | Tabbed: training records list + competency matrix grid. |
| Admin · Users | **List** | User table with roles, status, last login. |
| Admin · Roles | **Reference** | RBAC capability matrix derived from `lib/rbac.ts`. |

## Cross-cutting features (all complete)

- **Routing** — `react-router-dom` v6 nested routes; every route wrapped in
  `ProtectedRoute` with optional `capability` gating. Code-split with `lazy()`.
- **AuthN/AuthZ** — `lib/auth.tsx` context; `lib/rbac.ts` capability map for 7 roles
  (Admin, Quality Manager, Quality Engineer, Auditor, Supplier Quality, Operator,
  Read-Only). Nav, action buttons, and routes are all gated.
- **Electronic signatures (21 CFR Part 11)** — `SignatureModal` requires meaning +
  reason + password re-authentication; used by NCR disposition, CAPA close,
  document approve, change approve.
- **Theme** — light/dark via CSS custom properties, persisted to `localStorage`,
  honors `prefers-color-scheme`. No hardcoded hex in component inline styles
  (uses `var(--...)`).
- **CUI banner** — top and bottom on every screen incl. login.
- **Accessibility** — semantic landmarks, `aria-*` on nav/dialog/sort/breadcrumbs,
  focus-visible rings, `sr-only` helpers, keyboard-dismissable modals (Esc).
- **Data layer** — `hooks/useResource.ts` generic CRUD factory generates per-domain
  react-query hooks (`useList`, `useDetail`, `useCreate`, `useUpdate`, `useRemove`,
  `useAction`) bound to documented endpoints. `useListController` standardizes
  search/sort/filter/pagination state.
- **UX feedback** — toast provider for mutation success/error; loading spinners;
  empty states; confirm dialogs.

## API contract assumptions

The backend `app/schemas` package was not yet populated, so domain types in
`src/types/domain.ts` were defined to be coherent and idiomatic for the documented
endpoints. They follow consistent conventions the backend can mirror:

- List endpoints return `Paginated<T>` (`items`, `total`, `page`, `page_size`, `pages`)
  and accept `page`, `page_size`, `search`, `sort`, `order`, plus per-domain filters.
- Detail at `GET /{resource}/{id}`; workflow actions at `POST /{resource}/{id}/{action}`
  (e.g. `nonconformances/{id}/disposition`, `capa/{id}/close`, `documents/{id}/approve`,
  `changes/{id}/approve`).
- Dashboard at `GET /dashboard`; competency matrix at `GET /training/competency-matrix`.
- Auth: `POST /auth/login`, `POST /auth/refresh`, `GET /auth/me`.

If backend field names differ, adjust `src/types/domain.ts` and the affected render
columns — the data flow and components remain unchanged.

## What is intentionally not built

- Create/edit forms for modules beyond Nonconformances (the create pattern is
  demonstrated by `NcrCreateModal` and can be cloned per domain).
- File-upload UI (attachments are rendered read-only; an uploader would attach to
  the `/attachments` endpoint).
- Real-time/websocket updates (react-query polling/invalidation is used instead).

## Testing

- `src/lib/rbac.test.ts` — capability matrix behavior across roles.
- `src/lib/format.test.ts` — date/overdue/bytes/initials formatters.
- Run with `npm run test` (vitest + jsdom; `src/test/setup.ts` stubs `matchMedia`).

## Notes / caveats

- `npm install` was **not** run in this environment, so `tsc`/`vite build` were not
  executed here. Imports, types, and default exports were verified to be coherent and
  free of unused symbols by static inspection. Run `npm install && npm run build`
  to confirm in CI.
