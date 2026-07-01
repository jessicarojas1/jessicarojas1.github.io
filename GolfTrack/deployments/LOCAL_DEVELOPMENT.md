# Local Development — GolfTrack

Operator guide for building, running, and testing GolfTrack on a developer
workstation. GolfTrack is an **on-device SwiftUI app** (iOS 17+ / macOS 14+) with
an Apple Watch companion (watchOS 10+) and a Garmin Connect IQ companion. The
authoritative build tool is **Xcode on macOS**; a limited SwiftPM compile check
also runs on Linux (see the [Dockerfile](../Dockerfile)).

> **Applicability:** This is the primary, fully-applicable target. There is **no
> server, no database service, no login, and no file-upload endpoint** — all
> state lives on-device in SwiftData. Verification below is adapted accordingly.

Cross-links: [DEPLOYMENT](../docs/DEPLOYMENT.md) ·
[ARCHITECTURE](../docs/ARCHITECTURE.md) ·
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) (CI host) ·
[AZURE](AZURE.md) / [AWS](AWS.md) (distribution).

---

## 1. Deployment architecture

"Deployment" locally means: compile the Swift Package or the Xcode multiplatform
app, then run it in the iOS/watchOS Simulator or on a tethered device.

| Layer | What runs | Where |
|-------|-----------|-------|
| iOS/macOS app | `GolfTrack` executable target (SwiftUI + SwiftData) | Simulator or device |
| Watch companion | `Sources/GolfTrackWatch/` (watchOS `@main`) | Paired Watch / Watch Simulator |
| Garmin companion | `GarminApp/GolfTrack.mc` (Monkey C) | Connect IQ Simulator / Garmin device |
| Persistence | SwiftData `ModelContainer(for: Round, HoleScore, CustomCourse)` | On-device sandbox |
| Networking | MapKit `MKLocalSearch` (course search) + music-app URL schemes only | — |

There is no backend to stand up. The Linux SwiftPM path (`swift build`) is a
**compile check only** — Apple frameworks (SwiftUI, SwiftData, MapKit,
CoreLocation, MediaPlayer, WatchConnectivity) do not exist on Linux, so a full
build/run requires Xcode/xcodebuild on macOS.

## 2. Topology

```
 ┌─────────────────────────── macOS workstation ───────────────────────────┐
 │                                                                          │
 │   Xcode 15+  ──►  GolfTrack multiplatform app                            │
 │      │              ├─ iOS target      ──► iOS Simulator / iPhone        │
 │      │              ├─ macOS target    ──► runs natively                 │
 │      │              └─ watchOS target  ──► Watch Simulator / Apple Watch │
 │      │                                                                    │
 │   SwiftData store (on-device sandbox, per simulator/device)             │
 │                                                                          │
 │   Connect IQ SDK ──► Monkey C build ──► CIQ Simulator / Garmin device   │
 └──────────────────────────────────────────────────────────────────────────┘

 (Optional) Linux box or container:  swift build / swift test  = compile check only
```

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| macOS | 14 (Sonoma) or newer | Required for iOS 17 / watchOS 10 SDKs |
| Xcode | 15 or newer | Ships iOS 17, watchOS 10, macOS 14 SDKs + Simulators |
| Swift toolchain | 5.9 (bundled with Xcode 15) | `swift-tools-version: 5.9` |
| Apple Developer account | Free tier OK for Simulator; paid ($99/yr) for device + TestFlight | Needed to set a signing Team |
| Signing Team | Personal or Org team ID | Set per target under Signing & Capabilities |
| Garmin Connect IQ SDK | 4.x | Only to build/run the Garmin companion |
| Garmin device / CIQ Simulator | Connect IQ 3.4+ device | For Garmin testing |
| Docker (optional) | any recent | Only for the Linux compile-check image |

## 4. Identity & credentials

Local development uses your **Apple ID / developer signing identity** — there are
no application credentials, API keys, or service accounts in this app.

- **Simulator:** no signing required.
- **Physical device:** select a **Team** under each target's *Signing &
  Capabilities*; Xcode manages a development provisioning profile automatically.
- **Garmin device:** a Connect IQ **developer key** (generated once via the SDK)
  is required to sign sideloaded `.prg` builds.

No secrets are committed. There is no `.env` for the app.

## 5. Environment variables

