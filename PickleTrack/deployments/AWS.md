# PickleTrack — AWS (Commercial + GovCloud)

> **Applicability: distribution + optional CI pipeline, not app hosting.** PickleTrack is an
> on-device iOS/macOS app — AWS does **not** host the running app and there is **no companion
> backend**. AWS's real role: (a) run the **build/signing pipeline** (CodePipeline/CodeBuild
> or GitHub Actions on **EC2 Mac** instances), (b) store **signing secrets in AWS Secrets
> Manager** accessed via an IAM **role** (OIDC from CI, no static keys), and (c) feed
> enterprise/MDM or **TestFlight / App Store Connect** distribution. Apple's App Store
> distribution itself is **global and not gov-partitioned** — only the CI/secrets infra
> differs between Commercial and GovCloud.

Related: [AZURE.md](AZURE.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

| Concern | AWS service | Notes |
|---------|-------------|-------|
| CI orchestration | **CodePipeline / CodeBuild** or GitHub Actions | Trigger on push/PR |
| App build + sign + archive | **EC2 Mac** (`mac2.metal`) instance | Only macOS runs `xcodebuild` |
| Secret storage | **AWS Secrets Manager** (KMS-encrypted) | ASC API key, `.p12`, profiles |
| Pipeline identity | **IAM role** assumed via **OIDC** | Short-lived STS creds — prefer over static keys |
| Public distribution | Apple **TestFlight / App Store Connect** | Global, not an AWS resource |
| Enterprise / managed distribution | MDM (Intune / Jamf) or Ad-Hoc | Push signed `.ipa` |

---

## 2. Topology

```
  Git push
     │
     ▼
 CodePipeline / GitHub Actions ──(OIDC → sts:AssumeRole)──▶ IAM role
     │                                                        │
     ▼                                                        ▼
 EC2 Mac (mac2.metal, Xcode 15) ◀── secretsmanager:GetSecretValue (KMS-decrypt)
     │
     ├─ xcodebuild archive + export (.ipa)
     │
     ├──▶ Apple TestFlight / App Store Connect   (global)
     └──▶ MDM (Ad-Hoc / enterprise)  ──▶ managed devices
```

---

## 3. Prerequisites

| Requirement | Detail |
|-------------|--------|
| AWS account | Commercial **or** GovCloud (us-gov) |
| EC2 Mac | `mac2.metal` (dedicated host, 24h min allocation) with Xcode 15+ |
| CI system | CodeBuild/CodePipeline or GitHub Actions with OIDC |
| Apple Developer Program | Team + App Store Connect access |
| KMS key | Encrypts the signing secrets |
| IAM OIDC provider | For CI federation (e.g. `token.actions.githubusercontent.com`) |

---

## 4. Identity & credentials

**Use an IAM role via OIDC — no long-lived access keys.**

Least-privilege policy for the build role (read only the signing secret + decrypt):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadSigningSecrets",
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:aws:secretsmanager:us-east-1:111122223333:secret:pickletrack/signing-*"
    },
    {
      "Sid": "DecryptWithKms",
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": "arn:aws:kms:us-east-1:111122223333:key/<key-id>"
    }
  ]
}
```

> **GovCloud:** replace partition `aws` with `aws-us-gov` in every ARN, e.g.
> `arn:aws-us-gov:secretsmanager:us-gov-west-1:...`. Use FIPS STS/KMS/Secrets Manager
> endpoints (below).

Secrets stored in Secrets Manager (JSON): ASC `.p8` key + key id + issuer id, distribution
`.p12` (base64) + password, provisioning profile (base64).

---

## 5. Environment variables

`Variable | Example | Purpose` — build-time only (the app reads none at runtime).

**Commercial**

| Variable | Example | Purpose |
|----------|---------|---------|
| `AWS_REGION` | `us-east-1` | Pipeline region |
| `AWS_ROLE_ARN` | `arn:aws:iam::111122223333:role/pickletrack-ci` | Role assumed via OIDC |
| `SIGNING_SECRET_ID` | `pickletrack/signing` | Secrets Manager secret name |
| `STS_ENDPOINT` | `https://sts.us-east-1.amazonaws.com` | Token exchange |
| `SECRETS_ENDPOINT` | `https://secretsmanager.us-east-1.amazonaws.com` | Fetch signing secret |
| `ASC_KEY_ID` / `ASC_ISSUER_ID` | `2X9ABC1234` / `69a6de70-...` | App Store Connect (global) |

