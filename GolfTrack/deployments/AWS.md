# AWS — GolfTrack (Distribution & CI)

> **Applicability:** AWS does **not host** GolfTrack — it is an on-device iOS /
> watchOS / macOS app with **no backend**. AWS's real role is a **build/CI
> pipeline** and **secret custody** for mobile **distribution** (TestFlight /
> App Store Connect / Ad-Hoc / enterprise MDM), plus **Garmin Connect IQ** store
> submission. There is no ECS/EKS app workload, RDS, or S3 object path for the app.

Covers **AWS Commercial** (partition `aws`) and **AWS GovCloud (US)** (partition
`aws-us-gov`, FIPS endpoints). Cross-links: [AZURE](AZURE.md) ·
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) ·
[DEPLOYMENT](../docs/DEPLOYMENT.md) · [SECURITY](../docs/SECURITY.md).

---

## 1. Deployment architecture

| Component | AWS service | Role |
|-----------|-------------|------|
| CI orchestration | CodePipeline / GitHub Actions | Trigger on push/tag |
| Linux compile check | CodeBuild / EKS Job (`swift:slim`) | Package/syntax gate |
| macOS build + sign | **EC2 Mac** dedicated host **or** self-hosted Mac | `xcodebuild archive`, sign, upload |
| Secret custody | **AWS Secrets Manager** | App Store Connect API key `.p8`, signing `.p12`, profiles |
| Deploy identity | **IAM role via OIDC** (GitHub/CodeBuild) | Read secrets without static keys |
| Distribution | TestFlight / App Store Connect; MDM | Deliver to devices |
| Garmin | Connect IQ store upload (`.iq`) | Garmin companion |

> **App Store distribution is global**, Apple-operated — **not** GovCloud
> partitioned. Only the **CI + secret infrastructure** differs between Commercial
> and GovCloud (endpoints, partition ARNs, FIPS). Choose GovCloud when your
> signing secrets/pipeline must reside in a gov boundary.

## 2. Topology

```
  Git push/tag
     │
     ▼
  CodePipeline / GitHub Actions
     ├─► CodeBuild (swift:slim)     → swift build/test (compile gate)
     └─► EC2 Mac / self-hosted Mac
            │  (IAM role via OIDC — sts:AssumeRoleWithWebIdentity)
            ▼
        AWS Secrets Manager ── AuthKey.p8 / dist.p12 / profiles
            │
            ▼
        xcodebuild archive → sign → export
            ├─► App Store Connect / TestFlight   (global, Apple-operated)
            ├─► Ad-Hoc .ipa                       (registered UDIDs)
            └─► Enterprise/MDM (Intune/Jamf)      → managed devices
        Garmin CIQ build (.iq) → Connect IQ store
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Apple Developer Program | Paid; App Store Connect |
| AWS account | Commercial or GovCloud (separate account) |
| Secrets Manager | Signing secrets store |
| EC2 Mac dedicated host **or** self-hosted Mac | macOS 14 + Xcode 15 |
| OIDC identity provider | GitHub/CodeBuild federation to IAM |
| Garmin Connect IQ SDK 4.x + developer key | Garmin companion |
| `aws` CLI | Use FIPS endpoints in GovCloud |

## 4. Identity & credentials

Prefer an **IAM role assumed via OIDC** (GitHub Actions or CodeBuild) — the
pipeline gets short-lived STS credentials, **no long-lived access keys**.

Least-privilege policy (read-only on the specific signing secret):
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadGolfTrackSigning",
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue", "secretsmanager:DescribeSecret"],
      "Resource": "arn:aws:secretsmanager:us-east-1:123456789012:secret:golftrack/signing-*"
    },
    {
      "Sid": "DecryptWithKmsKey",
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": "arn:aws:kms:us-east-1:123456789012:key/<cmk-id>"
    }
  ]
}
```
GovCloud: replace ARNs with partition `aws-us-gov` (e.g.
`arn:aws-us-gov:secretsmanager:us-gov-west-1:...`).

Secrets stored: `golftrack/signing` (JSON with `asc_key_p8`, `asc_key_id`,
`asc_issuer_id`, `dist_cert_p12_b64`, `dist_cert_password`, `provisioning_profile_b64`).

## 5. Environment variables

