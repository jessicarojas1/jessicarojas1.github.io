# Architecture — GolfTrack

GolfTrack is an **on-device** golf scorecard, handicap tracker, and stats app
built with **SwiftUI + SwiftData**, targeting **iOS 17+ / macOS 14+**, with an
**Apple Watch** companion (watchOS 10+) and a **Garmin Connect IQ** companion
(Monkey C). There is **no server, no backend API, and no cloud database** — all
persistence is local.

Cross-links: [DEPLOYMENT](DEPLOYMENT.md) · [SECURITY](SECURITY.md) ·
[DISASTER_RECOVERY](DISASTER_RECOVERY.md) · deployment guides in
[`../deployments/`](../deployments/).

---

## 1. Platform & design principles

| Principle | Implementation |
|-----------|----------------|
| On-device first | SwiftData store in the app sandbox; no accounts, no network backend |
| Native UI | SwiftUI, one codebase across iOS/macOS/watchOS |
| Observable state | `@Observable` manager classes drive views |
| Loose device coupling | WatchConnectivity + Garmin messaging via plain dictionaries |
| Zero external deps | Only Apple frameworks — nothing to vendor/patch |
| SPM-compatible entry | `main.swift` calls `GolfTrackApp.main()` (not `@main`) |

## 2. Component overview

| Layer | Files | Responsibility |
|-------|-------|----------------|
| Entry | `main.swift`, `GolfTrackApp.swift` | App bootstrap + SwiftData `ModelContainer` |
| Models (`Models/`) | `Course.swift`, `RoundModels.swift`, `ClubProfile.swift` | `@Model` entities + value types |
| Data (`Data/`) | `CourseLibrary.swift` | 5 built-in courses with hole data |
| Managers (`Managers/`) | `ActiveRoundManager`, `HandicapCalculator`, `LocationManager`, `CourseSearchManager` | `@Observable` domain logic |
| Watch bridge (`Watch/`) | `WatchConnectivityManager.swift` | iOS-side `WCSession` send/receive + Garmin message shape |
| Music (`Music/`) | `MusicManager.swift` | `MPMusicPlayerController` + music-app URL schemes |
| Views (`Views/`) | Home, ActiveRound, Stats, Course, Rules, Settings | SwiftUI screens |
| Watch app (`Sources/GolfTrackWatch/`) | `GolfTrackWatchApp`, `WatchSessionManager`, `WatchContentView`, `WatchActiveRoundView` | watchOS `@main` companion |
| Garmin (`GarminApp/`) | `GolfTrack.mc` | Connect IQ companion (Monkey C) |

## 3. Monorepo placement & internal layout

GolfTrack lives as the `GolfTrack/` subfolder of the portfolio repo
`github.com/jessicarojas1/jessicarojas1.github.io`.

```
GolfTrack/
├── Package.swift                 # swift-tools-version:5.9; iOS17/macOS14; 1 executable target
├── Sources/
│   ├── GolfTrack/                # the SwiftPM target (iOS/macOS app)
│   │   ├── main.swift            # entry: GolfTrackApp.main()
│   │   ├── GolfTrackApp.swift    # struct : App + ModelContainer
│   │   ├── Models/  Data/  Managers/  Watch/  Music/  Views/
│   └── GolfTrackWatch/           # watchOS @main companion (NOT in the SwiftPM target)
├── GarminApp/GolfTrack.mc        # Garmin Connect IQ (Monkey C)
├── deployments/  docs/           # this doc set
├── Dockerfile  render.yaml
└── README.md  OPEN_ITEMS.md  CLAUDE.md
```

> **Target boundary:** `Package.swift` declares a **single** executable target
> (`Sources/GolfTrack`). `Sources/GolfTrackWatch/` and `GarminApp/` are **not**
> part of the SwiftPM target — they are added as separate watchOS / Connect IQ
> targets when the package is imported into Xcode (see
> [DEPLOYMENT](DEPLOYMENT.md)).

## 4. Targets & platforms

| Target | Platform(s) | Entry | Build tool |
|--------|-------------|-------|-----------|
| `GolfTrack` (SwiftPM) | iOS 17, macOS 14 | `main.swift` → `GolfTrackApp.main()` | Xcode / `swift build` (Linux = compile check) |
| GolfTrackWatch | watchOS 10 | `@main` (`GolfTrackWatchApp`) | Xcode (added in Xcode) |
| Garmin companion | Connect IQ 3.4+ (device); SDK 4.x | Monkey C `App` | Connect IQ SDK |

## 5. Apple frameworks used

| Framework | Where | Use |
|-----------|-------|-----|
| SwiftUI | Views | All UI |
| SwiftData | Models, `GolfTrackApp` | Local persistence (`@Model`, `ModelContainer`) |
| MapKit | `CourseSearchManager` | `MKLocalSearch` nearby course search |
| CoreLocation | `LocationManager` | `CLLocationManager` user location |
| MediaPlayer | `MusicManager` | `MPMusicPlayerController` now-playing/controls |
| WatchConnectivity | `WatchConnectivityManager`, watch `WatchSessionManager` | `WCSession` iPhone↔Watch sync |

