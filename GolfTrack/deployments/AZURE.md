# Azure — GolfTrack (Distribution & CI)

> **Applicability:** Azure does **not host** GolfTrack — it is an on-device iOS /
> watchOS / macOS app with **no backend**. Azure's real role here is a **build/CI
> pipeline** and **secret custody** for the mobile **distribution** flow
> (TestFlight / App Store Connect / Ad-Hoc / enterprise MDM via **Microsoft
> Intune**), plus **Garmin Connect IQ** store submission. There is no App Service,
> AKS app workload, database, or Blob storage for the app.

Covers **Azure Commercial** and **Azure Government** (`*.usgovcloudapi.net`).
Cross-links: [AWS](AWS.md) · [SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) ·
[DEPLOYMENT](../docs/DEPLOYMENT.md) · [SECURITY](../docs/SECURITY.md).

---

## 1. Deployment architecture

| Component | Azure service | Role |
|-----------|---------------|------|
| CI orchestration | Azure DevOps Pipelines / GitHub Actions | Trigger builds on push/tag |
| Linux compile check | Azure Container Instances / AKS Job (`swift:slim`) | Package/syntax gate |
| macOS build + sign | **Self-hosted macOS agent** (Azure DevOps agent on a Mac) | `xcodebuild archive`, sign, upload — Azure has no native macOS build VMs |
| Secret custody | **Azure Key Vault** | App Store Connect API key `.p8`, signing cert `.p12`, profiles |
| Deploy identity | **Managed identity / workload identity federation** | Key Vault access without static keys |
| Distribution | TestFlight / App Store Connect; **Intune** for enterprise/MDM | Deliver builds to devices |
| Garmin | Connect IQ store upload (`.iq`) | Garmin companion distribution |

> **App Store distribution is global**, operated by Apple — it is **not**
> Azure-Government-partitioned. Only your **CI/secret infrastructure** differs
> between Commercial and Government. **Intune Government** uses distinct tenant
> endpoints; the enrollment/app-assignment model is otherwise the same.

## 2. Topology

```
  Git push/tag
     │
     ▼
  Azure DevOps / GitHub Actions
     ├─► ACI/AKS Job (swift:slim)      → swift build/test (compile gate)
     └─► self-hosted macOS agent
            │  (workload identity)
            ▼
        Azure Key Vault ── .p8 / .p12 / provisioning profiles
            │
            ▼
        xcodebuild archive → sign → export
            ├─► App Store Connect / TestFlight   (global, Apple-operated)
            ├─► Ad-Hoc .ipa                       (registered UDIDs)
            └─► Microsoft Intune (Commercial | Gov) → managed devices
        Garmin CIQ build (.iq) → Connect IQ store
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Apple Developer Program | Paid; App Store Connect access |
| Azure subscription | Commercial or Government |
| Azure Key Vault | One vault for signing secrets |
| Self-hosted macOS agent | macOS 14 + Xcode 15 (Azure DevOps agent or GH ARC on Mac) |
| Microsoft Intune | For enterprise/MDM (Commercial tenant or Gov tenant) |
| Garmin Connect IQ SDK 4.x + developer key | Garmin companion |
| `az` CLI | Commercial: `AzureCloud`; Gov: `az cloud set --name AzureUSGovernment` |

## 4. Identity & credentials

Prefer **workload identity federation** (OIDC from Azure DevOps/GitHub → Entra ID)
so the pipeline gets a short-lived token to read Key Vault — **no static client
secrets**. Fall back to a **user-assigned managed identity** on the macOS agent VM.

Least-privilege Key Vault access policy (RBAC role assignment):
```
Role: Key Vault Secrets User   (get/list secrets only — NOT set/delete)
Scope: /subscriptions/<sub>/resourceGroups/<rg>/providers/
        Microsoft.KeyVault/vaults/<golftrack-signing-kv>
