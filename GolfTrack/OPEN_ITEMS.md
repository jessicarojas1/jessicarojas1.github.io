# Open Items — GolfTrack (Production-Readiness Register)

Honest status of what is done vs outstanding for shipping GolfTrack to the App
Store / TestFlight / MDM and the Garmin Connect IQ store. Grouped by theme; each
item has **status**, **impact**, and a **suggested action**.

Legend: ✅ done · 🟡 partial · ⛔ outstanding

Cross-links: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) ·
[docs/SECURITY.md](docs/SECURITY.md) · [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md).

---

## Testing
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Unit tests | ⛔ | No `Tests/` target exists; `swift test` reports no tests. Regressions in handicap/scoring logic go uncaught. | Add a `Tests/GolfTrackTests` target; unit-test `HandicapCalculator` (WHS), score-vs-par, club recommendation — these are platform-agnostic and can run in Linux CI. |
| UI / snapshot tests | ⛔ | No coverage of SwiftUI screens. | Add XCUITest / snapshot tests on macOS runner for Home, Active Round, Stats. |
| Device sync tests | ⛔ | WatchConnectivity/Garmin message contract untested. | Add tests around the dictionary encode/decode + action handling. |

## CI / CD
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Linux compile-check image | ✅ | `Dockerfile` provides a SwiftPM gate. | Keep pinned to Swift 5.9. |
| CI pipeline definition | ⛔ | No GitHub Actions/Azure/AWS pipeline wired yet. | Add a pipeline: Linux compile-check Job + macOS archive stage (see deployments/AWS.md, AZURE.md). |
| Automated version bump | ⛔ | Manual build numbers risk collisions. | Automate CFBundleVersion bump in CI. |

## Distribution & signing
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Signing certs / provisioning | ⛔ | Not yet set up; cannot archive/distribute. | Create distribution cert + profiles; store in a secrets manager (deployments/AWS.md / AZURE.md). |
| App Store Connect record | ⛔ | No App Store record / bundle ID reserved. | Register the app + bundle ID in App Store Connect. |
| App Store Connect API key | ⛔ | No automated upload path. | Generate `.p8`, store as a secret, least-privilege role. |
| Garmin Connect IQ submission | 🟡 | Companion code exists; not built/submitted. | Build `.iq` with CIQ SDK 4.x; submit to the store. |
| ExportOptions.plist | ⛔ | Export method not templated. | Commit a secret-free `ExportOptions.plist` template. |

## Persistence & sync
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| On-device SwiftData | ✅ | Rounds/scores/custom courses persist locally. | — |
| Cloud backup / sync | ⛔ | User data is per-device; no cloud backup unless the user has iCloud/Finder device backup. Data lost on device loss/reset. | Implement CloudKit sync (roadmap Phase 4); document the limitation meanwhile (see DISASTER_RECOVERY). |
| SwiftData migration strategy | 🟡 | Model changes could break existing stores. | Define versioned schema migrations before shipping model changes. |

## Watch / Garmin integration
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| watchOS companion code | ✅ | Present in `Sources/GolfTrackWatch/`. | — |
| Xcode target wiring | 🟡 | Watch + Garmin targets are added manually in Xcode (not in Package.swift). | Document/automate the Xcode project setup; consider committing an `.xcodeproj`/workspace. |
| Message contract robustness | 🟡 | Unknown actions are ignored (good) but untested. | Add tests; validate payload bounds. |

## Privacy & compliance
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Privacy manifest (`PrivacyInfo.xcprivacy`) | ⛔ | Apple now requires a privacy manifest; submission may be flagged. | Author `PrivacyInfo.xcprivacy` (location, no tracking, no data collection). |
| App Privacy details (App Store) | ⛔ | Required on the App Store listing. | Complete App Privacy questionnaire — declare on-device-only, no data collected. |
| Permission strings | ✅ | Location + Apple Music usage strings defined. | Keep least-privilege (when-in-use). |

## Hardening & operations
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Crash reporting | ⛔ | Only Xcode Organizer crash logs today. | Add a crash reporter or rely on Organizer + document it. |
| Debug logging in Release | 🟡 | Ensure no PII / verbose logs in Release. | Gate logging behind `#if DEBUG`. |
| Signing-asset backup / DR drills | ⛔ | Loss of signing identity = major recovery cost. | Implement backups + quarterly restore drill (DISASTER_RECOVERY §5). |

## Accessibility
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| VoiceOver / Dynamic Type | 🟡 | SwiftUI gives baseline support; not audited. | Audit labels, contrast, Dynamic Type on all screens. |

## Localization
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Strings localization | ⛔ | English-only; hard-coded strings. | Extract to `String Catalog`; localize key markets. |

## Documentation
| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Standard doc set | ✅ | `deployments/` ×6, `docs/` ×4, README, this file, CLAUDE.md, Dockerfile, render.yaml present. | Keep current as the app changes (CLAUDE.md standing rule). |
