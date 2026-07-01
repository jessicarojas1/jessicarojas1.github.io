# CLAUDE.md — PickleTrack

Project guidance for working on **PickleTrack**. Read this before making changes.

## What it is

A native **SwiftUI pickleball scorekeeper and stats app** for **iOS 17+** and **macOS 14+**.
Live scoring with full side-out serving logic, exact undo via a point-event audit log, match
history, a stats dashboard, a MapKit-powered court finder, and a built-in rules reference.
Everything is **on-device** — no server, no accounts, no data leaves the device except an
anonymous Apple Maps court search.

## Stack & conventions

- **Swift Package Manager**, `swift-tools-version: 5.9`. Single **`.executableTarget`** named
  `PickleTrack`. Platforms `.iOS(.v17)`, `.macOS(.v14)`.
- **No external SwiftPM dependencies** — Apple frameworks only (**SwiftUI, SwiftData, MapKit,
  CoreLocation**). Do not add third-party packages without strong justification; it keeps
  supply-chain risk and air-gap effort near zero.
- The target enables the experimental **`StrictConcurrency`** feature. Keep the build clean:
  fix actor-isolation / `Sendable` issues rather than disabling the flag.
- **Entry point:** `main.swift` calls `PickleTrackApp.main()`. **Do not add `@main`** to
  `PickleTrackApp.swift` — the executable target requires the `main.swift` entry for SPM.
- **Managers are `@Observable`** classes: `ActiveMatchManager`, `CourtSearchManager`,
  `LocationManager`. Owned by `PickleTrackApp` alongside the `ModelContainer`.
- **Models are SwiftData `@Model`**: `PBMatch`, `PBGame`; plus a `PointEvent` audit log.
  Persistence: `ModelContainer(for: PBMatch.self, PBGame.self)`.
- **Scoring lives in `ActiveMatchManager`** — the serve state machine (first-serve rule,
  server rotation, side-out, win-by-2) and undo (pop the last `PointEvent`, restore score +
  serve state). Preserve the audit-log-driven undo; don't recompute serve state ad hoc.
- **`Database::update()` and web/CSP rules from the repo-root CLAUDE.md do not apply here** —
  this is a native app, not the PHP/web platform. The applicable rules are the on-device
  iOS security/privacy ones below.

## Where things live

```
Sources/PickleTrack/
├── PickleTrackApp.swift     App scene + managers + ModelContainer
├── main.swift               PickleTrackApp.main()
├── Models/MatchModels.swift PBMatch + PBGame @Model, PointEvent
├── Managers/                ActiveMatchManager, CourtSearchManager, LocationManager
└── Views/                   ContentView, HomeView, RulesView,
                             MatchViews/, StatsViews/, CourtViews/
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full layout and data flow.

## Build / test

```bash
swift build      # compiles the package; on Linux this is a COMPILE CHECK ONLY
swift test       # no Tests/ target yet → "no tests"
# Full app (macOS + Xcode 15+):
xcodebuild -scheme PickleTrack -destination 'platform=iOS Simulator,name=iPhone 15' build
```

> Apple frameworks do not exist on Linux — `swift build`/`swift test` on Linux (see
> [Dockerfile](Dockerfile)) only validates platform-agnostic Swift + package resolution. A
> runnable iOS/macOS app requires **Xcode/`xcodebuild` on macOS**.

Required Info.plist key: `NSLocationWhenInUseUsageDescription` (court finder).

## Distribute

Build → sign → distribute: Simulator, TestFlight, App Store, Ad-Hoc, enterprise/MDM. Exact
commands in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Signing assets + App Store Connect API
key are stored in a secrets manager and fetched via a short-lived role/managed identity —
never committed (see [deployments/AWS.md](deployments/AWS.md) / [deployments/AZURE.md](deployments/AZURE.md)).

## Applicable on-device security / privacy rules

- No secrets in the app bundle; no hardcoded credentials.
- Least-privilege permissions — **only** when-in-use location, used solely for the court finder.
- No tracking/analytics; no PII leaves the device. SwiftData store under iOS Data Protection;
  Keychain for any future secrets.
- ATS/TLS enforced for the single MapKit network call — no ATS exceptions.
- Author the Privacy Manifest + App Privacy details before App Store submission.

## Standing rule — keep the doc set current

This project ships the standard documentation + deployment set and it **must be kept accurate
as the app changes**, in the same change that alters behavior:

- `deployments/` ×6 — LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED
- `docs/` ×4 — ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
- `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`

Where a server/cloud target genuinely doesn't apply, state **Applicability: N/A** at the top
and document the real mobile equivalent — never fabricate infrastructure, commands, env vars,
or paths.