**GovCloud (us-gov)**

| Variable | Example | Purpose |
|----------|---------|---------|
| `AWS_REGION` | `us-gov-west-1` | GovCloud region |
| `AWS_ROLE_ARN` | `arn:aws-us-gov:iam::444455556666:role/pickletrack-ci` | Partition `aws-us-gov` |
| `SIGNING_SECRET_ID` | `pickletrack/signing` | Same logical name |
| `STS_ENDPOINT` | `https://sts.us-gov-west-1.amazonaws.com` (FIPS: `sts-fips.us-gov-west-1.amazonaws.com`) | GovCloud/FIPS STS |
| `SECRETS_ENDPOINT` | `https://secretsmanager-fips.us-gov-west-1.amazonaws.com` | FIPS Secrets Manager |
| `KMS_ENDPOINT` | `https://kms-fips.us-gov-west-1.amazonaws.com` | FIPS KMS |
| `ASC_KEY_ID` / `ASC_ISSUER_ID` | *(same)* | Apple side is **not** gov-partitioned |

> App Store / TestFlight distribution is global — GovCloud changes only the AWS CI/secrets
> plane (partition, FIPS endpoints), not the Apple upload.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| Scheme | `PickleTrack` | `xcodebuild -scheme PickleTrack` |
| Export method | `app-store` / `ad-hoc` / `enterprise` | ExportOptions.plist |
| Bundle Identifier | `com.yourorg.pickletrack` | App identity |
| EC2 Mac AMI | `amzn-ec2-macos-14` + Xcode 15 | Build host image |
| `NSLocationWhenInUseUsageDescription` | *(court finder string)* | Required Info.plist key |

---

## 7. Verification

> No health endpoint, login, file upload to storage, or DB — verify the **pipeline**.

- [ ] CI assumes the IAM role via OIDC (STS returns short-lived creds; no static keys)
- [ ] `secretsmanager:GetSecretValue` resolves the signing secret (KMS decrypt succeeds)
- [ ] Signing assets import into a temporary keychain on the EC2 Mac
- [ ] `xcodebuild archive` + export yields a signed `.ipa`
- [ ] TestFlight upload succeeds (build shows in App Store Connect)
- [ ] App launches; key screens render (see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md#7-verification))

```bash
aws secretsmanager get-secret-value --secret-id "$SIGNING_SECRET_ID" \
  --region "$AWS_REGION" --endpoint-url "$SECRETS_ENDPOINT" --query SecretString --output text
xcrun altool --upload-app -f build/PickleTrack.ipa -t ios \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Rotate signing cert / ASC key | Update the Secrets Manager secret; enable rotation reminders (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |
| Release EC2 Mac host | Dedicated hosts bill 24h min — release when idle to control cost |
| Phased release | Configure phased rollout in App Store Connect |
| Audit secret reads | CloudTrail on `GetSecretValue` / `kms:Decrypt` |
| Switch to GovCloud | Repoint ARNs to `aws-us-gov` + FIPS endpoints per the Gov table |

No migrations, workers, queues, autoscaling, or persistent storage — nothing runs 24/7.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `AccessDenied` on GetSecretValue | Role policy too narrow / wrong ARN partition | Fix ARN (`aws` vs `aws-us-gov`); grant least-privilege secret read |
| `kms:Decrypt` denied | KMS key policy missing the role | Add role as a key grantee |
| STS OIDC fails | Trust policy / audience mismatch | Verify OIDC provider + `sub`/`aud` conditions |
| TestFlight upload 401 | ASC key expired | Rotate `.p8` in Secrets Manager |
| GovCloud endpoint timeout | Commercial endpoint used | Use `*-fips.us-gov-west-1.amazonaws.com` endpoints |
| EC2 Mac unreachable | Dedicated host not allocated | Allocate a `mac2` dedicated host (24h min) |
