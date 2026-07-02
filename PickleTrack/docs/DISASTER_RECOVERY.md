# PickleTrack — Disaster Recovery

PickleTrack has no servers, databases, or object storage to fail over. The recoverable
artifact is **the app itself, rebuilt from git**, and the irreplaceable state is the
**Apple code-signing identity and App Store record**. This document treats DR as protecting
those, plus an honest note about on-device user data.

Related: [SECURITY.md](SECURITY.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [ARCHITECTURE.md](ARCHITECTURE.md)

---

## 1. What holds state

| State | Location | Recoverable? | Criticality |
|-------|----------|--------------|-------------|
| Source code | Git (`jessicarojas1.github.io` repo, `PickleTrack/`) | Yes — clone/rebuild | Low (replaceable) |
| Build artifact (`.ipa`/`.app`) | Rebuildable from source | Yes — `xcodebuild` | Low |
| **Distribution certificate + private key** | Keychain / secrets manager | **Only if backed up** | **Critical** |
| **Provisioning profiles** | Apple Developer / secrets manager | Regenerable if cert survives | High |
| **App Store Connect API key (`.p8`)** | Downloaded once, then unrecoverable from Apple | **Only if backed up** | **Critical** |
| **Bundle ID + App Store record** | App Store Connect account | Tied to the Apple account | **Critical** |
| **User data (matches, games, stats)** | On-device SwiftData store | Only via the user's device/iCloud backup | Out of our control |

> **User data note:** all match/stat data lives **only on the user's device** in SwiftData.
> There is **no cloud backup, no CloudKit sync today** — if the user deletes the app or loses
> the device without an iCloud/iTunes device backup, their data is gone. This is a product
> limitation tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md), not something operators can restore.

---

## 2. RPO / RTO targets

Framed for a code + signing project (not a live service):

| Scenario | RPO (data loss) | RTO (time to recover) |
|----------|-----------------|------------------------|
| Lost dev machine, source in git | 0 (last push) | Minutes — clone + open in Xcode |
| Lost signing cert **with** backup | 0 | ~1 hour — import `.p12`, re-archive |
| Lost signing cert **without** backup | 0 (code intact) | Hours–1 day — revoke, re-issue cert, re-provision |
| Lost App Store Connect API key | 0 | Minutes — generate a new key (old one revoked) |
| Compromised Apple account | n/a | Depends on Apple support — protect with 2FA/roles |
| End-user device loss | User's iCloud backup interval, else total | Not operator-recoverable |

---

## 3. Backups

What to back up (code is already in git; focus on signing/identity):

| Item | How to back up | Where | Encryption |
|------|----------------|-------|------------|
| Source | `git push` to the remote | GitHub | TLS in transit |
| Distribution cert `.p12` (cert + private key) | `Keychain Access → Export` as `.p12` | AWS Secrets Manager / Azure Key Vault | KMS/Key Vault at rest + strong `.p12` password |
| Provisioning profiles | Store `.mobileprovision` files | Same secrets manager | at rest |
| App Store Connect `.p8` key | Save immediately at creation (Apple won't re-download) | Same secrets manager | at rest |
| Key metadata | Record key id + issuer id | Secrets manager / password vault | — |
| Export options / build config | Committed in repo | Git | — |

```bash
# Export the distribution identity to a password-protected .p12 (then upload to a vault)
security find-identity -v -p codesigning          # find the identity
# In Keychain Access: select the cert + its private key → Export → .p12 (set a strong password)
# Store in a secrets manager, NOT in git:
aws secretsmanager put-secret-value --secret-id pickletrack/signing \
  --secret-binary fileb://dist.p12
```

---

## 4. Restore runbook

Numbered, copy-pasteable. Goal: rebuild and re-submit a signed app after loss.

**A. Restore source & build environment**
```bash
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io/PickleTrack
# Ensure Xcode 15+ / Swift 5.9 on macOS
swift build            # sanity compile
```

**B. Restore signing identity**
```bash
# Retrieve the backed-up .p12 from the secrets manager
aws secretsmanager get-secret-value --secret-id pickletrack/signing \
  --query SecretBinary --output text | base64 --decode > dist.p12
# Import into a keychain
security create-keychain -p "$KC_PASS" build.keychain
security import dist.p12 -k build.keychain -P "$P12_PASS" -T /usr/bin/codesign
security list-keychains -s build.keychain
```

**C. If the cert was NOT backed up (re-issue)**
1. Sign in to the Apple Developer portal → **Certificates** → revoke the lost cert.
2. Create a new **Apple Distribution** certificate (generate a CSR from Keychain Access).
3. Regenerate the provisioning profile(s) bound to the bundle id.
4. Download and re-import as in step B; back up the new `.p12` immediately.

**D. Restore the App Store Connect API key (if lost)**
1. App Store Connect → **Users and Access → Integrations → App Store Connect API**.
2. Revoke the missing key; **generate a new key** (download the `.p8` now — one chance).
3. Record the new key id + issuer id; store the `.p8` in the secrets manager.

**E. Rebuild, sign, re-submit**
```bash
xcodebuild -scheme PickleTrack -destination 'generic/platform=iOS' \
  -archivePath build/PickleTrack.xcarchive archive
xcodebuild -exportArchive -archivePath build/PickleTrack.xcarchive \
  -exportOptionsPlist ExportOptions.plist -exportPath build/export
xcrun altool --upload-app -f build/export/PickleTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

**F. Clean up**
```bash
security delete-keychain build.keychain
rm -f dist.p12
```

---

## 5. Verification cadence

| Drill | Frequency | Pass criteria |
|-------|-----------|---------------|
| **Signing restore drill** | Quarterly | Import backed-up `.p12` into a clean keychain, produce a signed archive |
| Clean rebuild from git | On each release | `xcodebuild archive` succeeds from a fresh clone |
| API key validity | Before each release cycle | TestFlight upload authenticates |
| Backup integrity | Quarterly | Secrets manager entries decrypt and match expected identities |
| Cert expiry watch | Monthly | Distribution cert > 30 days from expiry |

---

## 6. High availability

Reframed — there is nothing to run, so nothing to make HA:

- The **App Store CDN** hosts and delivers the app globally; Apple provides its availability.
- No servers, load balancers, replicas, or failover to manage.
- "Availability" of new releases depends on Apple review + the developer's ability to rebuild
  and re-submit — which the restore runbook above guarantees as long as signing assets are
  backed up.
