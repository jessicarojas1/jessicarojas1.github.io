# Disaster Recovery — GolfTrack

GolfTrack is an on-device app with **no backend and no cloud data store**. The
recoverable artifact (the app binary) is fully **rebuildable from git**. The
genuinely irreplaceable state is the **code-signing identity and Apple developer
assets** — losing those, not losing a server, is the real disaster.

Cross-links: [DEPLOYMENT](DEPLOYMENT.md) · [SECURITY](SECURITY.md) ·
[AWS](../deployments/AWS.md) / [AZURE](../deployments/AZURE.md) (secret custody).

---

## 1. What holds state

| State | Where | Recoverable? | Criticality |
|-------|-------|--------------|-------------|
| Source code | git (`jessicarojas1.github.io/GolfTrack`) | Yes — clone/rebuild | Low (replaceable) |
| App binary / archive | CI output | Yes — rebuild from a tag | Low |
| **Distribution certificate + private key** | Keychain / secrets vault | **Only if backed up** | **Critical** |
| **Provisioning profiles** | Apple Developer portal / vault | Regenerable if account intact | High |
| **App Store Connect API key (`.p8`)** | Secrets vault | Regenerable if account intact | High |
| **App Store record / bundle ID** | App Store Connect | Tied to Apple account | Critical |
| **Garmin developer key** | Offline/secure store | **Only if backed up** | High |
| User data (rounds, scores, custom courses) | On-device SwiftData (per device) | Only via user's iCloud/iTunes device backup | User-owned |

> **User data note:** rounds/scores/custom courses live **only on the user's
> device** in SwiftData. GolfTrack does **not** back them up to any cloud. A user
> recovers their data only if they had an iCloud or Finder/iTunes **device
> backup**. Server-side/CloudKit sync is **roadmap only** (Phase 4) and does not
> exist today.

## 2. RPO / RTO targets

Framed for a code + signing project (not a live service):

| Scenario | RPO | RTO | Basis |
|----------|-----|-----|-------|
| Source loss | 0 (git remote) | Minutes | `git clone` |
| CI/build host loss | 0 | < 1 day | Re-provision runner; rebuild |
| Signing identity loss (backed up) | 0 | < 1 hour | Import `.p12` from vault |
| Signing identity loss (NOT backed up) | n/a | 1–3 days | Revoke + reissue cert; re-provision; re-sign |
| Apple account compromise | n/a | Days | Apple support recovery; rotate keys |

There is **no user-data RPO/RTO** to own — data is on the user's device under
their control.

## 3. Backups

| Asset | How to back up | Where | Rotation |
|-------|----------------|-------|----------|
| Source | git remote (GitHub) | `github.com/jessicarojas1/...` | Continuous (push) |
| Distribution cert + key | Export `.p12` (password-protected) | Secrets Manager / Key Vault | On issue/renewal |
| Provisioning profiles | Store `.mobileprovision` | Secrets vault | On regeneration |
| ASC API key `.p8` | Store file + key ID + issuer ID | Secrets vault | On rotation |
| Garmin developer key | Copy `.der`/key file | Offline secure store | On issue |
| Export options / signing config | Commit `ExportOptions.plist` template (no secrets) | git | With code |

Encrypt exported `.p12` with a strong password; store the password separately
from the file. Prefer a managed secrets store with access logging (see
[AWS](../deployments/AWS.md) / [AZURE](../deployments/AZURE.md)).

## 4. Restore runbook (numbered, copy-pasteable)

**A. Rebuild the app from source**
```bash
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io/GolfTrack
swift build            # compile-check gate
# On macOS with Xcode:
xcodebuild -scheme GolfTrack -destination 'generic/platform=iOS' \
  -archivePath build/GolfTrack.xcarchive archive
```

**B. Restore signing identity (from backup)**
```bash
# 1. Retrieve the distribution cert from the secrets vault (AWS example)
aws secretsmanager get-secret-value --secret-id golftrack/signing \
  --query SecretString --output text > signing.json
# 2. Import the .p12 into the login Keychain on the macOS runner
security import dist_cert.p12 -k ~/Library/Keychains/login.keychain-db \
  -P "$P12_PASSWORD" -T /usr/bin/codesign
# 3. Install the provisioning profile
cp GolfTrack.mobileprovision \
  ~/Library/MobileDevice/Provisioning\ Profiles/
```

**C. Signing identity lost with NO backup**
1. Revoke the compromised/lost distribution certificate in the Apple Developer portal.
2. Create a new distribution certificate; download + import to the runner Keychain.
3. Regenerate provisioning profiles against the new cert; download + install.
4. Re-run archive + export (step A) with the new identity.
5. Back up the new `.p12` immediately (step 3 of §3).

**D. Re-submit / re-provision the app**
```bash
xcodebuild -exportArchive -archivePath build/GolfTrack.xcarchive \
  -exportOptionsPlist ExportOptions.plist -exportPath build/export
xcrun altool --upload-app -f build/export/GolfTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

**E. Garmin companion**
1. Restore the Garmin developer key from the offline store.
2. Rebuild `GarminApp/GolfTrack.mc` with the Connect IQ SDK; re-submit the `.iq`.

## 5. Verification cadence (restore drills)

| Drill | Cadence | Pass criteria |
|-------|---------|---------------|
| Rebuild from tag | Every release | Archive produced; app launches in Simulator |
| Signing restore | Quarterly | Import `.p12` from vault; sign a test archive successfully |
| Secret retrieval | Quarterly | Vault access via workload identity returns ASC key |
| Garmin key restore | Semi-annually | Rebuild + validate `.iq` |

## 6. High availability

Reframed: **there is nothing to run**, so there is nothing to make
highly-available. App availability post-distribution is provided by the **App
Store CDN** (Apple-operated); the Garmin companion by the **Connect IQ store**.
Your resilience obligation is limited to:

- Redundant, backed-up **signing assets** (single point of failure if lost).
- A reproducible build (git + pinned Xcode/Swift toolchain).
- More than one person/role with App Store Connect access (avoid bus-factor 1).
