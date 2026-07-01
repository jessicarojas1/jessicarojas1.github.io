# ISMS Document Library — AWS (Commercial + GovCloud)

Audience: operators hosting the ISMS library on **AWS S3 static hosting +
CloudFront** in **AWS Commercial** (`aws` partition) or **AWS GovCloud (US)**
(`aws-us-gov` partition). It is a **Type A static website** — no backend, no
database, no server auth, **no application secrets**. The only identity that
matters is the **CI deploy role** (GitHub OIDC → IAM role), not static keys.

> Siblings: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) ·
> [AZURE.md](AZURE.md) · [AIRGAPPED.md](AIRGAPPED.md)

## 1. Deployment architecture

Files live in a **private S3 bucket** (Block Public Access ON, SSE-KMS,
versioning). **CloudFront** serves them over TLS using **Origin Access Control
(OAC)** so the bucket is never public. **ACM** provides the certificate;
**Route 53** the DNS. Security headers/CSP are attached via a **CloudFront
response-headers policy**. CI publishes with `aws s3 sync` + a CloudFront
invalidation, authenticating through a **GitHub OIDC → IAM role** (no long-lived
keys). Publish the repo root so `../` shared assets resolve; land users at
`/isms/index.html`.

## 2. Topology

```
                 Route 53 (DNS)                ACM (TLS cert; us-east-1 for CF)
                      │                              │
Browser ─HTTPS─► CloudFront distribution ◄───────────┘
                  │  OAC (SigV4)  + response-headers policy (CSP, HSTS…)
                  ▼
            S3 bucket (PRIVATE): Block Public Access, SSE-KMS, Versioning
                  ▲
   GitHub Actions ─┘  OIDC → IAM role (sts:AssumeRoleWithWebIdentity)
                      s3:PutObject/DeleteObject + cloudfront:CreateInvalidation
   Browser also loads Bootstrap 5.3.3 + devicons ──► jsDelivr CDN (or vendored)
```

## 3. Prerequisites

| Item | Version | Notes |
|------|---------|-------|
| AWS account | Commercial **or** GovCloud (US) | GovCloud is a separate signup |
| AWS CLI v2 | 2.x | `--profile` per partition |
| S3 + CloudFront + ACM + Route 53 | — | ACM cert for CloudFront must be in **us-east-1** (Commercial) |
| GitHub OIDC provider in IAM | — | `token.actions.githubusercontent.com` |
| KMS CMK | — | SSE-KMS for the bucket |

## 4. Identity & credentials

