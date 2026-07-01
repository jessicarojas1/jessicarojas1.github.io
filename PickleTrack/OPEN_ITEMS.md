# PickleTrack — Open Items (Production-Readiness Register)

Honest status of what is built vs. outstanding. PickleTrack's **feature core (Phase 1)** is
complete — live scoring with side-out serving logic, undo via a point-event log, match
history, stats, court finder, and a rules reference. The items below are the gaps between a
working app and a shippable, operable product, grouped by theme.

Legend: ✅ done · 🟡 partial · ❌ not started

Related: [README.md](README.md) · [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) · [docs/SECURITY.md](docs/SECURITY.md) · [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)

---

## Testing

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Unit test target | ❌ | No `Tests/` dir exists; `swift test` reports no tests. Scoring/serve state machine is unverified by automation. | Add a `Tests/PickleTrackTests` target; unit-test `ActiveMatchManager` (side-out, first-serve rule, win-by-2, undo/`PointEvent` rollback). |
| UI tests | ❌ | Regressions in key screens go uncaught. | Add XCUITest smoke tests for Home → New Match → Active Match → History. |
| Snapshot/golden tests | ❌ | Layout regressions (iOS + macOS) undetected. | Add snapshot tests for the scoreboard and stats views. |

## CI / build automation

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| CI pipeline | ❌ | No automated build/test on push/PR. | Add GitHub Actions: Linux `swift:slim` compile-check + a macOS `xcodebuild build test` job. |
| Linux compile-check | 🟡 | `Dockerfile` exists (swift:slim) but isn't wired into CI. | Reference the Dockerfile in a CI workflow (see [Dockerfile](Dockerfile)). |
| Release automation | ❌ | Manual archive/upload. | Automate archive → export → TestFlight with secrets from a manager (see [deployments/AWS.md](deployments/AWS.md)). |

## Distribution

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Signing & provisioning | ❌ | No distribution cert/profile set up; cannot ship. | Enroll in Apple Developer Program; create distribution cert + profile; back them up (see [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)). |
| App Store Connect record | ❌ | No app record / bundle id registered. | Register the bundle id and create the App Store Connect app. |
| App Store Connect API key | ❌ | No automated upload identity. | Generate a `.p8` key; store in a secrets manager. |
| Xcode project vs SPM | 🟡 | App entry is `main.swift` + SwiftPM; README documents manual Xcode project creation. | Decide on a committed `.xcodeproj`/workspace or an Xcode-openable package flow to standardize builds. |

## Persistence & data

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| On-device only storage | ✅ (by design) | Data lives solely in SwiftData on the device. | Documented; acceptable for v1. |
| Cloud backup / sync | ❌ | App deletion or device loss (without iCloud device backup) loses all match data; no cross-device sync. | Evaluate **CloudKit** mirroring for SwiftData (opt-in iCloud sync) in a later phase. |
| Migration strategy | 🟡 | Relies on SwiftData lightweight migration; destructive model changes need handling. | Document/verify a migration plan before shipping schema-changing updates. |

## Privacy & compliance

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Privacy Manifest | ❌ | Required for App Store submission. | Author `PrivacyInfo.xcprivacy` (location = app functionality, no tracking). |
| App Privacy details | ❌ | App Store "nutrition label" not filled. | Complete App Privacy in App Store Connect. |
| Location minimalism | ✅ | Only when-in-use location requested. | Keep least-privilege; no background location. |

## Apple Watch companion

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Watch app | ❌ (roadmap Phase 2) | Advertised roadmap item; not built. | Build a watchOS target sharing the scoring model once Phase 1 hardens. |

## Accessibility

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| VoiceOver / Dynamic Type | 🟡 | SwiftUI gives baseline support; not audited. | Audit labels/traits on scoreboard controls; verify Dynamic Type scaling. |
| Color contrast | 🟡 | Not verified for the stats/scoreboard palette. | Check WCAG contrast; support high-contrast / reduce-motion. |

## Localization

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| String localization | ❌ | UI strings are hard-coded English. | Extract to `String Catalog` (`.xcstrings`) for future locales. |

## Observability & operations

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Crash reporting | ❌ | No visibility into field crashes. | Adopt Xcode Organizer crash logs (via TestFlight/App Store) or a privacy-respecting reporter. |
| Structured logging | 🟡 | Ad-hoc `print`/`os_log`. | Standardize on `os.Logger` categories; ensure no PII and no debug logging in Release. |
| Phased release | ❌ | No staged rollout configured. | Enable App Store phased release for updates. |

## Documentation

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Standard doc set | ✅ | `deployments/` ×6, `docs/` ×4, README, OPEN_ITEMS, CLAUDE.md, Dockerfile, render.yaml present. | Keep current as the app changes (standing rule in [CLAUDE.md](CLAUDE.md)). |
