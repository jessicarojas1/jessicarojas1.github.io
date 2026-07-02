# PickleTrack — Single Linux Server

> **Applicability: N/A.** PickleTrack is an **on-device iOS/macOS app**. It does not run
> as a service on a Linux server — there is no HTTP listener, database, or daemon to host.
> This document therefore covers the **nearest real equivalent**: using a single host as a
> **build/test/CI machine** for the project. Two flavors are described:
>
> 1. A **headless Linux host** running SwiftPM (`swift:slim`) as a *compile-check* gate.
> 2. A **self-hosted macOS runner** — the only host that can produce real app archives /
>    TestFlight builds via `xcodebuild`.

Related: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) · [KUBERNETES.md](KUBERNETES.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

| Host type | OS | Capability | Cannot do |
|-----------|----|-----------|-----------|
| **Linux CI host** | Ubuntu 22.04 + `swift:slim` | `swift build` / `swift test` = platform-agnostic compile check + package resolution | Cannot compile Apple frameworks (SwiftUI/SwiftData/MapKit/CoreLocation) or produce an `.app`/`.ipa` |
| **macOS runner** | macOS 14 + Xcode 15 | `xcodebuild` build/test/archive, Simulator tests, code signing, TestFlight upload | — |

Because Apple frameworks are unavailable on Linux, the Linux host is a **fast fail-early
gate** (does the Swift compile / does the package resolve), not an app builder. Anything
that ships to a device/store must run on the macOS runner.

---

## 2. Topology

```
        developer push / PR
               │
   ┌───────────┴────────────┐
   ▼                        ▼
Linux CI host           macOS runner (self-hosted)
(swift:slim)            Xcode 15 + toolchain
   │                        │
 swift build             xcodebuild build/test
 swift test              xcodebuild -archivePath ... archive
   │                        │
 compile-check ✅        signed .xcarchive ─▶ Export .ipa ─▶ TestFlight
                            │
                            └─ signing assets from a secrets manager
                               (see AWS.md / AZURE.md)
```

No inbound traffic, no listening ports, no TLS termination — these are outbound build
runners, not servers.

---

## 3. Prerequisites

**Linux CI host**

| Requirement | Version |
|-------------|---------|
| OS | Ubuntu 22.04 LTS (or Debian slim) |
| Docker | 24+ (to run `swift:slim`) or a native Swift 5.9 Linux toolchain |
| Git | any recent |

**macOS runner**

| Requirement | Version |
|-------------|---------|
| macOS | 14 (Sonoma) |
| Xcode | 15+ (includes Swift 5.9, iOS 17 SDK) |
| Command Line Tools | matching Xcode |
| Apple Developer Program | paid, for signing/TestFlight |
| Runner agent | GitHub Actions self-hosted runner / GitLab runner / Jenkins agent |

---

## 4. Identity & credentials

- **Linux CI host:** no credentials required — it only compiles. Prefer running the
  build in an unprivileged container (`swift:slim` as a non-root user, see
  [../Dockerfile](../Dockerfile)).
- **macOS runner:** needs Apple **code-signing identities** (distribution certificate +
  private key), **provisioning profiles**, and an **App Store Connect API key** to upload.
  Store these in a secrets manager (AWS Secrets Manager / Azure Key Vault) and inject at
  build time via a short-lived cloud **role**; never commit them. Import into a temporary
  keychain per build and delete it afterward:

```bash
security create-keychain -p "$KC_PASS" build.keychain
security import dist.p12 -k build.keychain -P "$P12_PASS" -T /usr/bin/codesign
security set-keychain-settings -lut 3600 build.keychain   # auto-lock, short-lived
# ... xcodebuild archive/export ...
security delete-keychain build.keychain
```

---

## 5. Environment variables

Runners are configured by the CI system, not by the app. Relevant build-side variables:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Select Xcode on the macOS runner |
| `ASC_KEY_ID` | `2X9ABC1234` | App Store Connect API key id (from secrets manager) |
| `ASC_ISSUER_ID` | `69a6de70-...` | App Store Connect issuer id |
| `ASC_KEY_PATH` | `/run/secrets/AuthKey.p8` | Path to the injected `.p8` key |
| `KC_PASS` / `P12_PASS` | *(secret)* | Temporary keychain + `.p12` import passwords |

The app itself reads **no environment variables at runtime**.

---

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Scheme | `PickleTrack` | `xcodebuild -scheme PickleTrack` |
| Destination (test) | `platform=iOS Simulator,name=iPhone 15` | Simulator target for CI tests |
| Archive path | `build/PickleTrack.xcarchive` | Output of `xcodebuild archive` |
| Export options plist | `ExportOptions.plist` | Method (`app-store` / `ad-hoc` / `enterprise`) + team id |

---

## 7. Verification

> No health endpoint, login, upload, or DB — verification is "the build gate passes."

**Linux CI host:**

```bash
docker run --rm -v "$PWD":/src -w /src swiftlang/swift:5.9-slim \
  bash -lc 'swift build && swift test'
# Expect: compile succeeds; swift test reports no tests (no Tests/ target yet)
```

**macOS runner:**

```bash
xcodebuild -scheme PickleTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' clean build test
xcodebuild -scheme PickleTrack -archivePath build/PickleTrack.xcarchive archive
```

- [ ] Linux compile-check passes
- [ ] macOS build + Simulator run succeeds; key screens render (see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md#7-verification))
- [ ] Archive produced and signable

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update toolchain | Pull a newer `swift:slim` tag; install newer Xcode on the macOS runner |
| Rotate signing assets | Re-import from the secrets manager (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |
| Clean runner disk | Purge `~/Library/Developer/Xcode/DerivedData` and old keychains |
| Patch OS | Standard apt/macOS updates; keep Xcode ≥ 15 |
| Backups | Nothing app-stateful to back up; back up **signing assets** off-host |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Linux build: "no such module 'SwiftUI'" | Apple frameworks unavailable on Linux | Expected — use the macOS runner for app builds |
| `xcodebuild` "No signing certificate" | Signing assets not imported | Import `.p12` into a temp keychain from secrets manager |
| Archive uploads fail (401) | App Store Connect API key expired/rotated | Refresh `.p8`/key id from secrets manager |
| Runner out of disk | DerivedData / archives accumulate | Clean DerivedData; prune old `.xcarchive` |
| `swift test` "no tests" | No `Tests/` target | Expected; tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md) |
