# GolfTrack

A SwiftUI golf scorecard, handicap tracker, and stats app for **iOS 17+** and **macOS 14+**, with an **Apple Watch companion app** and **in-round music controls**.

## Features

- **Find Nearby Courses** — GPS-based search using MapKit finds real golf courses near you, shows them on a map with distance, address, and phone number
- Set hole-by-hole par before starting a round from any found course

- **Live Scorecard** — hole-by-hole scoring with strokes, putts, fairway hit, GIR, and penalties
- **Apple Watch** — companion watchOS app mirrors your scorecard; adjust strokes/putts from your wrist, navigate holes, see score vs par badge
- **Music Controls** — mini now-playing bar at the bottom of the active round; skip, play/pause, and open Apple Music, Spotify, YouTube Music, Amazon Music, or Tidal
- **Club Recommendations** — suggests which club to use based on hole yardage; enter your own carry distances or pick Beginner / Intermediate / Advanced / Masters preset
- **Rules Reference** — built-in USGA/R&A rules guide covering scoring, penalty areas, the green, and etiquette
- **World Handicap System** — automatic Handicap Index calculated from your last 20 rounds (WHS formula)
- **Detailed Stats** — avg score, best score, fairways %, GIR %, avg putts, score trend chart
- **Score Distribution** — Eagles, Birdies, Pars, Bogeys, Doubles across all rounds
- **Course Library** — 5 famous courses (Pebble Beach, TPC Sawgrass, Bethpage Black, Torrey Pines, Generic Muni)
- **Custom Courses** — add any course with rating, slope, and per-hole par
- **Round History** — full hole-by-hole scorecard view for every past round
- **SwiftData persistence** — everything saved locally

## Revenue Model

| Tier     | Price     | Features |
|----------|-----------|---------|
| Free     | $0        | Basic scoring, last 5 rounds, handicap |
| Pro      | $4.99/mo  | Unlimited history, full stats, PDF scorecard export |
| Premium  | $9.99/mo  | Cloud sync, betting games (Nassau, Skins), share scorecards |

**Market**: 25M+ golfers in the US. Even 0.1% conversion at $4.99/mo = $12,500/month.

---

## Getting Started

### 1. Pull the source code

The source lives inside the portfolio repo. Clone it and navigate to the GolfTrack folder:

```bash
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io/GolfTrack
```

Or use Git sparse checkout to pull only this folder:

```bash
git clone --filter=blob:none --sparse https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io
git sparse-checkout set GolfTrack
cd GolfTrack
```

### 2. Open in Xcode

1. Open **Xcode 15+** on your Mac
2. **File → New → Project → Multiplatform → App**
3. Name: **GolfTrack**, Targets: **iOS 17**, **macOS 14**, **watchOS 10**
4. Delete the generated `ContentView.swift`
5. Drag all files from `Sources/GolfTrack/` into the **iOS/macOS** target
6. Drag all files from `Sources/GolfTrackWatch/` into the **watchOS** target
7. Set your **Team** and **Bundle Identifier** under each target's Signing & Capabilities tab
8. Enable **WatchConnectivity** capability on both iOS and watchOS targets
9. Add the required Info.plist keys (see below)
10. **⌘R** to build and run

> **Note:** The app uses `main.swift` as its entry point (not `@main`) for SPM compatibility. Do not add `@main` to `GolfTrackApp.swift`.

### 3. Required Info.plist Keys

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>GolfTrack uses your location to find nearby golf courses.</string>

<key>NSAppleMusicUsageDescription</key>
<string>GolfTrack shows now-playing info while you score your round.</string>

<key>LSApplicationQueriesSchemes</key>
<array>
    <string>spotify</string>
    <string>youtubemusic</string>
    <string>amznmusic</string>
    <string>tidal</string>
