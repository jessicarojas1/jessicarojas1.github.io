# PickleTrack

A native SwiftUI pickleball scorekeeper and stats app for **iOS 17+** and **macOS 14+**.

> **Build status:** Phase 1 feature-complete. No CI configured yet and no `Tests/` target exists
> (`swift test` reports "no tests"). A Linux SwiftPM compile-check image is provided in
> [`Dockerfile`](Dockerfile). Real app builds require Xcode/`xcodebuild` on macOS.

## Documentation & Deployment

Full operator-grade documentation and deployment guides live alongside this app:

**docs/**
- [Architecture](docs/ARCHITECTURE.md) — SwiftPM layout, data model, serve state machine, on-device data flow
- [Deployment](docs/DEPLOYMENT.md) — build → sign → distribute (Simulator, TestFlight, App Store, Ad-Hoc, MDM)
- [Disaster Recovery](docs/DISASTER_RECOVERY.md) — git + signing-asset backup/restore runbook
- [Security](docs/SECURITY.md) — on-device data protection, permissions, signing identity, reporting

**deployments/**
- [Local Development](deployments/LOCAL_DEVELOPMENT.md) — Xcode + Simulator, SwiftPM compile-check caveats
- [Single Linux Server](deployments/SINGLE_LINUX_SERVER.md) — N/A for on-device app; CI/build host equivalent
- [Kubernetes](deployments/KUBERNETES.md) — N/A for the app; k8s-based compile-check pipeline
- [Azure](deployments/AZURE.md) — build pipeline + Key Vault + Intune (Commercial + Government)
- [AWS](deployments/AWS.md) — build pipeline + Secrets Manager + distribution (Commercial + GovCloud)
- [Air-Gapped](deployments/AIRGAPPED.md) — offline build + internal MDM (no external deps)

See also: [OPEN_ITEMS.md](OPEN_ITEMS.md) (production-readiness register) · [CLAUDE.md](CLAUDE.md) (project guidance).

## Technology

- **Language / build:** Swift 5.9, Swift Package Manager (`swift-tools-version: 5.9`), single `.executableTarget`
- **Platforms:** iOS 17+, macOS 14+
- **Frameworks (Apple only):** SwiftUI, SwiftData (`ModelContainer(for: PBMatch.self, PBGame.self)`), MapKit (`MKLocalSearch`), CoreLocation (`CLLocationManager`)
- **Concurrency:** experimental **`StrictConcurrency`** feature enabled in `Package.swift` (compile-time data-race checks)
- **Dependencies:** **none** — no external SwiftPM packages; Apple frameworks only (trivial air-gap, minimal supply-chain risk)

## Prerequisites

- macOS 14+ with **Xcode 15+** (bundles the Swift 5.9 toolchain + iOS 17 SDK/Simulator)
- Apple Developer account (free for Simulator/personal device; paid for TestFlight/App Store)
- A signing **Team** + Bundle Identifier for on-device/distribution builds
- (optional) Docker + `swift:slim` for the Linux compile-check ([`Dockerfile`](Dockerfile))

## Common Commands

```bash
swift build       # compile the package (Linux = compile-check only; app needs macOS/Xcode)
swift test        # no Tests/ target yet → "no tests"

# Full app build for a Simulator (macOS + Xcode)
xcodebuild -scheme PickleTrack -destination 'platform=iOS Simulator,name=iPhone 15' build

# Archive + export a signed .ipa (see docs/DEPLOYMENT.md)
xcodebuild -scheme PickleTrack -destination 'generic/platform=iOS' \
  -archivePath build/PickleTrack.xcarchive archive
```

## Features

- **Live Scoring** — tap to score points with full side-out serving logic (first-serve rule, server rotation in doubles)
- **Score Announcement** — displays the correct "serving–receiving–server#" call before each serve
- **Undo** — one tap to reverse the last point with full serve-state rollback
- **Match History** — full game-by-game breakdown for every completed match
- **Stats Dashboard** — win rate, win streak, avg points for/against, last 10 results bar chart
- **Find Courts** — GPS-powered MapKit search for nearby pickleball courts with distance, address, and phone
- **Rules Reference** — built-in rules guide covering scoring, serving, the kitchen (NVZ), faults, and match formats
- **Singles & Doubles** — supports both formats with per-player and partnership names
- **Flexible format** — to 11, 15, or 21; best of 1, 3, or 5
- **SwiftData persistence** — everything saved locally

## Revenue Model

| Tier    | Price    | Features |
|---------|----------|----------|
| Free    | $0       | Basic scoring, last 5 matches |
| Pro     | $3.99/mo | Unlimited history, full stats, court finder |
| Premium | $7.99/mo | Tournament bracket, team leaderboards, share scorecards |

**Market**: 36M+ pickleball players in the US — fastest growing sport. App Store has almost no polished options.

---

## Getting Started

### 1. Pull the source code

The source lives inside the portfolio repo. Clone it and navigate to the PickleTrack folder:

```bash
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io/PickleTrack
```

Or use Git sparse checkout to pull only this folder:

```bash
git clone --filter=blob:none --sparse https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io
git sparse-checkout set PickleTrack
cd PickleTrack
```

### 2. Open in Xcode

1. Open **Xcode 15+** on your Mac
2. **File → New → Project → Multiplatform → App**
3. Name: **PickleTrack**, Targets: **iOS 17** and **macOS 14**
4. Delete the generated `ContentView.swift`
5. Drag all files from `Sources/PickleTrack/` into the project
6. Set your **Team** and **Bundle Identifier** under Signing & Capabilities
7. Add `NSLocationWhenInUseUsageDescription` to Info.plist (see below)
8. **⌘R** to build and run

> **Note:** The app uses `main.swift` as its entry point (not `@main`) for SPM compatibility. Do not add `@main` to `PickleTrackApp.swift`.

### 3. Required Info.plist Key

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>PickleTrack uses your location to find nearby pickleball courts.</string>
```

---

## Serving Rules Implemented

- **First-serve rule**: The first team to serve at the start of each game begins with only one server (server 2), preventing an unfair double-server advantage.
- **Doubles rotation**: Server 1 → Server 2 → side-out. Each server keeps serving until their team loses a rally.
- **Singles**: Standard side-out — serving player keeps serving until they lose the rally.
- **Win by 2**: All game lengths (11/15/21) require winning by 2 points.
- **Score announcement**: Auto-generated "serving–receiving–server#" string displayed before every serve.
- **Undo**: Rolls back the last point AND restores the correct serve state from the point log.

---

## Project Structure

```
Sources/PickleTrack/
├── PickleTrackApp.swift             App entry + SwiftData container
├── main.swift                       Entry point (calls PickleTrackApp.main())
├── Models/
│   └── MatchModels.swift            PBMatch + PBGame @Model, PointEvent audit log
├── Managers/
│   ├── ActiveMatchManager.swift     @Observable serve state machine + scoring logic
│   ├── CourtSearchManager.swift     MKLocalSearch for pickleball courts
│   └── LocationManager.swift        CLLocationManager wrapper
└── Views/
    ├── ContentView.swift            TabView (iOS) / NavigationSplitView (macOS)
    ├── HomeView.swift               Win rate hero card + new match button
    ├── RulesView.swift              Built-in rules reference (6 collapsible sections)
    ├── MatchViews/
    │   ├── NewMatchSetupView.swift  Format, game length, player/team names
    │   ├── ActiveMatchView.swift    Live scoreboard + point buttons + undo
    │   └── MatchHistoryView.swift   History list + game-by-game detail view
    ├── StatsViews/
    │   └── StatsView.swift          8-metric grid + W/L bar chart + recent list
    └── CourtViews/
        └── NearbyCourtView.swift    Map + results list + detail sheet (Open in Maps)
```

## Phase Roadmap

- **Phase 1 (done)**: Live scoring, match history, stats, court finder, rules reference
- **Phase 2**: Apple Watch companion (score from wrist, navigate games)
- **Phase 3**: Tournament bracket mode
- **Phase 4**: Team leaderboards + share scorecards
- **Phase 5**: Rating tracker (DUPR integration)
