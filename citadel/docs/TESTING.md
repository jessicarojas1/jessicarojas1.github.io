# CITADEL — Testing Guide

## Running the suites

```bash
cd citadel/server
npm ci
npm test        # smoke + node --test (lib + api + cli)  — 86 cases
npm run lint    # ESLint over server/ and ../js
```

Accuracy benchmark (gated in CI):

```bash
node benchmark/run.js            # recall/precision/F1 over a labeled corpus (fails under 0.90/0.90)
node benchmark/owasp/run.js      # OWASP Benchmark Java runner (manual; not in CI)
```

## What is covered

| Suite | File | Covers |
|---|---|---|
| Library | `server/test/lib.test.js` | JWT (alg-pinning, tamper, expiry), AES-GCM secret sealing, FIPS KDF, multi-tenancy slug/SQLi guards, TOTP/MFA + one-time backup codes, rate-limit/lockout, session revocation, audit hash-chain + tamper detection, password hashing (timing-safe), OIDC mapping, fingerprint merge, scoring (no-NaN), license tiers, **offline advisory DB**, **modern secret patterns + Luhn PAN**, readiness gate, schema↔db.js parity, and the browser engine loaded in Node (Java taint rules) |
| API integration | `server/test/api.test.js` | Real server: login/refresh/me, httpOnly refresh cookie, bad creds → 401, admin routes → 401 unauth, **RBAC-negative (analyst denied admin routes → 403)**, malformed JSON → 400 (no stack leak), **SSRF block**, scan-url traversal subpath reject, must-change-password gate, admin reset, **uploaded-file scan**, **zip-slip rejection**, **decompression-bomb cap → 400**, logout revocation, two-step MFA |
| CLI | `server/test/cli.test.js` | `cli/citadel-gate.js` release gate exit codes + `--json` output |
| Smoke | `server/test/smoke.js` | engine reuse, scanners degrade-not-throw, heuristic+scanner merge, report shape |

## What runs in CI (`.github/workflows/ci.yml`, job `node-citadel`, gating)

1. `node --check` over server + browser JS (`scripts/ci-node-check.sh`)
2. ESLint (`npm run lint`)
3. `npm audit --omit=dev --audit-level=high` (own production deps)
4. `npm test`
5. SARIF structural validation (`scripts/validate-sarif.js`)
6. Accuracy benchmark (recall ≥ 0.90, precision ≥ 0.90)

## Writing a test

- **Server libs / engine:** add to `lib.test.js`. Use `loadEngine()` to load the
  browser analyzer modules in Node and drive `CITADEL.scanner.scan([entry])`.
- **HTTP behavior:** add to `api.test.js` — it spawns the real server in
  file-store mode (`before`/`after` hooks) and hits it with `fetch`.
- Assemble any token-shaped test fixtures from fragments so the file contains no
  literal that trips secret-scanning push protection.

## Real-browser UI smoke tests (local/manual)

```bash
cd citadel/server
npm run test:ui
```

`server/test/ui.playwright.test.js` launches a real Chromium (via
`playwright-core`) against the real server + real SPA — the same `before`/
`after` server-spawn pattern as `api.test.js` — and drives the page with real
keyboard/mouse input. It covers UI surfaces that the rest of the suite only
ever exercises through Node DOM stubs:

- the app loads at `/` with no unexpected console/page errors
- `window.CITADEL.ui` (`js/ui.js`) exposes `toast` / `confirm` / `prompt`
- `CITADEL.ui.toast()` renders a real `.citadel-toast` element in the DOM
- `CITADEL.ui.confirm()` opens a real `role="dialog" aria-modal="true"` modal;
  a real Cancel-button click resolves it to `false`, and a real `Escape`
  keypress also resolves it to `false`
- a real `?` keypress opens the `.kbd-overlay` keyboard-shortcuts overlay;
  repeated real `Tab`/`Shift+Tab` presses never move focus outside
  `.kbd-card` (the focus trap added in `js/app.js`); `Escape` closes it

It requires the Chromium binary pre-installed at
`/opt/pw-browsers/chromium-1194/chrome-linux/chrome` (launched with
`--no-sandbox`, needed in container environments). If that binary is missing,
every test in the file `test.skip`s instead of failing, so it can never become
a flaky/breaking gate on a runner that lacks it.

This is **not** part of `npm test` and **not** wired into CI (`ci.yml`) yet —
CI runners aren't currently provisioned with Chromium for this. Run it
manually/locally when touching `js/ui.js` or the keyboard-overlay/focus-trap
code in `js/app.js`.

## Known gaps

- No line-coverage threshold gate (the benchmark gates detection accuracy, not
  code coverage).
- The OWASP Benchmark runner is manual, not wired into CI.
- `js/report.js` exporters and the SBOM manifest parsers have indirect coverage
  (via corpus/smoke) rather than dedicated unit tests.