</array>
```

---

## Apple Watch Setup

The watchOS companion app (`Sources/GolfTrackWatch/`) uses **WatchConnectivity** to stay in sync with the iPhone:

- While a round is active, every stroke/putt change is pushed to the Watch via `WCSession.sendMessage`
- The Watch can adjust strokes and putts independently; changes sync back to the iPhone
- Prev/Next hole buttons on the Watch navigate the iPhone scorecard
- When the Watch is out of range, `updateApplicationContext` keeps the complication data current

## Garmin Integration

`GarminApp/GolfTrack.mc` is a **Garmin Connect IQ** companion app written in Monkey C.

1. Install [Garmin Connect IQ SDK](https://developer.garmin.com/connect-iq/sdk/)
2. Build and sideload `GolfTrack.mc` to a supported Garmin device
3. The iPhone app sends hole updates via **Garmin's phone-to-device messaging API** (`Comm.transmit`)
4. The Garmin watch displays hole number, par, yardage, and score vs par; Up/Select buttons navigate holes

## Android Wear / Wear OS

An Android Wear companion requires a separate Android app (Kotlin). Architecture:

1. Use **Wearable Data Layer API** (`WearableClient`) to push messages from the Android version of GolfTrack
2. Watch-side app receives `DataItem` updates and displays the scorecard
3. Touch input on the Wear OS watch sends `sendMessage("/hole/next")` back to the phone

## Music Controls

During an active round, a mini music bar appears at the bottom of the scoring screen:

- **Play/Pause, Skip Forward/Back** via `MPMusicPlayerController.systemMusicPlayer`
- **Open App** taps the music note icon to pick Apple Music, Spotify, YouTube Music, Amazon Music, or Tidal — the chosen app opens via URL scheme
- Album art is pulled from `MPMediaItem.artwork`

---

## Project Structure

```
Sources/GolfTrack/
├── GolfTrackApp.swift               App entry + SwiftData container
├── main.swift                       Entry point (calls GolfTrackApp.main())
├── Models/
│   ├── Course.swift                 HoleInfo struct, CustomCourse @Model
│   ├── RoundModels.swift            Round + HoleScore @Model (SwiftData)
│   └── ClubProfile.swift           Club distances, SkillLevel presets, recommendations
├── Data/
│   └── CourseLibrary.swift          5 built-in courses with full hole data
├── Managers/
│   ├── HandicapCalculator.swift     WHS handicap index calculation
│   ├── ActiveRoundManager.swift     @Observable — start/finish rounds, update scores
│   ├── LocationManager.swift        CLLocationManager wrapper
│   └── CourseSearchManager.swift    MKLocalSearch nearby course finder
├── Watch/
│   └── WatchConnectivityManager.swift  iOS-side WCSession; send/receive hole data
├── Music/
│   └── MusicManager.swift           MPMusicPlayerController + URL scheme launchers
└── Views/
    ├── ContentView.swift            TabView (iOS) / NavigationSplitView (macOS)
    ├── HomeView.swift               Handicap index, stats pills, recent rounds
    ├── MusicPlayerBar.swift         Mini now-playing bar + app picker sheet
    ├── RoundViews/
    │   ├── ActiveRoundView.swift    Live scoring + Watch sync + music bar
    │   ├── ClubRecommendationCard.swift  Club suggestion widget
    │   ├── RoundHistoryView.swift   All rounds grouped by month
    │   └── RoundDetailView.swift   Full scorecard grid + distribution
    ├── StatsViews/
    │   └── StatsView.swift          Metrics grid + trend chart + distribution
    ├── CourseViews/
    │   ├── CourseLibraryView.swift  Browse built-in + add custom courses
    │   └── NearbyCoursesView.swift  GPS-based course search with map
    ├── RulesViews/
    │   └── GolfRulesView.swift      USGA/R&A rules reference (8 sections)
    └── SettingsViews/
        ├── DeviceConnectionView.swift  Watch status, Garmin link, Android Wear info
        └── ClubProfileSetupView.swift  Per-club distance sliders + skill level picker

