# Air-Gapped — GolfTrack

> **Applicability:** GolfTrack is an on-device app with **no backend** and — as
> verified — **no external SwiftPM dependencies** (Apple frameworks only). That
> makes the "offline dependency mirror" problem **trivial**: there is nothing to
> mirror for the Swift package. The real air-gapped concerns are: an offline
> **Xcode/toolchain + Simulator** install, offline **build caches**, internal
> **MDM distribution** without the public App Store, and **offline Garmin
> sideload**.
>
> **Ollama / self-hosted LLM: N/A.** GolfTrack has **no AI feature today**. The
> roadmap mentions a future "AI caddie" (Phase 5), but it does not exist, so there
> is no hosted AI API to replace and no Ollama to run. State this and move on.

Cross-links: [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) ·
[DEPLOYMENT](../docs/DEPLOYMENT.md) · [SECURITY](../docs/SECURITY.md) ·
[Dockerfile](../Dockerfile).

---

## 1. Deployment architecture

Everything runs inside the enclave: an offline macOS build host, an offline
secrets store for signing, and an internal MDM (Intune/Jamf) or manual device
provisioning for distribution — the public App Store is unreachable and not used.

| Concern | Air-gapped approach |
|---------|--------------------|
| SwiftPM deps | None to mirror — Apple frameworks only. `Package.swift` has zero `dependencies`. |
| Toolchain | Pre-download Xcode `.xip` + command-line tools; install offline on the Mac build host |
| Simulators | Bundle the iOS 17 / watchOS 10 Simulator runtimes into the offline Xcode image |
| Build cache | Vendor `.build/` and DerivedData caches; disable network fetches |
| Signing | Enterprise/in-house distribution cert + profile in an offline secrets vault |
| Distribution | Internal MDM (Intune/Jamf) or manual `ideviceinstaller`; no App Store |
| Garmin | Offline CIQ SDK + developer key; sideload `.prg` over USB/BT |
| AI/Ollama | N/A — no AI feature exists |

## 2. Topology

```
  ┌──────────────────────── Air-gapped enclave ────────────────────────┐
  │                                                                     │
  │  Offline Mac build host                                             │
  │    Xcode (.xip, offline) + Simulators                               │
  │    swift build (no network — zero external deps)                    │
  │    xcodebuild archive → sign (offline vault: cert + profile)        │
  │        │                                                             │
  │        ├─► Internal MDM (Intune/Jamf) ──► managed iOS/watchOS devices│
  │        └─► Manual install (ideviceinstaller) ──► device             │
  │                                                                     │
  │  Offline CIQ SDK ──► GolfTrack.prg ──► USB/BT sideload ──► Garmin   │
  └─────────────────────────────────────────────────────────────────────┘
   No internet. No App Store. No Ollama (no AI feature).
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Offline macOS host | macOS 14 + pre-staged Xcode 15 `.xip` + Simulator runtimes |
| In-house distribution cert + profile | Enterprise or org signing, exported offline |
| Internal MDM | Intune (on-prem connector) or Jamf Pro in-enclave |
| Offline CIQ SDK 4.x + developer key | Garmin sideload |
| `libimobiledevice` (optional) | Manual `.ipa` install over USB |

## 4. Identity & credentials

- **Signing:** in-house/enterprise distribution certificate + private key and
  provisioning profile, exported once and stored in the enclave's offline secrets
  vault (HSM or sealed `.p12`). No App Store Connect connectivity is required for
  enterprise/MDM distribution.
- **Garmin:** developer key file, offline.
- No API keys leave the enclave; no cloud secrets manager is reachable — use the
  offline vault.

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Offline Xcode |
| `DEVELOPMENT_TEAM` | `AB12CD34EF` | Enterprise signing team |
| `EXPORT_METHOD` | `enterprise` | In-house export (no App Store) |
| `SWIFTPM_DISABLE_NETWORK` | `1` | Guard against accidental fetches (none needed) |
| `CIQ_DEVKEY` | `/secure/developer_key.der` | Garmin signing key path |

## 6. Configuration references

Same on-device config as everywhere: Info.plist keys + WatchConnectivity
capability (see [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) §6). Nothing points at
network services except MapKit local search (which will simply return no results
offline) and music-app URL schemes.

> Offline behavior: Nearby Courses (MapKit `MKLocalSearch`) requires network and
> will be non-functional in the enclave — expected and acceptable; the rest of the
> app (scoring, stats, handicap, rules, Watch/Garmin sync) is fully offline.

## 7. Verification

No health/login/upload/object — **explicitly N/A**. Verify offline build +
install:

- [ ] `swift build` completes with **no network access** (proves zero external deps).
- [ ] `xcodebuild archive` + enterprise export produce a signed `.ipa` offline.
- [ ] MDM (or `ideviceinstaller`) installs the `.ipa` on an enclave device.
- [ ] App launches; Home/Active Round/Stats/Rules render offline.
- [ ] Nearby Courses degrades gracefully (empty result, no crash) with no network.
- [ ] Garmin `.prg` sideloads and displays hole/par/score.

```bash
# Prove no-network build (should succeed — zero external deps)
SWIFTPM_DISABLE_NETWORK=1 swift build
```

## 8. Day-2 operations

| Task | How |
|------|-----|
| Toolchain update | Bring a new Xcode `.xip` in via approved transfer; reinstall offline |
| Rotate signing | Generate new enterprise cert/profile outside; import to offline vault |
| Redistribute | Push new `.ipa` version via internal MDM |
| Garmin update | Rebuild `.prg` offline; re-sideload |
| Cache refresh | Re-vendor `.build`/DerivedData with new toolchain |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `swift build` tries to reach network | Someone added an external dep | Revert; keep zero-dependency policy |
| Enterprise app won't launch | Untrusted profile on device | Trust the MDM/enterprise profile in Settings |
| Nearby Courses empty | No network (expected in enclave) | Acceptable; feature needs MapKit connectivity |
| Xcode install fails offline | Missing Simulator runtime | Add the runtime `.dmg`/component to the offline bundle |
| Garmin sideload rejected | Missing/expired developer key | Re-provision offline CIQ developer key |
