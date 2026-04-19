# PickleTrack

A native SwiftUI pickleball scorekeeper and stats app for **iOS 17+** and **macOS 14+**.

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