Sources/GolfTrackWatch/
├── GolfTrackWatchApp.swift          watchOS @main entry
├── WatchSessionManager.swift        watchOS WCSession + all score state
├── WatchContentView.swift           Root view: idle or active round
└── WatchActiveRoundView.swift       Hole scoring UI with +/- buttons

GarminApp/
└── GolfTrack.mc                     Garmin Connect IQ companion (Monkey C)
```

## Phase Roadmap

- **Phase 1 (done)**: Scorecard, handicap, stats, course library
- **Phase 2 (done)**: Apple Watch companion, music controls, GPS course search, club recommendations, rules reference
- **Phase 3**: Betting games — Nassau, Skins, Stableford, Match Play
- **Phase 4**: CloudKit sync + share scorecards with friends
- **Phase 5**: AI caddie — shot-by-shot recommendations based on your stats


## Features

- **Find Nearby Courses** — GPS-based search using MapKit finds real golf courses near you, shows them on a map with distance, address, and phone number
- Set hole-by-hole par before starting a round from any found course

- **Live Scorecard** — hole-by-hole scoring with strokes, putts, fairway hit, GIR, and penalties
- **Apple Watch** — companion watchOS app mirrors your scorecard; adjust strokes/putts from your wrist, navigate holes, see score vs par badge
- **Music Controls** — mini now-playing bar at the bottom of the active round; skip, play/pause, and open Apple Music, Spotify, YouTube Music, Amazon Music, or Tidal
- **World Handicap System** — automatic Handicap Index calculated from your last 20 rounds (WHS formula)
- **Detailed Stats** — avg score, best score, fairways %, GIR %, avg putts, score trend chart
- **Score Distribution** — Eagles, Birdies, Pars, Bogeys, Doubles across all rounds
- **Course Library** — 5 famous courses (Pebble Beach, TPC Sawgrass, Bethpage Black, Torrey Pines, Generic Muni)
- **Custom Courses** — add any course with rating, slope, and per-hole par
- **Round History** — full hole-by-hole scorecard view for every past round
- **SwiftData persistence** — everything saved locally

## Revenue Model

| Tier     | Price     | Features |
|----------|-----------|---------|
| Free     | $0        | Basic scoring, last 5 rounds, handicap |
| Pro      | $4.99/mo  | Unlimited history, full stats, PDF scorecard export |
| Premium  | $9.99/mo  | Cloud sync, betting games (Nassau, Skins), share scorecards |

**Market**: 25M+ golfers in the US. Even 0.1% conversion at $4.99/mo = $12,500/month.

## Required Info.plist Keys (add in Xcode)

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>GolfTrack uses your location to find nearby golf courses.</string>

<!-- For music controls -->
<key>NSAppleMusicUsageDescription</key>
<string>GolfTrack shows now-playing info while you score your round.</string>
```

For Spotify / YouTube Music / Amazon Music URL schemes, add to LSApplicationQueriesSchemes:
```xml
<key>LSApplicationQueriesSchemes</key>
<array>
    <string>spotify</string>
    <string>youtubemusic</string>
    <string>amznmusic</string>
    <string>tidal</string>
</array>
```

## Setup in Xcode

1. Open **Xcode 15+** on your Mac
2. **File → New → Project → Multiplatform → App**
3. Name: **GolfTrack**, Targets: **iOS 17**, **macOS 14**, **watchOS 10**
4. Delete the generated `ContentView.swift`
5. Drag all files from `Sources/GolfTrack/` into the iOS/macOS target
6. Drag all files from `Sources/GolfTrackWatch/` into the watchOS target
7. Enable **WatchConnectivity** capability on both iOS and watchOS targets
8. **⌘R** to build and run

## Apple Watch Setup

The watchOS companion app (`Sources/GolfTrackWatch/`) uses **WatchConnectivity** to stay in sync with the iPhone:

- While a round is active, every stroke/putt change is pushed to the Watch via `WCSession.sendMessage`
- The Watch can adjust strokes and putts independently; changes sync back to the iPhone
- Prev/Next hole buttons on the Watch navigate the iPhone scorecard
- When the Watch is out of range, `updateApplicationContext` keeps the complication data current