### Commercial (partition `aws`)
| Variable | Example | Purpose |
|----------|---------|---------|
| `AWS_PARTITION` | `aws` | Partition selector |
| `AWS_REGION` | `us-east-1` | CI/secrets region |
| `AWS_ROLE_ARN` | `arn:aws:iam::123456789012:role/golftrack-ci` | OIDC role to assume |
| `SIGNING_SECRET_ID` | `golftrack/signing` | Secrets Manager secret |
| `STS_ENDPOINT` | `https://sts.us-east-1.amazonaws.com` | Token endpoint |

### GovCloud (partition `aws-us-gov`, FIPS)
| Variable | Example | Purpose |
|----------|---------|---------|
| `AWS_PARTITION` | `aws-us-gov` | Gov partition |
| `AWS_REGION` | `us-gov-west-1` | Gov region |
| `AWS_ROLE_ARN` | `arn:aws-us-gov:iam::123456789012:role/golftrack-ci` | Gov OIDC role |
| `SIGNING_SECRET_ID` | `golftrack/signing` | Secrets Manager (gov account) |
| `STS_ENDPOINT` | `https://sts.us-gov-west-1.amazonaws.com` | Gov STS (FIPS: `sts-fips.us-gov-west-1.amazonaws.com`) |
| `SECRETS_ENDPOINT` | `https://secretsmanager-fips.us-gov-west-1.amazonaws.com` | FIPS Secrets Manager endpoint |
| `KMS_ENDPOINT` | `https://kms-fips.us-gov-west-1.amazonaws.com` | FIPS KMS endpoint |

> App Store Connect / TestFlight endpoints are **identical** in both — Apple runs
> them globally. Only STS/Secrets/KMS partitions + FIPS endpoints change.

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `DEVELOPER_DIR` | `/Applications/Xcode.app/Contents/Developer` | Active Xcode on Mac host |
| `DEVELOPMENT_TEAM` | `AB12CD34EF` | Signing team |
| `EXPORT_METHOD` | `app-store` / `ad-hoc` / `enterprise` | Export method |
| `SCHEME` | `GolfTrack` | Xcode scheme |

## 7. Verification

No health endpoint, no login, no S3 object write, no DB row — **explicitly N/A**.
Verify the distribution pipeline:

- [ ] OIDC role assumes; `aws secretsmanager get-secret-value` returns the signing secret (Commercial + Gov FIPS endpoint).
- [ ] Compile gate: `swift build`/`swift test` (CodeBuild) succeeds.
- [ ] EC2 Mac: `xcodebuild archive` + export produce a signed `.ipa`.
- [ ] TestFlight upload succeeds; build appears in App Store Connect.
- [ ] (Enterprise) MDM install on a managed device.
- [ ] (Garmin) `.iq` builds and uploads.

```bash
# GovCloud: read the signing secret via the FIPS endpoint
aws secretsmanager get-secret-value \
  --secret-id golftrack/signing \
  --region us-gov-west-1 \
  --endpoint-url https://secretsmanager-fips.us-gov-west-1.amazonaws.com \
  --query SecretString --output text > signing.json
```

## 8. Day-2 operations

| Task | How |
|------|-----|
| Rotate ASC API key | New `.p8` in App Store Connect; `aws secretsmanager put-secret-value` |
| Rotate signing cert | New dist cert; re-import to Mac Keychain; update secret |
| Update Xcode | Reprovision EC2 Mac AMI or upgrade in place |
| Phased rollout | App Store phased release; staged TestFlight groups |
| Logs | CodeBuild/CloudWatch Logs; Mac host `~/Library/Logs` |
| Rotate role trust | Update OIDC provider thumbprint / conditions |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `AccessDenied` on secret | Role policy/partition wrong | Add least-priv policy; use `aws-us-gov` ARNs in Gov |
| STS token errors in Gov | Commercial endpoint used | Set `STS_ENDPOINT` to gov (FIPS) endpoint |
| Upload rejected by ASC | Signing/entitlement mismatch | Verify profile ↔ bundle ID ↔ capabilities |
| EC2 Mac 24h release lock | Dedicated host min alloc | Plan host lifecycle; keep host allocated |
| KMS decrypt fails (Gov) | Non-FIPS KMS endpoint | Use `kms-fips.us-gov-west-1` |
| Garmin upload rejected | Manifest/permissions | Validate with CIQ SDK first |
