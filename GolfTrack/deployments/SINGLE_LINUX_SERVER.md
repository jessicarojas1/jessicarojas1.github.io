# Single Linux Server — GolfTrack

> **Applicability: N/A for the app itself.** GolfTrack is an on-device iOS /
> watchOS / macOS app. It does **not** run as a Linux server workload — there is
> no HTTP service, no database server, and no backend to host on a VM.
>
> **Nearest real equivalent, documented here:** a headless **build/test host**.
> Two variants:
> 1. **Linux CI host** — SwiftPM (`swift:slim`) for platform-agnostic
>    compile/package checks only (Apple-framework code will not compile).
> 2. **Self-hosted macOS runner** — the *only* way to produce real
>    `xcodebuild` archives / TestFlight builds on your own hardware.

Cross-links: [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) ·
[KUBERNETES](KUBERNETES.md) (containerized CI) ·
[DEPLOYMENT](../docs/DEPLOYMENT.md) · [Dockerfile](../Dockerfile).

---

## 1. Deployment architecture

There is nothing to "deploy and serve." A single host serves one of two build
roles:

| Role | Host OS | Produces | Limits |
|------|---------|----------|--------|
| Linux compile-check host | Ubuntu 22.04 + Swift 5.9 | `swift build`/`swift test` pass/fail signal | Cannot compile Apple frameworks; cannot sign or archive an app |
| macOS build/archive runner | macOS 14 + Xcode 15 | Signed `.ipa`/`.xcarchive`, TestFlight uploads | Requires Apple hardware/VM licensing |

## 2. Topology

```
  Developer / Git push
         │
         ▼
  ┌──────────────────────┐        ┌──────────────────────────────┐
  │  Linux build host     │        │  Self-hosted macOS runner     │
  │  (systemd unit /       │        │  (launchd / CI agent)         │
  │   CI agent)            │        │                               │
  │  swift build           │        │  xcodebuild archive           │
  │  swift test            │        │  xcodebuild -exportArchive     │
  │  = compile-check only   │        │  xcrun altool / notarytool     │
  └──────────────────────┘        │        │                        │
                                    │        ▼                        │
                                    │  TestFlight / App Store Connect │
                                    └──────────────────────────────┘
```

## 3. Prerequisites

**Linux compile-check host**
| Tool | Version |
|------|---------|
| Ubuntu / Debian | 22.04 LTS |
| Swift toolchain | 5.9 (`swift.org` release or `swift:slim` image) |
| git | any recent |

**macOS archive runner**
| Tool | Version |
|------|---------|
| macOS | 14+ |
| Xcode | 15+ (command-line tools installed) |
| Apple Developer Program | paid ($99/yr) for distribution signing |
| CI agent | GitHub Actions runner / GitLab runner / Jenkins agent |

## 4. Identity & credentials

- **Linux host:** no app credentials; only git read access to the repo.
- **macOS runner:** holds **code-signing identities** (distribution certificate
  + private key in the login Keychain), **provisioning profiles**, and an **App
  Store Connect API key** (`.p8`). Prefer the API key (short-lived JWTs) over
  Apple-ID passwords. Store the `.p8`, key ID, and issuer ID in a secrets manager
  and inject at build time — never commit them.

Least-privilege guidance: the App Store Connect API key should use the **App
Manager** or a custom role limited to build upload, not Account Holder.

## 5. Environment variables

Linux compile-check host (systemd unit env):

| Variable | Example | Purpose |
|----------|---------|---------|
| `SWIFT_VERSION` | `5.9` | Pin toolchain |
| `CI` | `true` | Enable non-interactive mode |

macOS archive runner:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Active Xcode |
| `DEVELOPMENT_TEAM` | `AB12CD34EF` | Signing team ID |
| `ASC_KEY_ID` | `2X9R4HXF34` | App Store Connect API key ID |
| `ASC_ISSUER_ID` | `57246542-...` | App Store Connect issuer ID |
| `ASC_KEY_PATH` | `/run/secrets/AuthKey.p8` | Path to injected `.p8` (not committed) |

## 6. Configuration references

No runtime app config. Build config lives in the Xcode project (schemes,
`Info.plist`, entitlements) — see [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) §6.

## 7. Verification

No health endpoint, login, secrets-in-app, or upload-to-storage — **state that
explicitly**. Verify the build role instead.

**Linux compile-check host** (systemd oneshot):
```bash
swift build      # package resolves + compiles platform-agnostic Swift
swift test       # currently: no Tests/ target → "no tests" is expected/OK
```

**macOS runner** (authoritative artifact):
```bash
xcodebuild -scheme GolfTrack \
  -destination 'generic/platform=iOS' \
  -archivePath build/GolfTrack.xcarchive archive
xcodebuild -exportArchive -archivePath build/GolfTrack.xcarchive \
  -exportOptionsPlist ExportOptions.plist -exportPath build/export
```

Acceptance: exit code 0; archive produced; (optional) TestFlight upload accepted.

### systemd oneshot example (Linux compile-check)
```ini
# /etc/systemd/system/golftrack-ci.service
[Unit]
Description=GolfTrack SwiftPM compile check
After=network.target

[Service]
Type=oneshot
User=ci
WorkingDirectory=/opt/golftrack
ExecStart=/usr/bin/swift build
ExecStartPost=/usr/bin/swift test

[Install]
WantedBy=multi-user.target
```
Trigger on a timer or from a webhook; treat non-zero exit as a failed gate.

## 8. Day-2 operations

| Task | Linux host | macOS runner |
|------|-----------|--------------|
| Toolchain upgrade | Update Swift package/image | Install new Xcode; `xcode-select -s` |
| Rotate signing | n/a | Rotate cert/profile/API key in Keychain + secrets manager |
| Logs | `journalctl -u golftrack-ci` | CI agent logs / `~/Library/Logs` |
| Clean cache | `rm -rf .build` | `xcodebuild clean`; clear DerivedData |
| Backups | none needed (rebuildable) | Back up signing identities — see [DISASTER_RECOVERY](../docs/DISASTER_RECOVERY.md) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Build fails: `no such module 'SwiftUI'` | On Linux | Expected; use macOS runner for real builds |
| `swift test` reports no tests | No `Tests/` target | Expected; see [OPEN_ITEMS](../OPEN_ITEMS.md) |
| `archive` fails: no signing identity | Cert/profile missing on runner | Import distribution cert + profile; set Team |
| `altool` auth failure | Bad/expired API key | Rotate `.p8`; verify key ID + issuer ID |
| Runner offline | Agent not registered | Re-register CI agent; check network |