## Garmin Integration

`GarminApp/GolfTrack.mc` is a **Garmin Connect IQ** companion app written in Monkey C.

1. Install [Garmin Connect IQ SDK](https://developer.garmin.com/connect-iq/sdk/)
2. Build and sideload `GolfTrack.mc` to a supported Garmin device
3. The iPhone app sends hole updates via **Garmin's phone-to-device messaging API** (`Comm.transmit`)
4. The Garmin watch displays hole number, par, yardage, and score vs par; Up/Select buttons navigate holes

## Android Wear / Wear OS

An Android Wear companion requires a separate Android app (Kotlin). Architecture:

1. Use **Wearable Data Layer API** (`WearableClient`) to push messages from the Android version of GolfTrack
2. Watch-side app receives `DataItem` updates and displays the scorecard
3. Touch input on the Wear OS watch sends `sendMessage("/hole/next")` back to the phone

## Music Controls

During an active round, a mini music bar appears at the bottom of the scoring screen:

- **Play/Pause, Skip Forward/Back** via `MPMusicPlayerController.systemMusicPlayer`
- **Open App** taps the music note icon to pick Apple Music, Spotify, YouTube Music, Amazon Music, or Tidal — the chosen app opens via URL scheme
- Album art is pulled from `MPMediaItem.artwork`

## Project Structure

```
Sources/GolfTrack/
├── GolfTrackApp.swift               App entry + SwiftData container
├── Models/
│   ├── Course.swift                 HoleInfo struct, CustomCourse @Model
│   └── RoundModels.swift            Round + HoleScore @Model (SwiftData)
├── Data/
│   └── CourseLibrary.swift          5 built-in courses with full hole data
├── Managers/
│   ├── HandicapCalculator.swift     WHS handicap index calculation
│   ├── ActiveRoundManager.swift     @Observable — start/finish rounds, update scores
│   ├── LocationManager.swift        CLLocationManager wrapper
│   └── CourseSearchManager.swift    MKLocalSearch nearby course finder
├── Watch/
│   └── WatchConnectivityManager.swift  iOS-side WCSession; send/receive hole data
├── Music/
│   └── MusicManager.swift           MPMusicPlayerController + URL scheme launchers
└── Views/
    ├── ContentView.swift            TabView (iOS) / NavigationSplitView (macOS)
    ├── HomeView.swift               Handicap index, stats pills, recent rounds
    ├── MusicPlayerBar.swift         Mini now-playing bar + app picker sheet
    ├── RoundViews/
    │   ├── ActiveRoundView.swift    Live scoring + Watch sync + music bar
    │   ├── RoundHistoryView.swift   All rounds grouped by month
    │   └── RoundDetailView.swift   Full scorecard grid + distribution
    ├── StatsViews/
    │   └── StatsView.swift          Metrics grid + trend chart + distribution
    ├── CourseViews/
    │   ├── CourseLibraryView.swift  Browse built-in + add custom courses
    │   └── NearbyCoursesView.swift  GPS-based course search with map
    └── SettingsViews/
        └── DeviceConnectionView.swift  Watch status, Garmin link, Android Wear info

Sources/GolfTrackWatch/
├── GolfTrackWatchApp.swift          watchOS @main entry
├── WatchSessionManager.swift        watchOS WCSession + all score state
├── WatchContentView.swift           Root view: idle or active round
└── WatchActiveRoundView.swift       Hole scoring UI with +/- buttons

GarminApp/
└── GolfTrack.mc                     Garmin Connect IQ companion (Monkey C)
```

## Phase Roadmap

- **Phase 1 (done)**: Scorecard, handicap, stats, course library
- **Phase 2 (done)**: Apple Watch companion, music controls, GPS course search
- **Phase 3**: Betting games — Nassau, Skins, Stableford, Match Play
- **Phase 4**: CloudKit sync + share scorecards with friends
- **Phase 5**: AI caddie — club recommendations based on your stats
