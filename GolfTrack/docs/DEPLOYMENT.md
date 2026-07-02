# Deployment — GolfTrack

How to build, sign, and distribute GolfTrack. GolfTrack is an **on-device** app —
"deployment" means producing a signed build and delivering it to devices, **not**
running a service. There are **no server migrations, no worker/queue, no
Ollama/GPU** (see notes below).

## Contents
1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Build commands](#3-build-commands)
4. [Sign](#4-sign)
5. [Distribute](#5-distribute)
6. [Watch & Garmin companions](#6-watch--garmin-companions)
7. [Configuration & secrets](#7-configuration--secrets)
8. [Migrations / worker / Ollama / GPU](#8-migrations--worker--ollama--gpu)
9. [Production checklist](#9-production-checklist)
10. [Per-target guides](#10-per-target-guides)

---

## 1. Deployment models

| Model | Audience | Guide |
|-------|----------|-------|
| Simulator / local | Developers | [LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) |
| CI compile check (Linux) | CI | [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md), [KUBERNETES](../deployments/KUBERNETES.md) |
| TestFlight (beta) | Testers | [AZURE](../deployments/AZURE.md) / [AWS](../deployments/AWS.md) (pipeline) |
| App Store (public) | End users | AZURE/AWS pipeline |
| Ad-Hoc | Registered devices | AZURE/AWS pipeline |
| Enterprise / MDM (Intune/Jamf) | Managed fleets | AZURE (Intune) / [AIRGAPPED](../deployments/AIRGAPPED.md) |
| Garmin Connect IQ store | Garmin users | §6 |

## 2. Prerequisites

- macOS 14+ with **Xcode 15+** (iOS 17 / watchOS 10 / macOS 14 SDKs).
- Swift 5.9 toolchain (bundled with Xcode).
- Apple Developer Program (paid) for device/TestFlight/App Store.
- App Store Connect API key (`.p8`) for automated upload.
- Garmin Connect IQ SDK 4.x + developer key (Garmin companion).
- **No external SwiftPM dependencies** — `Package.swift` declares none.

## 3. Build commands

```bash
# Package resolve + compile-check (Linux CI or macOS). No external deps to fetch.
swift build
swift test          # NOTE: no Tests/ target yet → "no tests" is expected

# Full build for iOS Simulator (macOS/Xcode — authoritative)
xcodebuild -scheme GolfTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' build

# Archive for distribution
xcodebuild -scheme GolfTrack \
  -destination 'generic/platform=iOS' \
  -archivePath build/GolfTrack.xcarchive archive

# Export a signed .ipa from the archive
xcodebuild -exportArchive \
  -archivePath build/GolfTrack.xcarchive \
  -exportOptionsPlist ExportOptions.plist \
  -exportPath build/export
```

> `swift build`/`swift test` on Linux is a **compile/package gate only** —
> Apple-framework code (SwiftUI/SwiftData/MapKit/MediaPlayer/WatchConnectivity)
> does not compile off-Apple platforms. Real app/device builds require
> `xcodebuild` on macOS.

## 4. Sign

Signing assets (keep in a secrets manager — see
[AWS](../deployments/AWS.md) / [AZURE](../deployments/AZURE.md)):

- Distribution certificate + private key (`.p12`).
- Provisioning profile(s) matching the bundle ID + capabilities (WatchConnectivity).
- App Store Connect API key `.p8` (+ key ID, issuer ID).

`ExportOptions.plist` sets `method` = `app-store` | `ad-hoc` | `enterprise` and
the signing team.

## 5. Distribute

```bash
# Upload to TestFlight / App Store Connect
xcrun altool --upload-app -f build/export/GolfTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
# (or) xcrun notarytool / Transporter / App Store Connect UI
```

| Channel | Method | Notes |
|---------|--------|-------|
| TestFlight | `app-store` export → upload | Internal/external tester groups |
| App Store | `app-store` export → submit for review | Phased release available |
| Ad-Hoc | `ad-hoc` export | Only registered device UDIDs |
| Enterprise/MDM | `enterprise` export → Intune/Jamf | No public App Store |

## 6. Watch & Garmin companions

- **watchOS:** the Watch app (`Sources/GolfTrackWatch/`) is added as a watchOS
  target in Xcode and **bundled with the iOS app** — it ships in the same App
  Store / TestFlight submission, not separately.
- **Garmin:** build `GarminApp/GolfTrack.mc` with the Connect IQ SDK 4.x into a
  `.iq` package and submit to the **Connect IQ store** (or sideload a `.prg` for
  testing). This is an independent submission from the App Store build.

## 7. Configuration & secrets

- **App config:** compile-time Info.plist keys + WatchConnectivity capability
  (see [ARCHITECTURE](ARCHITECTURE.md) §8). No runtime env, no config files.
- **Build secrets:** signing cert, profiles, ASC API key — store in Key
  Vault / Secrets Manager, inject at build time, never commit.

## 8. Migrations / worker / Ollama / GPU

- **Database migrations: none.** SwiftData manages the on-device schema; there is
  no server DB and no migration command. Model-version changes are handled with
  SwiftData schema migration in-app if/when models evolve.
- **Worker / background process / queue: none.** No cron, no queue, no daemon.
- **Ollama: N/A.** No AI feature exists (a future "AI caddie" is roadmap only) —
  there is nothing to self-host.
- **GPU acceleration: N/A.** No server-side inference; on-device rendering uses
  the device GPU implicitly via SwiftUI/Metal — nothing to configure.

## 9. Production checklist

### Secrets & identity
- [ ] Distribution certificate + private key stored in a secrets manager (not committed).
- [ ] Provisioning profiles match bundle ID + WatchConnectivity capability.
- [ ] App Store Connect API key (`.p8`) stored as a secret; least-privilege role (App Manager).
- [ ] App Store Connect 2FA enforced; signing keys backed up (see [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).

### Transport & exposure
- [ ] App Transport Security (ATS) left at default (TLS enforced) — only MapKit + URL schemes make network calls.
- [ ] No cleartext exceptions added to Info.plist.
- [ ] `LSApplicationQueriesSchemes` limited to the 4 declared music apps (least privilege).

### Hardening
- [ ] Data Protection enabled (default `NSFileProtectionComplete` class where applicable).
- [ ] No secrets/credentials in the bundle (there are none today).
- [ ] Debug logging disabled in Release; no PII in logs.
- [ ] Keychain used for any future secrets (none stored today).

### Resilience & operations
- [ ] Crash reporting plan (Xcode Organizer at minimum) — see [OPEN_ITEMS](../OPEN_ITEMS.md).
- [ ] App Store **phased release** enabled for staged rollout.
- [ ] Version + build number bump automated in CI.
- [ ] Rollback plan: submit a previous build / expedite a fix (App Store has no instant rollback).

## 10. Per-target guides

- [LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md)
- [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) (CI host)
- [KUBERNETES](../deployments/KUBERNETES.md) (CI pipeline)
- [AZURE](../deployments/AZURE.md) (distribution + Intune, Commercial + Gov)
- [AWS](../deployments/AWS.md) (distribution + secrets, Commercial + GovCloud)
- [AIRGAPPED](../deployments/AIRGAPPED.md) (offline build + MDM)