The app reads **no environment variables at runtime**. The following affect the
local toolchain only:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Selects the active Xcode for `xcodebuild`/`swift` |
| `DEVELOPMENT_TEAM` | `AB12CD34EF` | Team ID passed to `xcodebuild` for signing (optional; usually set in the project) |
| `SIMULATOR_UDID` | `A1B2C3D4-...` | Target a specific simulator for `xcodebuild -destination` |

Select an Xcode: `sudo xcode-select -s /Applications/Xcode.app` (or set
`DEVELOPER_DIR`).

## 6. Configuration references

App configuration is compile-time (Info.plist keys + entitlements), not runtime env.

| Key | Example | Purpose |
|-----|---------|---------|
| `NSLocationWhenInUseUsageDescription` | "GolfTrack uses your location to find nearby golf courses." | CoreLocation prompt (nearby course search) |
| `NSAppleMusicUsageDescription` | "GolfTrack shows now-playing info while you score your round." | MediaPlayer now-playing access |
| `LSApplicationQueriesSchemes` | `spotify`, `youtubemusic`, `amznmusic`, `tidal` | Allow `canOpenURL` checks for music apps |
| WatchConnectivity capability | enabled on iOS + watchOS targets | `WCSession` pairing |

Entry point note: the app uses `Sources/GolfTrack/main.swift` calling
`GolfTrackApp.main()` (not `@main`) for SPM compatibility. Do **not** add `@main`
to `GolfTrackApp.swift`.

## 7. Verification

There is **no health endpoint, no login, no secrets to resolve, and no file
upload / object storage** — do not look for them. Verify the real behaviors:

**Compile check (Linux or macOS, platform-agnostic Swift + package resolution):**
```bash
swift build          # resolves the package and compiles what can compile on this host
swift test           # NOTE: no Tests/ target exists yet → "no tests" is expected
```
> On Linux, Apple-framework code will not compile; use this only as a package /
> syntax gate in CI. See [Dockerfile](../Dockerfile).

**Full build + run (macOS / Xcode — authoritative):**
```bash
# List simulators
xcrun simctl list devices available

# Build for an iOS simulator (scheme created when you import the package into Xcode)
xcodebuild -scheme GolfTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' build

# Or press ⌘R in Xcode
```

**Acceptance checklist:**
- [ ] `swift build` resolves the package (no external deps to fetch).
- [ ] Xcode build succeeds for iOS Simulator (and watchOS Simulator if the Watch target is wired).
- [ ] App launches in the Simulator without crashing.
- [ ] **Home** renders (handicap index, stats pills, recent rounds).
- [ ] **Active Round** renders; strokes/putts adjust; music bar appears.
- [ ] **Stats** renders metrics grid + trend chart.
- [ ] **Nearby Courses** prompts for location and lists results (grant location in Simulator: *Features → Location*).
- [ ] **Rules** reference renders all sections.
- [ ] (Optional) Watch Simulator mirrors the active round via WatchConnectivity.
- [ ] (Optional) Garmin CIQ Simulator displays hole/par/score from a simulated phone message.

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update toolchain | Install a newer Xcode; `xcode-select -s`; re-open the package |
| Reset simulator state | `xcrun simctl erase all` (wipes on-device SwiftData store) |
| Regenerate scheme | Re-import the package / recreate the multiplatform app in Xcode |
| Add a screen | Add a SwiftUI `View` under `Sources/GolfTrack/Views/...`; wire into `ContentView` |
| Update docs | Keep this set current (see [CLAUDE.md](../CLAUDE.md)) |

There are no migrations, no backups to run (state is ephemeral per simulator),
and no certificates to rotate locally.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `swift build` fails on Linux referencing SwiftUI/SwiftData | Apple frameworks absent on Linux | Expected — build with Xcode on macOS; Linux is compile-check only |
| `swift test` prints no tests | No `Tests/` target yet | Expected; see [OPEN_ITEMS](../OPEN_ITEMS.md) |
| "Signing requires a development team" | No Team set for device build | Set Team under target → Signing & Capabilities |
| Nearby Courses empty | Location not granted / not simulated | Simulator → Features → Location → pick a city |
| Music bar shows nothing | No now-playing item / permission denied | Play media in the Simulator; check `NSAppleMusicUsageDescription` |
| Watch not syncing | WatchConnectivity capability missing | Enable it on both iOS and watchOS targets |
| `@main` conflict | `@main` added to `GolfTrackApp.swift` | Remove it; entry is `main.swift` |
| Garmin build fails | Missing CIQ SDK / developer key | Install Connect IQ SDK 4.x; generate a developer key |
