# PickleTrack — Air-Gapped / Offline

> **Applicability:** PickleTrack is an on-device iOS/macOS app with **no external SwiftPM
> dependencies** (Apple frameworks only) and **no backend/AI service**. Air-gapped operation
> is therefore mostly about building and distributing **without public internet or the public
> App Store** — using an offline Xcode toolchain and internal MDM. There is essentially
> nothing to mirror on the package side.

Related: [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) · [../docs/SECURITY.md](../docs/SECURITY.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

| Concern | Air-gapped approach |
|---------|---------------------|
| SwiftPM dependencies | **None** — PickleTrack imports only Apple frameworks. `swift build` needs no network. Nothing to vendor/mirror. |
| Toolchain | Offline **Xcode 15+** installer + Simulator runtimes staged on the build Mac |
| Linux compile-check | `swift:slim` image mirrored to an internal registry |
| Distribution | Internal **MDM (Jamf / Microsoft Intune)** push of a signed `.ipa` — no public App Store |
| Build cache | Local DerivedData / SwiftPM cache on the isolated build host |
| AI / LLM (Ollama) | **N/A** — PickleTrack has no AI feature, so no self-hosted inference is needed |
| CVE / feeds | No third-party libs to scan; only OS/Xcode advisories apply |

---

## 2. Topology

```
 ┌──────────────── Air-gapped enclave (no public internet) ────────────────┐
 │                                                                          │
 │   Offline Xcode installer + Simulator runtimes                          │
 │            │                                                             │
 │            ▼                                                             │
 │   Build Mac (Xcode 15) ── xcodebuild archive/export ──▶ signed .ipa     │
 │            │                         │                                   │
 │            │                         ▼                                   │
 │            │                 Internal MDM (Jamf / Intune)                │
 │            │                         │                                   │
 │            │                         ▼                                   │
 │            │                 Enrolled iOS devices (installed offline)    │
 │            ▼                                                             │
 │   Internal registry ── swift:slim (mirrored) ──▶ Linux compile-check    │
 │                                                                          │
 │   Signing assets from an offline vault (HSM / sealed secrets)           │
 └──────────────────────────────────────────────────────────────────────────┘
      No egress. Apple MapKit court-finder is unavailable offline (degrades).
```

> **Runtime note:** the **court finder** (`MKLocalSearch`) needs Apple Maps connectivity and
> **will not return results in a fully air-gapped/offline environment** — the feature
> degrades gracefully and the rest of the app (scoring, history, stats, rules) works entirely
> offline against the local SwiftData store.

---

## 3. Prerequisites

| Requirement | Detail |
|-------------|--------|
| Offline Xcode 15+ | `.xip` staged on the build Mac (no App Store download) |
| Simulator runtimes | Pre-downloaded iOS 17 runtime `.dmg`/package |
| Internal registry | To host the mirrored `swift:slim` image (compile-check) |
| Internal MDM | Jamf Pro or Microsoft Intune (on-prem/gov tenant) |
| Offline signing vault | HSM or sealed secrets store for certs/keys/profiles |
| Apple enterprise/in-house program (optional) | For non-App-Store distribution of signed apps |

---

## 4. Identity & credentials

- **Build host:** no network identity needed for `swift build`; signing assets come from the
  **offline vault** and are imported into a temporary keychain per build, then deleted.
- **Distribution:** the internal MDM authenticates devices; the app itself has no accounts.
- Prefer short-lived / sealed secrets even offline; rotate signing certs on schedule
  (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).

| Asset | Offline source |
|-------|----------------|
| Distribution cert `.p12` + key | Offline vault / HSM |
| Provisioning profile | Generated on a connected host, sneakernet-imported |
| App Store Connect API key | Only needed if bridging to Apple; not used for pure MDM |

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Pin offline Xcode |
| `SWIFT_OFFLINE` | `1` | Signal build scripts to skip any network resolve (none needed) |
| `INTERNAL_REGISTRY` | `registry.enclave.local` | Mirrored `swift:slim` source |
| `MDM_ENDPOINT` | `https://jamf.enclave.local` | Internal MDM for distribution |

The app reads no environment variables at runtime.

---

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Package resolution | *offline* | No external deps → `swift build` needs no fetch |
| Export method | `enterprise` / `ad-hoc` | Non-App-Store distribution via MDM |
| `NSLocationWhenInUseUsageDescription` | *(court finder string)* | Required Info.plist key (feature degrades offline) |
| Compile-check image | `registry.enclave.local/swift:5.9-slim` | Mirrored toolchain |

---

## 7. Verification

> No health endpoint, login, upload, or DB. Verify offline build + internal distribution.

- [ ] `swift build` completes **with no network** (confirms zero external deps)
- [ ] Mirrored `swift:slim` compile-check Job runs from the internal registry
- [ ] `xcodebuild archive` + export produces a signed `.ipa` using offline-vault assets
- [ ] MDM (Jamf/Intune) installs the `.ipa` on an enrolled device with no internet
- [ ] App launches; scoring/history/stats/rules work offline; court finder degrades gracefully

```bash
# Prove the build needs no network (run with networking disabled)
swift build          # must succeed offline — no external SwiftPM deps
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update toolchain | Stage a new offline Xcode `.xip`; re-mirror `swift:slim` |
| Rotate signing | Re-issue cert from offline vault; sneakernet the new profile |
| Redistribute build | Push new `.ipa` version through internal MDM |
| Track OS advisories | Import Apple security notes manually into the enclave |
| Purge caches | Clean DerivedData / SwiftPM build cache on the build host |

No migrations, workers, or services. No Ollama/LLM to operate (no AI feature).

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `swift build` tries to reach network | Unexpected — PickleTrack has no deps | Confirm `Package.swift` has no `dependencies:`; nothing to fetch |
| Court finder empty offline | MapKit needs Apple services | Expected in air-gap; feature degrades, rest of app works |
| MDM install rejected | Wrong export method / device not enrolled | Use `enterprise`/`ad-hoc`; enroll device in MDM |
| Compile-check image pull fails | `swift:slim` not mirrored | Mirror it to `INTERNAL_REGISTRY` |
| Signing fails | Vault assets not imported | Import `.p12`/profile into a temp keychain before `xcodebuild` |
