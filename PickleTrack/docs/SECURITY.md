# PickleTrack — Security Guide

PickleTrack is an on-device app with **no user accounts, no backend, and no data leaving the
device** except an anonymous Apple Maps court search. Its security posture is therefore about
**on-device data protection, permission minimalism, transport safety, and protecting the
developer signing identity** — not server auth or RBAC.

Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [DEPLOYMENT.md](DEPLOYMENT.md)

---

## 1. Identity & authentication

- **The app has no user authentication or accounts.** There is nothing to log into; all
  features work offline against the local store.
- "Identity" in this project means the **developer / code-signing identity** — the Apple
  Distribution certificate, provisioning profiles, and App Store Connect access that authorize
  builds. Protecting these is the primary identity concern (see §7, §8).

---

## 2. Authorization

- **No in-app roles or permissions** — there is no multi-user model, so there is no RBAC.
- The only "authorization" is the **OS permission prompt** for location (when-in-use),
  granted or denied by the user (see §5).

---

## 3. Data protection

| Data | At rest | In transit |
|------|---------|------------|
| Matches / games / stats | SwiftData store inside the app sandbox, covered by **iOS Data Protection** (encrypted with the device passcode/Secure Enclave key hierarchy) | Never transmitted |
| Court search query | Not persisted | Apple Maps over TLS (ATS) |
| Credentials | **None stored** today | — |

- The SwiftData store is a sandboxed on-device file; use the default (or stricter) Data
  Protection class so it is encrypted at rest and inaccessible while the device is locked.
- **Keychain** is the correct home for any secret the app might store in future (none today);
  do not put secrets in `UserDefaults` or the SwiftData store.
- **No secrets are embedded in the app bundle** — no API keys, tokens, or credentials ship
  with the binary.

---

## 4. Auditability

- **App-local audit:** the `PointEvent` log is a per-match audit trail of every scored point
  and serve-state change (used for exact undo) — an integrity feature, not a security log.
- There is **no server-side audit log** because there is no server.
- **Distribution audit:** signing-secret access should be logged by the secrets manager
  (CloudTrail on `GetSecretValue` / Key Vault diagnostic logs) — see
  [../deployments/AWS.md](../deployments/AWS.md) / [../deployments/AZURE.md](../deployments/AZURE.md).

---

## 5. Privacy, permissions & classification

- **Least-privilege permissions:** the app requests **only** when-in-use location
  (`NSLocationWhenInUseUsageDescription`), used solely to find nearby courts. No background
  location, no always-on, no motion, no contacts, no photos.
- **No tracking, no analytics, no PII exfiltration** — no data leaves the device except the
  anonymous MapKit search term ("pickleball courts") and the coarse location needed to scope
  it.
- **Data classification:** user data is personal but low-sensitivity (scores, names the user
  types) and stays on-device — no CUI/regulated-data handling and no DLP surface.
- **Before App Store submission:** author the **Privacy Manifest** and **App Privacy**
  ("Nutrition Label") details (location = app functionality, not tracking). Tracked in
  [../OPEN_ITEMS.md](../OPEN_ITEMS.md).

---

## 6. Transport & FIPS readiness

- **App Transport Security (ATS)** defaults are enforced — the only network path (MapKit
  `MKLocalSearch`) uses TLS; add **no** ATS exceptions / arbitrary-loads.
- **FIPS readiness:** cryptography is provided by the OS — Apple **CoreCrypto** underpins TLS
  and Data Protection and has FIPS 140-validated modules per iOS release. The app implements
  no custom crypto, so FIPS posture follows the platform; document the target iOS version's
  CoreCrypto validation status when a FIPS claim is required.

---

## 7. Operator responsibilities

- Protect the **distribution certificate + private key** and **App Store Connect API key**;
  store them in a secrets manager, never in git (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- Enforce **2FA** on the Apple Developer / App Store Connect account and use
  **least-privilege roles** (e.g. limit who has Admin vs Developer vs App Manager).
- Ensure CI fetches signing secrets via a short-lived **role / managed identity**, not static
  keys, and deletes temporary keychains after each build.
- Keep Xcode/toolchain patched; review `StrictConcurrency` and sanitizer output.

---

## 8. Secrets rotation

| Secret | Rotate when | How |
|--------|-------------|-----|
| App Store Connect API `.p8` key | On team change / suspected exposure / annually | Revoke + generate a new key in App Store Connect; update the secrets manager |
| Distribution certificate | Before expiry (max ~1 year) or on key exposure | Re-issue in the Developer portal; back up the new `.p12` |
| Provisioning profiles | On cert/device changes or expiry | Regenerate; update the secrets manager |

Rotation must not require code changes — swap the secret in the manager and re-run the
pipeline.

---

## 9. Reporting (vulnerability disclosure)

- Report suspected security issues to the maintainer:
  **cuevasjessica40@yahoo.com** (repo owner: `github.com/jessicarojas1`).
- Target acknowledgement **SLA: 5 business days**; fixes triaged by severity and shipped via a
  standard App Store update / phased release.
- Please include reproduction steps and affected app/iOS versions. Do not include real
  personal data in reports.