Assigned to: <pipeline-workload-identity or macOS-agent managed identity>
```
Secrets stored in Key Vault: `asc-api-key-p8`, `asc-key-id`, `asc-issuer-id`,
`dist-cert-p12`, `dist-cert-password`, `provisioning-profile`.

## 5. Environment variables

### Commercial
| Variable | Example | Purpose |
|----------|---------|---------|
| `AZURE_CLOUD` | `AzureCloud` | Cloud selector |
| `KEY_VAULT_URI` | `https://golftrack-kv.vault.azure.net/` | Signing secret store |
| `AZURE_CLIENT_ID` | `<managed-identity-client-id>` | Workload identity |
| `ASC_KEY_ID` | `2X9R4HXF34` | App Store Connect API key ID |
| `ASC_ISSUER_ID` | `57246542-0000-...` | ASC issuer ID |
| `INTUNE_TENANT` | `contoso.onmicrosoft.com` | MDM tenant (Commercial) |

### Azure Government
| Variable | Example | Purpose |
|----------|---------|---------|
| `AZURE_CLOUD` | `AzureUSGovernment` | Gov cloud selector |
| `KEY_VAULT_URI` | `https://golftrack-kv.vault.usgovcloudapi.net/` | Gov Key Vault endpoint |
| `AZURE_AUTHORITY_HOST` | `https://login.microsoftonline.us` | Entra ID Gov authority |
| `ASC_KEY_ID` | `2X9R4HXF34` | Same — App Store Connect is global |
| `ASC_ISSUER_ID` | `57246542-0000-...` | Same — global |
| `INTUNE_TENANT` | `contoso.onmicrosoft.us` | Intune **Government** tenant |

> App Store Connect / TestFlight endpoints are identical in both — Apple operates
> them globally. Only Key Vault, Entra authority, and Intune endpoints change.

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Active Xcode on macOS agent |
| `DEVELOPMENT_TEAM` | `AB12CD34EF` | Signing team |
| `EXPORT_METHOD` | `app-store` / `ad-hoc` / `enterprise` | `xcodebuild -exportArchive` method |
| `SCHEME` | `GolfTrack` | Xcode scheme |

## 7. Verification

No health endpoint, no login, no upload-to-Blob, no DB row — **explicitly N/A**.
Verify the distribution pipeline:

- [ ] Workload identity resolves a token; `az keyvault secret show` retrieves `asc-key-id` (Commercial and Gov URIs).
- [ ] Compile gate: `swift build`/`swift test` Job succeeds.
- [ ] macOS agent: `xcodebuild archive` + `-exportArchive` produce a signed `.ipa`.
- [ ] Upload to TestFlight succeeds (`xcrun altool`/`notarytool` or Transporter); build appears in App Store Connect.
- [ ] (Enterprise) Intune app assignment installs on a managed test device (Commercial or Gov tenant).
- [ ] (Garmin) `.iq` package builds and uploads to the Connect IQ store.

```bash
# Retrieve the ASC API key from Key Vault (Gov example)
az cloud set --name AzureUSGovernment
az keyvault secret download --vault-name golftrack-kv \
  --name asc-api-key-p8 --file AuthKey.p8

# Upload a build to TestFlight
xcrun altool --upload-app -f build/export/GolfTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

## 8. Day-2 operations

| Task | How |
|------|-----|
| Rotate ASC API key | Generate new `.p8` in App Store Connect; update Key Vault secret |
| Rotate signing cert | New distribution cert; import to macOS agent Keychain; update `.p12` in Key Vault |
| Update Xcode | Upgrade agent macOS/Xcode; `xcode-select -s` |
| Phased rollout | Enable App Store phased release; stage TestFlight groups |
| Intune app update | Upload new `.ipa`, bump version, reassign |
| Logs | Pipeline run logs; agent `~/Library/Logs` |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Key Vault 403 | Missing RBAC / wrong cloud | Assign *Key Vault Secrets User*; `az cloud set` to correct partition |
| Gov auth fails | Commercial authority used | Set `AZURE_AUTHORITY_HOST` to `login.microsoftonline.us` |
| TestFlight upload rejected | Signing/entitlement mismatch | Verify profile matches bundle ID + capabilities |
| Intune install fails (Gov) | Wrong tenant endpoint | Use `.onmicrosoft.us` tenant; check Gov service URLs |
| No macOS agent | Azure lacks native macOS VMs | Register a self-hosted Mac agent |
| Garmin upload rejected | Manifest/permissions | Validate in Connect IQ SDK before upload |