**Prefer a keyless CI role — never static access keys.** Create an IAM role
trusted by GitHub OIDC and scoped to this one bucket + distribution:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow",
      "Action": ["s3:PutObject","s3:DeleteObject","s3:ListBucket"],
      "Resource": ["arn:aws:s3:::isms-site","arn:aws:s3:::isms-site/*"] },
    { "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::<acct>:distribution/<DIST_ID>" },
    { "Effect": "Allow",
      "Action": ["kms:GenerateDataKey","kms:Encrypt"],
      "Resource": "arn:aws:kms:<region>:<acct>:key/<cmk-id>" }
  ]
}
```

> **GovCloud:** swap every ARN partition `aws` → **`aws-us-gov`**
> (`arn:aws-us-gov:s3:::…`). The running site needs **no** identity at all — it is
> public static content.

## 5. Environment variables

The app uses **no environment variables**. These are **CI/deploy** values, split
Commercial vs GovCloud where they differ:

| Variable | Commercial example | GovCloud example | Purpose |
|----------|--------------------|------------------|---------|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | deploy region |
| `AWS_PARTITION` | `aws` | `aws-us-gov` | ARN partition |
| `S3_BUCKET` | `isms-site` | `isms-site-gov` | origin bucket |
| `CF_DIST_ID` | `E123ABC…` | `E456DEF…` | distribution to invalidate |
| `AWS_ROLE_ARN` | `arn:aws:iam::<acct>:role/isms-deploy` | `arn:aws-us-gov:iam::<acct>:role/isms-deploy` | OIDC deploy role |
| S3 endpoint | `s3.us-east-1.amazonaws.com` | `s3.us-gov-west-1.amazonaws.com` (FIPS: `s3-fips.us-gov-west-1.amazonaws.com`) | data-plane |
| STS endpoint | `sts.us-east-1.amazonaws.com` | `sts.us-gov-west-1.amazonaws.com` (FIPS available) | OIDC assume-role |
| ACM region | `us-east-1` (required for CloudFront) | `us-gov-west-1` | TLS cert |

Use **FIPS endpoints** (`*-fips.*`) in GovCloud where required.

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| S3 Block Public Access | all four = ON | bucket stays private |
| Bucket encryption | SSE-KMS (CMK) | at-rest encryption |
| Bucket versioning | Enabled | point-in-time artifact backup |
| CloudFront default root object | `index.html` | root serves the portfolio home |
| CloudFront behavior | redirect-to-https, compress | transport + gzip/br |
| Response headers policy | CSP, HSTS, X-CTO, X-Frame, Referrer, Permissions | headers ([../docs/SECURITY.md](../docs/SECURITY.md)) |
| Landing | CloudFront Function rewrites `/` → `/isms/index.html` (optional) | ISMS as entry |

## 7. Verification

No login/DB/upload. Verify publish, private origin, TLS, headers, client behavior:

```bash
# Publish (CI, via assumed OIDC role)
aws s3 sync . s3://$S3_BUCKET/ --delete \
  --exclude ".git/*" --sse aws:kms
aws cloudfront create-invalidation --distribution-id $CF_DIST_ID --paths "/*"

# Entry + assets over the CDN
curl -I https://isms.example.com/isms/index.html      # 200
curl -I https://isms.example.com/isms/isms.css        # 200
curl -I https://isms.example.com/theme.css            # 200 (shared asset)

# Origin is private (should NOT be reachable directly)
curl -I https://$S3_BUCKET.s3.amazonaws.com/isms/index.html   # 403 AccessDenied

# Headers
curl -sI https://isms.example.com/isms/index.html | grep -iE 'content-security-policy|strict-transport'
```

Browser: hub renders, search/filters work, theme + branding persist, CSP clean.
"Object written" = the `s3 sync` uploaded the HTML objects (verify with
`aws s3 ls s3://$S3_BUCKET/isms/`).

## 8. Day-2 operations

- **Deploy:** CI `s3 sync` + invalidation on merge to `main` (OIDC role).
- **Rollback:** versioning is on — restore prior object versions, or re-sync a
  previous git tag; invalidate.
- **Certs:** ACM auto-renews DNS-validated certs.
- **Cache:** set HTML `Cache-Control: no-cache`, assets long-cache; always
  invalidate `/*` (or the changed paths) after deploy.
- **Backups:** S3 versioning + **git as source of truth**
  ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **Monitoring:** CloudWatch alarms on CloudFront 5xx; enable access logs
  (CloudFront + S3) to a log bucket; ACM/cert expiry is managed but alert anyway.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 403 through CloudFront | OAC not granted on bucket policy | Add the OAC `s3:GetObject` allow for the distribution |
| Direct S3 URL returns content | Bucket not actually private | Enable Block Public Access; serve only via CloudFront |
| Old content after deploy | CDN cache | Create an invalidation (`/*`) |
| `AccessDenied` in CI | Role/OIDC trust or partition ARN wrong | Fix trust policy; use `aws-us-gov` ARNs in GovCloud |
| TLS cert not attaching | ACM cert not in us-east-1 (Commercial) | Reissue the CloudFront cert in `us-east-1` |
| Unstyled pages | jsDelivr blocked by client egress | Vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| Missing headers | No response-headers policy | Attach the CSP/HSTS policy to the behavior |
