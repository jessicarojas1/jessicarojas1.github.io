# PickleTrack — Azure (Commercial + Azure Government)

> **Applicability: distribution + optional CI pipeline, not app hosting.** PickleTrack is an
> on-device iOS/macOS app — Azure does **not** host the running app and there is **no
> companion backend**. Azure's real role here is: (a) hosting the **build/signing pipeline**
> (Azure Pipelines + a macOS agent), (b) storing **signing secrets in Azure Key Vault** via
> **workload / managed identity**, and (c) **enterprise/MDM distribution via Microsoft
> Intune**. Public app distribution still goes through Apple **TestFlight / App Store
> Connect**, which is global and not Azure-partitioned.

Related: [AWS.md](AWS.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

| Concern | Azure service | Notes |
|---------|---------------|-------|
| CI orchestration | **Azure DevOps Pipelines** / GitHub Actions | Triggers builds on push/PR |
| App build + sign + archive | **macOS agent** (self-hosted or Azure DevOps macOS-hosted) | Only macOS can run `xcodebuild` |
| Secret storage | **Azure Key Vault** | ASC API key, `.p12` cert, provisioning profiles |
| Pipeline identity | **Microsoft Entra Workload Identity / Managed Identity** | OIDC federation — prefer over static keys |
| Public distribution | Apple **TestFlight / App Store Connect** | Global; not an Azure resource |
| Enterprise / managed distribution | **Microsoft Intune** (MDM) | Push the signed `.ipa` to enrolled devices |

---

## 2. Topology

```
  Git push
     │
     ▼
 Azure DevOps Pipeline ──(OIDC/workload identity)──▶ Azure Key Vault
     │                                                  │  ASC .p8 key
     │                                                  │  distribution .p12
     │                                                  │  provisioning profile
     ▼                                                  ▼
 macOS build agent ◀──────── injects signing secrets ──┘
     │
     ├─ xcodebuild archive + export (.ipa)
     │
     ├──▶ Apple TestFlight / App Store Connect   (public / beta — global)
     └──▶ Microsoft Intune (Commercial or Gov tenant)  ──▶ managed iOS devices
```

---

## 3. Prerequisites

| Requirement | Detail |
|-------------|--------|
| Azure subscription | Commercial **or** Azure Government |
| Azure DevOps / GitHub | Pipeline definition |
| macOS build agent | Xcode 15+, Swift 5.9 (self-hosted or hosted pool) |
| Apple Developer Program | Team enrolled; App Store Connect access |
| Microsoft Intune | Licensed tenant for MDM distribution |
| Key Vault | One vault (Commercial or Gov cloud) |
| Entra ID app registration | For workload-identity federation to the pipeline |

---

## 4. Identity & credentials

**Prefer workload identity / managed identity over static keys.**

- Federate the pipeline's service connection to an **Entra ID** app registration using
  **OIDC workload identity federation** — no client secret stored in the pipeline.
- Grant that identity **least-privilege** access to Key Vault: `get`/`list` on secrets only.

Example Key Vault access policy (RBAC role assignment):

| Principal | Role | Scope |
|-----------|------|-------|
| Pipeline workload identity | **Key Vault Secrets User** | The single vault holding signing assets |

Secrets stored (never in git):

| Secret name | Contents |
|-------------|----------|
| `asc-api-key` | App Store Connect `.p8` key (base64) |
| `asc-key-id` / `asc-issuer-id` | ASC key metadata |
| `ios-dist-cert` | Distribution certificate `.p12` (base64) |
| `ios-provisioning` | Provisioning profile (base64) |
| `p12-password` | Import password for the `.p12` |

---

## 5. Environment variables

`Variable | Example | Purpose` — pipeline/build-time only (the app reads none at runtime).
Commercial vs Government differ mainly in Key Vault / Intune **endpoints and tenant**, not
in the Apple distribution path.

**Commercial**

| Variable | Example | Purpose |
|----------|---------|---------|
| `AZURE_KEYVAULT_URI` | `https://pt-signing.vault.azure.net/` | Vault holding signing secrets |
| `AZURE_TENANT_ID` | `<entra-tenant-guid>` | Entra tenant for OIDC federation |
| `ASC_KEY_ID` | `2X9ABC1234` | App Store Connect key id |
| `ASC_ISSUER_ID` | `69a6de70-...` | App Store Connect issuer id |
| `INTUNE_GRAPH_ENDPOINT` | `https://graph.microsoft.com` | Intune app upload (MS Graph) |

**Azure Government**

| Variable | Example | Purpose |
|----------|---------|---------|
| `AZURE_KEYVAULT_URI` | `https://pt-signing.vault.usgovcloudapi.net/` | Gov-cloud vault endpoint |
| `AZURE_TENANT_ID` | `<gov-entra-tenant-guid>` | Gov tenant |
| `AZURE_AUTHORITY_HOST` | `https://login.microsoftonline.us` | Gov Entra authority |
| `INTUNE_GRAPH_ENDPOINT` | `https://graph.microsoft.us` | Gov MS Graph for Intune |
| `ASC_KEY_ID` / `ASC_ISSUER_ID` | *(same)* | Apple App Store Connect is **global** — same regardless of Azure partition |

> The Apple side (TestFlight / App Store Connect API) is identical in both — Apple has no
> gov partition. Only Azure Key Vault + Intune endpoints/tenant change.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| Scheme | `PickleTrack` | `xcodebuild -scheme PickleTrack` |
| Export method | `app-store` / `ad-hoc` / `enterprise` | ExportOptions.plist distribution method |
| Bundle Identifier | `com.yourorg.pickletrack` | App identity |
| Intune app type | `iOS line-of-business (.ipa)` | For MDM distribution |
| `NSLocationWhenInUseUsageDescription` | *(court finder string)* | Required Info.plist key |

---

## 7. Verification

> No health endpoint, login, upload-to-storage, or DB to check — those don't exist. Verify
> the **pipeline** end-to-end instead.

- [ ] Pipeline authenticates to Key Vault via workload identity (no static secret in logs)
- [ ] Signing secrets resolve from Key Vault into a temporary keychain
- [ ] `xcodebuild archive` + export produces a signed `.ipa`
- [ ] Upload to **TestFlight** succeeds (build appears in App Store Connect)
- [ ] (MDM) `.ipa` uploads to **Intune** and installs on an enrolled test device
- [ ] App launches; key screens render (see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md#7-verification))

```bash
# Resolve a secret via managed identity (illustrative)
az keyvault secret show --vault-name pt-signing --name asc-key-id --query value -o tsv
# Upload to TestFlight
xcrun altool --upload-app -f build/PickleTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Rotate signing cert / ASC key | Update the Key Vault secret; next pipeline run picks it up (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |
| Rotate provisioning profile | Regenerate in App Store Connect; update `ios-provisioning` secret |
| Phased App Store release | Configure in App Store Connect (7-day phased rollout) |
| Update macOS agent | Install newer Xcode; keep ≥ 15 |
| Move to Gov tenant | Repoint Key Vault + Intune/Graph endpoints per the Gov table |
| Audit secret access | Key Vault diagnostic logs → Log Analytics |

No migrations, workers, queues, or scaling — nothing runs continuously.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pipeline can't read Key Vault | Missing role / wrong tenant | Assign **Key Vault Secrets User**; verify Commercial vs Gov `AZURE_TENANT_ID` |
| TestFlight upload 401 | ASC key expired/rotated | Refresh `.p8` in Key Vault |
| Intune install fails | Wrong export method / device not enrolled | Use `enterprise`/`ad-hoc` profile; confirm MDM enrollment |
| Gov auth fails | Commercial authority used | Set `AZURE_AUTHORITY_HOST=https://login.microsoftonline.us` |
| Signing fails on agent | `.p12` not imported | Import into a temp keychain from Key Vault before `xcodebuild` |