## 6. Data model

SwiftData `@Model` entities registered in the container:
`ModelContainer(for: Round.self, HoleScore.self, CustomCourse.self)`.

| Entity | Key fields (representative) | Notes |
|--------|-----------------------------|-------|
| `Round` | date, courseName, holes, `scoreVsPar`, `totalStrokes` | A played/active round |
| `HoleScore` | `holeNumber`, `par`, `yardage`, `strokes`, `putts`, fairway, GIR, penalties | Per-hole line |
| `CustomCourse` | name, rating, slope, per-hole par | User-added course |

Value types: `HoleInfo` (course hole metadata), `ClubProfile` / `SkillLevel`
(club distances + recommendation presets).

**Managers** are `@Observable` classes (not persisted): `ActiveRoundManager`
(start/finish rounds, mutate scores), `HandicapCalculator` (WHS index from last
20 rounds), `LocationManager`, `CourseSearchManager`, `MusicManager`,
`WatchConnectivityManager`.

## 7. Device message contract (WatchConnectivity + Garmin)

The iPhone pushes hole/score state to the Watch (via `WCSession.sendMessage` /
`updateApplicationContext`) and to Garmin (via `Comm.transmit`) as a plain
**string-keyed dictionary**. Both companions read the same keys:

| Key | Type | Meaning |
|-----|------|---------|
| `holeNumber` | Int | Current hole |
| `par` | Int | Hole par |
| `yardage` | Int | Hole yardage |
| `strokes` | Int | Strokes on the hole |
| `putts` | Int | Putts on the hole |
| `scoreVsPar` | Int | Round score vs par |
| `totalStrokes` | Int | Round total strokes |
| `courseName` | String | Course display name |
| `holesTotal` / `holesPlayed` | Int | Progress (Watch) |
| `action` | String | Inbound command |

**Inbound actions** (companion → iPhone): the Watch sends
`scoreUpdate` / `puttUpdate` and `nextHole` / `prevHole`; Garmin sends
`{ "action" => "nextHole" }` / `{ "action" => "prevHole" }` back via
`Comm.transmit`. The iPhone maps these to notifications
(`watchScoreUpdate`, `watchPuttUpdate`, `watchNextHole`, `watchPrevHole`).

```
 iPhone (ActiveRoundManager)
   │  dict{holeNumber,par,yardage,strokes,putts,scoreVsPar,totalStrokes,courseName,...}
   ├── WCSession.sendMessage ─────────► Apple Watch (WatchSessionManager)
   │                                        └─ action: nextHole/prevHole/scoreUpdate/puttUpdate ─┐
   ├── Comm.transmit ─────────────────► Garmin (GolfState)                                       │
   │                                        └─ action: nextHole/prevHole ──────────────┐        │
   └◄──────────────────── notifications (watchNextHole, watchPrevHole, ...) ◄──────────┴────────┘
```

## 8. Configuration model

No runtime env vars, no config files, no server config. Configuration is
compile-time:

| Config | Where | Value |
|--------|-------|-------|
| Location permission | Info.plist | `NSLocationWhenInUseUsageDescription` |
| Music permission | Info.plist | `NSAppleMusicUsageDescription` |
| Music app schemes | Info.plist | `LSApplicationQueriesSchemes`: spotify, youtubemusic, amznmusic, tidal |
| Watch pairing | Entitlements/capability | WatchConnectivity on iOS + watchOS |

## 9. Request & error contract (reframed: on-device data flow)

GolfTrack exposes **no network API**. There is no routing, response envelope, or
error-code table because there is no server. The only outbound network use is:

1. **MapKit `MKLocalSearch`** (Nearby Courses) — Apple-mediated; failures surface
   as an empty/failed search state in `CourseSearchManager` (no crash).
2. **Music-app URL schemes** — `openURL`/`canOpenURL` gated by
   `LSApplicationQueriesSchemes`; a missing app degrades to no-op.

All "data flow" is in-process: Views observe `@Observable` managers; managers
read/write SwiftData; device sync is the dictionary contract in §7. Errors are
handled locally (optionals, empty states), not returned over a wire.

## 10. Security model

On-device, single-user, no accounts. Data never leaves the device except the two
Apple-mediated network uses above. Device sync is over the local paired
Bluetooth/Wi-Fi trust boundary (WatchConnectivity / Garmin BT). Full detail in
[SECURITY](SECURITY.md).

## 11. Observability

No server telemetry. Observability is the Apple developer tooling:

| Signal | Tool |
|--------|------|
| Logs | `os_log` / `print` → Console.app / Xcode console |
| Performance | Xcode **Instruments** (Time Profiler, Allocations, SwiftUI) |
| Crashes | Xcode Organizer crash logs / (future) a crash reporter — see [OPEN_ITEMS](../OPEN_ITEMS.md) |
| Live view | Xcode debugger, SwiftUI previews |

There are no metrics endpoints, traces, or health checks — there is no service to
scrape.

## 12. Deployment topology

Build/sign/distribute only (no running infra). See [DEPLOYMENT](DEPLOYMENT.md)
and the per-target guides in [`../deployments/`](../deployments/).
