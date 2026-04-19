# PickleTrack

A native SwiftUI pickleball scorekeeper and stats app for **iOS 17+** and **macOS 14+**.

## Features

- **Live Scoring** — tap to score points with full side-out serving logic (first-serve rule, server rotation in doubles)
- **Score Announcement** — displays the correct "serving–receiving–server#" call before each serve
- **Undo** — one tap to reverse the last point
- **Match History** — full game-by-game breakdown for every completed match
- **Stats Dashboard** — win rate, win streak, avg points for/against, last 10 results bar chart
- **Find Courts** — GPS-powered MapKit search for nearby pickleball courts with distance, address, and phone
- **Singles & Doubles** — supports both formats with per-player and partnership names
- **Flexible format** — to 11, 15, or 21; best of 1, 3, or 5
- **SwiftData persistence** — everything saved locally

## Revenue Model

| Tier    | Price    | Features |
|---------|----------|----------|
| Free    | $0       | Basic scoring, last 5 matches |
| Pro     | $3.99/mo | Unlimited history, full stats, court finder |
| Premium | $7.99/mo | Tournament bracket, team leaderboards, share scorecards |

**Market**: 36M+ pickleball players in the US as of 2023 — fastest growing sport. App Store has almost no polished options.

## Setup in Xcode

1. Open **Xcode 15+**
2. **File → New → Project → Multiplatform → App**
3. Name: **PickleTrack**, Targets: **iOS 17** and **macOS 14**
4. Drag all files from `Sources/PickleTrack/` into the project
5. Add `NSLocationWhenInUseUsageDescription` to Info.plist
6. **⌘R** to build and run

## Serving Rules Implemented

- **First-serve rule**: The first team to serve at the start of each game begins with only one server (server 2), preventing an unfair double-server advantage.
- **Doubles rotation**: Server 1 → Server 2 → side-out. Each server keeps serving until their team loses a rally.
- **Singles**: Standard side-out — serving player keeps serving until they lose the rally.
- **Win by 2**: All game lengths (11/15/21) require winning by 2 points.

## Project Structure

```
Sources/PickleTrack/
├── PickleTrackApp.swift
├── main.swift
├── Models/
│   └── MatchModels.swift        PBMatch + PBGame @Model, PointEvent log
├── Managers/
│   ├── ActiveMatchManager.swift @Observable serve state machine + scoring
│   ├── CourtSearchManager.swift MKLocalSearch for pickleball courts
│   └── LocationManager.swift    CLLocationManager wrapper
└── Views/
    ├── ContentView.swift        TabView (iOS) / NavigationSplitView (macOS)
    ├── HomeView.swift           Win rate hero + new match button
    ├── MatchViews/
    │   ├── NewMatchSetupView.swift  Format, length, player names
    │   ├── ActiveMatchView.swift    Live scoreboard + point buttons + undo
    │   └── MatchHistoryView.swift   History list + game-by-game detail
    ├── StatsViews/
    │   └── StatsView.swift          8-metric grid + W/L bar + recent list
    └── CourtViews/
        └── NearbyCourtView.swift    Map + list + detail sheet with Open in Maps
```
