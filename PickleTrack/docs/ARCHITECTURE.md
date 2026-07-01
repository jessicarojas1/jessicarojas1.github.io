# PickleTrack — Architecture

How PickleTrack is structured: a single-target SwiftUI + SwiftData application that runs
entirely on-device, with the only external I/O being Apple MapKit's local court search.

Related: [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [../README.md](../README.md)

---

## 1. Platform & principles

- **Platform:** Apple, native. **SwiftUI** UI, **SwiftData** persistence, **MapKit** +
  **CoreLocation** for the court finder. No third-party frameworks.
- **Targets:** iOS 17+ and macOS 14+ (`.iOS(.v17)`, `.macOS(.v14)` in `Package.swift`).
- **Build system:** Swift Package Manager, `swift-tools-version: 5.9`, a **single
  `.executableTarget`** named `PickleTrack`.
- **Concurrency:** the target enables the experimental **`StrictConcurrency`** feature
  (`.enableExperimentalFeature("StrictConcurrency")`), so data-race safety is enforced at
  compile time — actor isolation and `Sendable` conformance are checked strictly.
- **Design principles:**
  - **On-device first** — no accounts, no server, no network except MapKit search.
  - **Observation-driven** — managers are `@Observable`; views react to state.
  - **Auditable scoring** — every point is recorded as a `PointEvent` so undo is exact.
  - **No external dependencies** — reduces supply-chain risk and makes air-gap trivial.

---

## 2. Component overview

| Component | Type | Responsibility |
|-----------|------|----------------|
| `PickleTrackApp` | `struct: App` | Root scene; owns the managers + `ModelContainer` |
| `main.swift` | entry point | Calls `PickleTrackApp.main()` (not `@main`, for SPM) |
| `ActiveMatchManager` | `@Observable` | Serve state machine + scoring + undo |
| `CourtSearchManager` | `@Observable` | `MKLocalSearch` for nearby courts |
| `LocationManager` | `@Observable` | `CLLocationManager` wrapper / authorization |
| `PBMatch`, `PBGame` | `@Model` | Persisted match + game records |
| `PointEvent` | value type | Per-point audit log entry (drives undo) |
| Views | SwiftUI | Home, New Match, Active Match, History, Stats, Courts, Rules |

---

## 3. Monorepo placement & internal layout

PickleTrack is a subfolder of the portfolio repo
`github.com/jessicarojas1/jessicarojas1.github.io`. Its internal layout:

```
PickleTrack/
├── Package.swift                     swift-tools-version 5.9; single executable target
├── README.md                         features, setup, serving rules, structure, roadmap
├── Dockerfile                        Linux swift:slim CI compile-check image (not an app build)
├── render.yaml                       Applicability: N/A (on-device app, not a Render service)
├── deployments/                      LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES,
│                                     AZURE, AWS, AIRGAPPED
├── docs/                             ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
├── OPEN_ITEMS.md                     production-readiness register
├── CLAUDE.md                         project guidance for this app
└── Sources/PickleTrack/
    ├── PickleTrackApp.swift          App scene + managers + ModelContainer
    ├── main.swift                    PickleTrackApp.main()
    ├── Models/
    │   └── MatchModels.swift         PBMatch + PBGame @Model, PointEvent audit log
    ├── Managers/
    │   ├── ActiveMatchManager.swift  @Observable serve state machine + scoring
    │   ├── CourtSearchManager.swift  MKLocalSearch
    │   └── LocationManager.swift     CLLocationManager wrapper
    └── Views/
        ├── ContentView.swift         TabView (iOS) / NavigationSplitView (macOS)
        ├── HomeView.swift            Win-rate hero + new match
        ├── RulesView.swift           Rules reference (collapsible sections)
        ├── MatchViews/
        │   ├── NewMatchSetupView.swift
        │   ├── ActiveMatchView.swift
        │   └── MatchHistoryView.swift
        ├── StatsViews/
        │   └── StatsView.swift
        └── CourtViews/
            └── NearbyCourtView.swift
```

---

## 4. Data model

Persistence is **SwiftData**, initialized in `PickleTrackApp` as:

```swift
ModelContainer(for: PBMatch.self, PBGame.self)
```

| Entity | Kind | Holds |
|--------|------|-------|
| `PBMatch` | `@Model` | Match metadata: format (singles/doubles), target (11/15/21), best-of (1/3/5), player/team names, result, timestamps; owns its `PBGame`s |
| `PBGame` | `@Model` | Per-game scores and winner within a match |
| `PointEvent` | audit log | Ordered record of each scored point + serve state — the source of truth for **undo** |

The `PointEvent` log makes undo deterministic: reversing the last point restores both the
score **and** the exact serve state (server number, serving side) that preceded it, rather
than recomputing.

---

## 5. Serve state machine & scoring rules

`ActiveMatchManager` (`@Observable`) is the core state machine. It implements pickleball
side-out serving:

- **First-serve rule** — the first serving team of each game starts with only one server
  (treated as "server 2") to remove the double-server advantage.
- **Server rotation (doubles)** — Server 1 → Server 2 → **side-out**; a server keeps
  serving until their team loses a rally.
- **Singles** — standard side-out; the serving player keeps serving until losing a rally.
- **Win by 2** — every game length (11/15/21) requires a 2-point margin.
- **Score announcement** — an auto-generated `serving–receiving–server#` string is derived
  from current state and shown before each serve.
- **Undo** — pops the last `PointEvent`, restoring score and serve state exactly.

State transitions are driven by point-button actions in `ActiveMatchView`; each transition
appends a `PointEvent`, and completing games/matches writes `PBGame`/`PBMatch` via SwiftData.

---

## 6. Configuration model

There is **no server configuration and no runtime environment variables**. Configuration is
bundle/compile-time:

| Configuration | Where | Purpose |
|---------------|-------|---------|
| `NSLocationWhenInUseUsageDescription` | Info.plist | Required — court finder location prompt |
| Deployment targets | `Package.swift` | iOS 17 / macOS 14 |
| Bundle Identifier + signing | Xcode project | Identity / distribution |
| SwiftData schema | `ModelContainer(for:)` | Declares persisted models |
| `StrictConcurrency` flag | `Package.swift` swiftSettings | Compile-time data-race checks |

---

## 7. On-device data flow & "request/error" contract

There is **no network API and no request/response envelope** — reframed as on-device flow:

```
User tap ─▶ View ─▶ ActiveMatchManager (mutate serve/score, append PointEvent)
                          │
                          ▼
                  SwiftData ModelContext ─▶ persist PBMatch/PBGame
                          ▲
                          │
   Views observe @Observable/@Query state and re-render
```

**Only external call:** the court finder issues an `MKLocalSearch` request via
`CourtSearchManager`, gated by `LocationManager` authorization. Error handling there:

| Condition | Behavior |
|-----------|----------|
| Location denied / restricted | Court finder degrades; prompts to enable; rest of app unaffected |
| `MKLocalSearch` returns no results / errors | Empty-state list; user can retry |
| Offline / air-gapped | Search yields nothing (MapKit needs connectivity); other features fully work |

All other operations are synchronous local model mutations — they cannot "fail" over a
network; SwiftData persistence errors surface as local errors, not HTTP codes.

---

## 8. Security model

- **No accounts, no authentication, no authorization** — there are no users or roles.
  "Identity" in this project means the **developer code-signing identity**, not an app login.
- **All data stays on-device** in the SwiftData store, protected by iOS Data Protection and
  the app sandbox. No PII or analytics leaves the device.
- **Least-privilege permissions** — only `NSLocationWhenInUseUsageDescription`
  (when-in-use location), used solely for the court finder.
- **Transport** — the single network path (MapKit) uses Apple's TLS-secured services under
  App Transport Security defaults.

Full detail in [SECURITY.md](SECURITY.md).

---

## 9. Observability

There is **no server telemetry** and nothing to scrape. On-device observability:

| Signal | Tool |
|--------|------|
| Logs | `os_log` / `print` to **Console.app** during development |
| Performance / memory / leaks | **Instruments** (Time Profiler, Allocations, Leaks) |
| Concurrency issues | Thread Sanitizer + `StrictConcurrency` compile diagnostics |
| Crashes | Xcode Organizer crash logs / (future) a crash reporter — see [../OPEN_ITEMS.md](../OPEN_ITEMS.md) |
| SwiftData | Enable SwiftData/CoreData debug logging when diagnosing persistence |

No metrics endpoint, no distributed tracing, no health check — none apply to an on-device app.

---

## 10. Deployment topology

Build and distribution only (the app runs on the user's device, not on infrastructure):

```
Xcode/xcodebuild (macOS) ─▶ .xcarchive ─▶ export .ipa ─▶ TestFlight / App Store / MDM
        ▲
   (optional) Linux swift:slim compile-check gate in CI
```

See [DEPLOYMENT.md](DEPLOYMENT.md) and the [../deployments/](../deployments) target guides.
