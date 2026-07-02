# PickleTrack — Deployment Guide

Build → sign → distribute for an on-device iOS/macOS app. There is **no server to deploy**,
**no database migrations**, and **no background worker/queue**.

Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)

---

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Build commands](#4-build-commands)
5. [Sign & distribute](#5-sign--distribute)
6. [Migrations / worker / Ollama / GPU](#6-migrations--worker--ollama--gpu)
7. [Production checklist](#7-production-checklist)
8. [Target guides](#8-target-guides)

---

## 1. Deployment models

| Model | Audience | Channel |
|-------|----------|---------|
| **Simulator** | Developers | Xcode run / `xcodebuild ... -destination 'platform=iOS Simulator'` |
| **Development device** | Developers/testers | Xcode run on a signed device |
| **TestFlight** | Beta testers | App Store Connect internal/external testing |
| **App Store** | Public | App Store Connect submission + review |
| **Ad-Hoc** | Registered devices | Signed `.ipa`, UDID-provisioned |
| **Enterprise / MDM** | Managed fleets | Jamf / Microsoft Intune push of a signed `.ipa` |

There is **no managed-PaaS / single-server / k8s / airgapped app hosting** model — those
targets, where they appear in [../deployments/](../deployments), are reframed as CI/build or
distribution, not app hosting.

---

## 2. Prerequisites

| Requirement | Version |
|-------------|---------|
| macOS | 14 (Sonoma) recommended |
| Xcode | 15+ (Swift 5.9, iOS 17 SDK) |
| Apple Developer Program | Paid, for TestFlight/App Store/Ad-Hoc/enterprise |
| Signing assets | Distribution cert + private key, provisioning profile |
| App Store Connect API key | `.p8` + key id + issuer id (for automated upload) |
| (optional) Linux + `swift:slim` | Compile-check CI (see [../Dockerfile](../Dockerfile)) |

---

## 3. Configuration & secrets

- **App config** is bundle-level, not runtime: `NSLocationWhenInUseUsageDescription` in
  Info.plist (required), deployment targets, bundle id. The app reads **no env vars** and
  ships **no secrets** in the bundle.
- **Build/distribution secrets** (signing cert `.p12`, provisioning profile, App Store
  Connect `.p8`) live in a **secrets manager** (AWS Secrets Manager / Azure Key Vault) and
  are injected at build time via a short-lived **role/managed identity** — never committed.
  See [../deployments/AWS.md](../deployments/AWS.md) / [../deployments/AZURE.md](../deployments/AZURE.md).

---

## 4. Build commands

```bash
# SwiftPM compile check (macOS builds fully; Linux = compile check only — Apple frameworks
# do not exist on Linux, so this validates platform-agnostic Swift + package resolution)
swift build
swift test        # NOTE: no Tests/ target yet → reports "no tests"

# Full app build for a Simulator destination (macOS + Xcode)
xcodebuild -scheme PickleTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' clean build

# Run the (empty) test action against a Simulator
xcodebuild -scheme PickleTrack \
  -destination 'platform=iOS Simulator,name=iPhone 15' test
```

> Reminder: `swift build`/`swift test` on **Linux** only checks the Swift compiles and the
> package resolves. A runnable iOS/macOS app requires **Xcode/`xcodebuild` on macOS**.

---

## 5. Sign & distribute

```bash
# 1) Archive (macOS, signed with a distribution identity)
xcodebuild -scheme PickleTrack \
  -destination 'generic/platform=iOS' \
  -archivePath build/PickleTrack.xcarchive archive

# 2) Export a signed .ipa (method: app-store | ad-hoc | enterprise via ExportOptions.plist)
xcodebuild -exportArchive \
  -archivePath build/PickleTrack.xcarchive \
  -exportOptionsPlist ExportOptions.plist \
  -exportPath build/export

# 3) Upload to TestFlight / App Store Connect
xcrun altool --upload-app -f build/export/PickleTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
# (or notarize a macOS build:  xcrun notarytool submit ... --wait)
```

- **App Store:** submit the uploaded build for review in App Store Connect; use **phased
  release** for staged rollout.
- **Ad-Hoc / enterprise:** distribute the signed `.ipa` via MDM (Jamf/Intune) — see
  [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) for offline MDM.

---

## 6. Migrations / worker / Ollama / GPU

| Concern | Status |
|---------|--------|
| Database migrations | **None.** SwiftData handles lightweight on-device schema evolution automatically; there is no server DB and no migration command to run. |
| Background worker / queue / cron | **None.** No server-side jobs exist. |
| **Ollama / self-hosted LLM** | **N/A.** PickleTrack has no AI feature — no inference to host. |
| **GPU acceleration** | **N/A.** No ML/inference workload. (On-device rendering uses the OS GPU implicitly; nothing to configure.) |

---

## 7. Production checklist

### Secrets & identity
- [ ] Distribution certificate + private key stored in a secrets manager (not git)
- [ ] Provisioning profile current and matched to the bundle id
- [ ] App Store Connect API key (`.p8`) stored as a secret; key id/issuer set
- [ ] CI uses a short-lived **role / workload identity** to fetch signing secrets
- [ ] Temporary keychain created per build and deleted afterward

### Transport & exposure
- [ ] App Transport Security (ATS) defaults enforced — TLS only, for the **MapKit** call
- [ ] No arbitrary-loads / no ATS exceptions added
- [ ] No inbound surface (the app exposes no listener/endpoint)

### Hardening
- [ ] No secrets or API keys embedded in the bundle
- [ ] SwiftData store relies on iOS Data Protection; Keychain used for any future secrets
- [ ] Debug logging disabled in Release; no PII in logs
- [ ] `StrictConcurrency` clean (no data-race warnings)
- [ ] `NSLocationWhenInUseUsageDescription` present and accurate; least-privilege permissions only

### Resilience & operations
- [ ] Crash reporting decided/configured (currently none — see [../OPEN_ITEMS.md](../OPEN_ITEMS.md))
- [ ] Phased App Store release configured
- [ ] Signing-restore drill performed (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md))
- [ ] Privacy manifest / App Privacy details authored before submission

---

## 8. Target guides

| Target | Guide |
|--------|-------|
| Local dev | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |
| Linux CI / macOS runner | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes CI | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) |
| Azure (pipeline + Intune) | [../deployments/AZURE.md](../deployments/AZURE.md) |
| AWS (pipeline + distribution) | [../deployments/AWS.md](../deployments/AWS.md) |
| Air-gapped | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) |
