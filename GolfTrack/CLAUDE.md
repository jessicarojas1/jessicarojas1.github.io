# CLAUDE.md — GolfTrack Project Guidance

Guidance for working on **GolfTrack** inside the `jessicarojas1.github.io`
portfolio repo. Read this before making changes.

## What it is

GolfTrack is an **on-device** golf scorecard, handicap tracker, and stats app:
SwiftUI + SwiftData, **iOS 17+ / macOS 14+**, with an **Apple Watch** companion
(watchOS 10+) and a **Garmin Connect IQ** companion (Monkey C). There is **no
backend, no server, no accounts, and no cloud database** — all state is local.

## Stack

| Area | Choice |
|------|--------|
| Language / tooling | Swift 5.9, Swift Package Manager (`swift-tools-version: 5.9`) |
| UI | SwiftUI (one codebase, iOS/macOS/watchOS) |
| Persistence | SwiftData (`@Model`, `ModelContainer(for: Round, HoleScore, CustomCourse)`) |
| Platform frameworks | MapKit (`MKLocalSearch`), CoreLocation, MediaPlayer, WatchConnectivity |
| Watch | `Sources/GolfTrackWatch/` — watchOS `@main` |
| Garmin | `GarminApp/GolfTrack.mc` — Monkey C, Connect IQ SDK 4.x, device CIQ 3.4+ |
| External deps | **NONE** — Apple frameworks only (`Package.swift` has zero dependencies) |

## Conventions (follow these)

- **Entry point:** `Sources/GolfTrack/main.swift` calls `GolfTrackApp.main()`.
  `GolfTrackApp.swift` is a `struct ... : App`. **Do NOT add `@main`** to it — the
  explicit `main.swift` is required for SwiftPM executable-target compatibility.
- **No external dependencies.** Keep it that way; do not add SwiftPM packages
  without strong justification (it breaks the trivial air-gapped story).
- **Managers are `@Observable` classes** (`ActiveRoundManager`,
  `HandicapCalculator`, `LocationManager`, `CourseSearchManager`, `MusicManager`,
  `WatchConnectivityManager`). Views observe them.
- **Persistence via SwiftData `@Model`** (`Round`, `HoleScore`, `CustomCourse`).
  Do not introduce a separate DB or file format.
- **Single SwiftPM target.** `Sources/GolfTrackWatch/` and `GarminApp/` are added
  as separate targets **in Xcode**, not in `Package.swift`.
- **Device message contract** is a plain string-keyed dictionary
  (`holeNumber`, `par`, `yardage`, `strokes`, `putts`, `scoreVsPar`,
  `totalStrokes`, `courseName`, `action`). Inbound actions: `nextHole`,
  `prevHole`, `scoreUpdate`, `puttUpdate`. Switch on known actions; ignore
  unknown ones. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) §7.

## Where things live

```
Package.swift              swift-tools:5.9; iOS17/macOS14; 1 executable target
Sources/GolfTrack/         main.swift, GolfTrackApp.swift, Models/ Data/
                           Managers/ Watch/ Music/ Views/
Sources/GolfTrackWatch/    watchOS @main companion (added in Xcode)
GarminApp/GolfTrack.mc     Garmin Connect IQ companion (Monkey C)
deployments/ ×6            operator guides (per target)
docs/ ×4                   ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
Dockerfile                 Linux SwiftPM compile-check image (CI only)
render.yaml                Applicability N/A header (not a Render workload)
README.md OPEN_ITEMS.md    overview + production-readiness register
```

## Build / test

```bash
swift build            # package resolve + compile-check (Linux = gate only)
swift test             # NO Tests/ target yet → "no tests" is expected
```
> Linux SwiftPM is a **compile/package check only** — Apple frameworks don't
> exist on Linux. Real app/device builds require **Xcode + xcodebuild on macOS**:
```bash
xcodebuild -scheme GolfTrack -destination 'platform=iOS Simulator,name=iPhone 15' build
xcodebuild -scheme GolfTrack -destination 'generic/platform=iOS' -archivePath build/GolfTrack.xcarchive archive
```

## Distribute

Build → sign → distribute: Simulator → TestFlight → App Store (phased release) /
Ad-Hoc / enterprise MDM (Intune/Jamf); the watchOS app ships bundled with the iOS
app; Garmin ships to the Connect IQ store. Full flow:
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Signing/secret custody:
[deployments/AWS.md](deployments/AWS.md), [deployments/AZURE.md](deployments/AZURE.md).

## Security / privacy rules that apply (on-device iOS app)

- **No accounts/auth** in the app; "identity" = developer signing identity.
  Protect signing keys; enforce App Store Connect 2FA + least-privilege roles.
- **No secrets in the bundle.** If secrets are ever added, use the **Keychain**.
- **Least-privilege permissions:** location **when-in-use** only; Apple Music
  usage; `LSApplicationQueriesSchemes` limited to the 4 music apps.
- **ATS/TLS default** — no cleartext exceptions. Only MapKit + music URL schemes
  touch the network.
- **No PII leaves the device;** no analytics/tracking. Author a privacy manifest
  before submission (see [OPEN_ITEMS.md](OPEN_ITEMS.md)).
- Treat inbound Watch/Garmin messages as untrusted (switch on known actions).

## STANDING RULE — keep the doc set current

This project ships the standard documentation + deployment set and it **must be
kept accurate as the app changes**:

- `deployments/` ×6: LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE,
  AWS, AIRGAPPED.
- `docs/` ×4: ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY.
- Root: `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`.

Whenever a feature, framework, permission, or distribution change lands, update
the affected files in the **same** change. Do not invent commands, env vars,
ports, or paths — verify against the real code. Do not fabricate cloud infra for
this on-device app; where a target doesn't apply, state **Applicability: N/A** and
document the real mobile equivalent.
