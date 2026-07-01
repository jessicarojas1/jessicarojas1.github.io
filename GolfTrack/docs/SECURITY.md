# Security — GolfTrack

GolfTrack is a **single-user, on-device** iOS / watchOS / macOS app with **no
accounts, no backend, and no cloud data**. Its threat surface is small: local
data at rest, two Apple-mediated network uses, and the paired-device (Watch /
Garmin) message boundary. The most valuable secrets are **developer signing
identities**, not user credentials.

Cross-links: [ARCHITECTURE](ARCHITECTURE.md) · [DEPLOYMENT](DEPLOYMENT.md) ·
[DISASTER_RECOVERY](DISASTER_RECOVERY.md).

---

## 1. Identity & authentication (reframed)

**The app has no user auth, no accounts, and no login.** There is nothing to sign
in to. "Identity" here means **developer / signing identity**:

- Apple Developer account (App Store Connect) — protect with 2FA + least-privilege roles.
- Distribution certificate + private key.
- App Store Connect API key.
- Garmin developer key.

See §8 for operator responsibilities and §9 for rotation.

## 2. Authorization

No RBAC/permissions inside the app (single user, single device). The only
authorization surfaces are **OS permission prompts**:

| Permission | Prompt key | Scope (least privilege) |
|-----------|-----------|-------------------------|
| Location | `NSLocationWhenInUseUsageDescription` | **When-in-use only** (not Always) — for Nearby Courses |
| Apple Music / now-playing | `NSAppleMusicUsageDescription` | Read now-playing / control playback |
| Music app URL schemes | `LSApplicationQueriesSchemes` | Limited to `spotify`, `youtubemusic`, `amznmusic`, `tidal` |

Do not request Always-location, background modes, or additional schemes without a
concrete need.

## 3. Data protection

| Data | Storage | Protection |
|------|---------|-----------|
| Rounds, hole scores, custom courses | SwiftData store in the app sandbox | iOS Data Protection (file encrypted at rest; default class ties to device passcode) |
| Any future secrets | **Keychain** (Data Protection keychain) | None stored today — app holds no credentials/tokens |
| In transit | MapKit search + music URL schemes only | TLS via ATS (default) |

- The SwiftData store inherits **iOS Data Protection**; keep the default
  `NSFileProtectionComplete`-class behavior — do not downgrade file protection.
- **No secrets are embedded in the app bundle.** There are no API keys, tokens,
  or credentials in the code or resources.
- If credentials are ever added, use the **Keychain**, never `UserDefaults` or
  the SwiftData store.

## 4. Transport security (ATS/TLS)

- App Transport Security is left at **default** — TLS is enforced; no cleartext
  (`NSAllowsArbitraryLoads`) exceptions.
- Only two things touch the network: **MapKit `MKLocalSearch`** (Apple-mediated,
  TLS) and **music-app URL schemes** (local hand-off, not a network call).
- No custom sockets, no analytics beacons, no third-party SDK traffic.

## 5. Auditability

There is no server audit log (no server). Auditability is limited to:

- On-device `os_log` (developer-visible via Console/Xcode) — must contain **no
  PII**.
- App Store Connect access logs for the developer account.
- Secrets-manager access logs for signing assets (see
  [AWS](../deployments/AWS.md) / [AZURE](../deployments/AZURE.md)).

## 6. Classification & DLP

- **No PII leaves the device.** Golf scores/handicap are user-owned, on-device
  only; nothing is transmitted to a backend (there is none).
- No CUI/regulated data is handled by the app itself. If distributed via a gov
  MDM, the *distribution pipeline* (not the app) is the boundary — see
  [AZURE](../deployments/AZURE.md) / [AWS](../deployments/AWS.md).

## 7. Device message trust boundary (Watch / Garmin)

The iPhone ↔ Watch (`WCSession`) and iPhone ↔ Garmin (`Comm.transmit`) channels
carry the plain score/hole dictionary (see [ARCHITECTURE](ARCHITECTURE.md) §7).

- These run over the **local, OS-brokered, paired** Bluetooth/Wi-Fi link —
  WatchConnectivity requires an Apple-paired Watch; Garmin messaging requires a
  paired Connect IQ device. No open network listener is created.
- Payload is **non-sensitive** (hole number, par, strokes, putts, score) — no
  credentials or PII cross this boundary.
- Treat inbound `action` values (`nextHole`, `prevHole`, `scoreUpdate`,
  `puttUpdate`) as untrusted input: the app switches on known actions and ignores
  unknown ones (default case) — keep it that way; do not `eval` or reflect
  arbitrary keys.

## 8. FIPS readiness

- The app performs **no custom cryptography**. Any crypto is provided by iOS
  **CoreCrypto**, whose modules hold **FIPS 140-2/140-3** validation under Apple's
  program. For a FIPS posture, run on a supported iOS version and rely on OS
  crypto — do not roll your own.
- TLS is provided by the OS network stack (Secure Transport / Network.framework).

## 9. Operator responsibilities

- Protect **signing keys**: store `.p12` / `.p8` in a managed secrets vault with
  access logging; never commit them; back them up (see
  [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).
- Enforce **App Store Connect 2FA** and least-privilege roles (App Manager, not
  Account Holder, for CI).
- Prefer **workload identity / IAM roles** over static keys in CI.
- Keep `LSApplicationQueriesSchemes` and permission prompts to the minimum set.
- Disable debug logging in Release builds.

## 10. Secrets rotation

| Secret | Rotate when | How |
|--------|-------------|-----|
| App Store Connect API key | Compromise / annually | Revoke + create new `.p8`; update vault |
| Distribution certificate | Expiry (yearly) / compromise | Reissue in portal; re-import; update `.p12` in vault |
| Provisioning profiles | Cert change / device changes | Regenerate; redistribute |
| Garmin developer key | Compromise | Reissue via CIQ SDK |

## 11. Reporting (vulnerability disclosure)

- Report suspected vulnerabilities to the maintainer:
  **cuevasjessica40@yahoo.com** (project contact).
- Target acknowledgement within **3 business days**; triage/severity within **10
  business days**.
- Do not file exploit details in public issues; use the email channel.
- Fixes ship via a standard App Store update (phased release) — see
  [DEPLOYMENT](DEPLOYMENT.md).
