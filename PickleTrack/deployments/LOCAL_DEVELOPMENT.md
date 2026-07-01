# PickleTrack — Local Development

Operator guide for building and running **PickleTrack** on a developer workstation.

> **Applicability:** PickleTrack is an on-device SwiftUI app for iOS 17+ / macOS 14+.
> "Deployment" locally means building and running in **Xcode + the iOS Simulator**
> (or on a tethered device). There is no server, database service, container, health
> endpoint, login, or upload — do not look for them.

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) · [AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

Local development produces a debug build of the app and runs it in one of two places:

| Path | Toolchain | Runs on | What it exercises |
|------|-----------|---------|-------------------|
| **Xcode + Simulator** (primary) | Xcode 15+ on macOS | iOS Simulator / My Mac | Full app: SwiftUI, SwiftData, MapKit, CoreLocation |
| **Xcode + device** | Xcode 15+ + free/paid Apple Developer signing | iPhone/iPad | Full app incl. real GPS and Maps |
| **SwiftPM CLI** (`swift build`) | Swift 5.9 toolchain (macOS or Linux) | terminal | **Compile check only** — see the caveat below |

> **SwiftPM caveat.** `swift build` / `swift test` on **Linux** (e.g. the `swift:slim`
> container, see [../Dockerfile](../Dockerfile)) is a *limited syntax / package-resolution
> compile check*. PickleTrack depends on Apple-only frameworks (SwiftUI, SwiftData,
> MapKit, CoreLocation) that **do not exist on Linux**, so a full Linux compile of the
> app is not possible. A real runnable app build requires **Xcode / `xcodebuild` on
> macOS**. On macOS, `swift build` compiles the package but the Simulator/device run
> path is the supported way to launch the UI.

---

## 2. Topology

```
┌────────────────────────── macOS workstation ──────────────────────────┐
│                                                                        │
│   Xcode 15+  ──build──▶  PickleTrack.app (debug)                       │
│        │                     │                                         │
│        │                     ├──▶ iOS Simulator  (SwiftData store in   │
│        │                     │      the simulator's app container)     │
│        │                     └──▶ tethered iPhone/iPad (signed build)  │
│        │                                                               │
│   swift build / swift test  ──▶  compile check (macOS toolchain)       │
│                                                                        │
│   Network egress (device/sim only):                                    │
│        MapKit MKLocalSearch  ──HTTPS──▶  Apple Maps services           │
│        CoreLocation          ──────────  on-device GPS / CL services   │
└────────────────────────────────────────────────────────────────────────┘

   Linux CI host (optional):  swift:slim ──▶ swift build/test = compile check only
```

Data flow is entirely on-device. The only outbound network call is Apple's Maps
service via `MKLocalSearch` in the court finder; everything else (scoring, history,
stats) is local SwiftData.

---

## 3. Prerequisites

| Requirement | Version / detail | Notes |
|-------------|------------------|-------|
| macOS | Ventura 13+ (Sonoma 14+ recommended) | Required for Xcode + Simulator |
| Xcode | **15 or newer** | Ships the iOS 17 SDK + Simulator runtimes |
| Swift toolchain | **5.9** (bundled with Xcode 15) | `swift-tools-version: 5.9` in `Package.swift` |
| iOS Simulator runtime | iOS 17+ | Install via Xcode → Settings → Platforms |
| Apple Developer account | Free tier for Simulator/personal device; paid ($99/yr) for TestFlight/App Store | Needed only for on-device / distribution |
| Signing team | Your Team ID | Set under **Signing & Capabilities** |
| Git | any recent | Source lives in the portfolio repo |
| (optional) Docker + `swift:slim` | for Linux compile-check CI | See [../Dockerfile](../Dockerfile) |

Experimental feature: the target enables `StrictConcurrency`
(`.enableExperimentalFeature("StrictConcurrency")`). Expect Swift concurrency
warnings/errors to surface at compile time — treat them as build-blocking.

---

## 4. Identity & credentials

There are **no runtime credentials, secrets, API keys, or service accounts** in local
development. The app authenticates no users and calls no authenticated backend.

The only "identity" involved is **Apple code-signing** when running on a physical
device:

| Item | Where set | Local requirement |
|------|-----------|-------------------|
| Development Team | Xcode → target → Signing & Capabilities → Team | Free Apple ID works for personal device |
| Bundle Identifier | same panel | e.g. `com.yourorg.pickletrack` |
| Provisioning | "Automatically manage signing" | Xcode provisions a development profile |

Simulator runs need **no signing**. Distribution signing is covered in
[AWS.md](AWS.md) / [AZURE.md](AZURE.md) and [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

---

## 5. Environment variables

PickleTrack reads **no environment variables at runtime** — it is a sandboxed
on-device app configured through `Info.plist` and Xcode build settings, not env vars.

The following are optional **developer-side** shell variables for the SwiftPM CLI path:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Pin which Xcode `swift`/`xcodebuild` resolve to |
| `TOOLCHAINS` | `swift` | Select an alternate Swift toolchain if installed |

---

## 6. Configuration references

App configuration is compile-time / bundle-level, not runtime config:

| Setting | Example | Purpose |
|---------|---------|---------|
| `NSLocationWhenInUseUsageDescription` (Info.plist) | `PickleTrack uses your location to find nearby pickleball courts.` | **Required** — court finder location permission prompt |
| Deployment target (iOS) | `17.0` | Matches `.iOS(.v17)` in `Package.swift` |
| Deployment target (macOS) | `14.0` | Matches `.macOS(.v14)` |
| Bundle Identifier | `com.yourorg.pickletrack` | App identity for signing/distribution |
| SwiftData model container | `ModelContainer(for: PBMatch.self, PBGame.self)` | On-device persistence, no config needed |

---

## 7. Verification

> There is **no health endpoint, no login, no secrets to resolve, no file upload, and
> no database server** to check. Verification means the build succeeds and the app's
> key screens render and function on the Simulator.

**Build / test (macOS or Linux compile-check):**

```bash
cd PickleTrack
swift build          # compiles the package (Apple frameworks only compile on macOS)
swift test           # NOTE: no Tests/ target exists yet → reports "no tests"
```

**Full app (macOS, Xcode):**

```bash
# Build for a Simulator destination
xcodebuild -scheme PickleTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' build
```

Then in Xcode press **⌘R** and walk the screens:

- [ ] **Home** — win-rate hero card renders; "New Match" button present
- [ ] **New Match** — pick singles/doubles, format to 11/15/21, best of 1/3/5, enter names
- [ ] **Active Match** — point buttons score correctly; serve announcement string updates; **Undo** rolls back the last point and serve state
- [ ] **Match History** — completed match shows game-by-game breakdown
- [ ] **Stats** — metric grid + last-10 W/L bar chart populate
- [ ] **Nearby Courts** — grants location, runs `MKLocalSearch`, lists courts with distance/address; "Open in Maps" works
- [ ] **Rules** — collapsible sections expand

> On first launch of **Nearby Courts**, iOS shows the location permission prompt driven
> by `NSLocationWhenInUseUsageDescription`. Denying it degrades the court finder only;
> the rest of the app is unaffected.

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Pull latest | `git pull` in the portfolio repo; re-open in Xcode |
| Reset local data | Delete the app from the Simulator/device, or **Device → Erase All Content and Settings** — wipes the SwiftData store |
| Add a Simulator runtime | Xcode → Settings → Platforms → **+** |
| Update toolchain | Install newer Xcode; confirm `swift --version` ≥ 5.9 |
| Clean build | `swift package clean` / Xcode **⇧⌘K** |
| Regenerate SwiftData store | Migrations are automatic (lightweight) for additive `@Model` changes; destructive changes require deleting the app in dev |
| Compile-check in CI | Run the `swift:slim` image (see [../Dockerfile](../Dockerfile)) |

There are no server migrations, background workers, queues, or scheduled jobs.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `swift build` fails on Linux with "no such module 'SwiftUI'" | Apple frameworks absent on Linux | Expected — build on macOS with Xcode; Linux is compile-check only |
| `swift test` says "no tests" | No `Tests/` target exists yet | Expected; see [../OPEN_ITEMS.md](../OPEN_ITEMS.md) |
| Court finder shows nothing | Location denied, or `NSLocationWhenInUseUsageDescription` missing | Add the Info.plist key; re-enable location in Settings → Privacy |
| "No account for team" when running on device | Signing not configured | Set Team under Signing & Capabilities; use a free Apple ID for personal device |
| StrictConcurrency errors on build | Data-race safety warnings promoted by the experimental flag | Fix the flagged actor/`Sendable` issue; do not disable the flag |
| App builds but Simulator won't launch | Missing iOS 17 runtime | Install the runtime via Xcode → Settings → Platforms |
| Maps search returns nothing on Simulator | Simulator has no default location | Set **Features → Location → Custom** in the Simulator |
