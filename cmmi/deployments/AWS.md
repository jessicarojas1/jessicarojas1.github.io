# AWS — CMMI v2.0 Practice Reference

Host the static site on **S3 + CloudFront (OAC)** with **ACM** TLS and **Route
53**, for **AWS Commercial** and **AWS GovCloud**. Deploy via a GitHub **OIDC
IAM role** (no static keys), with a least-privilege put/invalidate policy.

## 1. Deployment architecture

The **repository root** is synced to a private S3 bucket (so `../cmmidev3.js` and
the shared `../` assets resolve), with the app served at `/cmmi/`. CloudFront
fronts the bucket via **Origin Access Control (OAC)** — the bucket stays private;
only CloudFront can read it. ACM provides TLS; Route 53 maps the domain.
CloudFront's default root object / a function routes `/` → `/cmmi/`. No backend,
database, or Secrets Manager entry is used by the running site.

## 2. Topology

```
                 Commercial (partition: aws)        |   GovCloud (partition: aws-us-gov)
Browser ─HTTPS─▶ CloudFront (ACM TLS, OAC)          |  CloudFront (us-gov regions, FIPS)
                     │  default root → /cmmi/        |     │  same
                     ▼                               |     ▼
              S3 (private, repo ROOT:                 |  S3 us-gov-west-1 (FIPS endpoint)
              cmmi/ + cmmidev3.js + shared)           |  s3-fips.us-gov-west-1.amazonaws.com
Route 53 ─── DNS ── cmmi.example.com                 |  Route 53 (gov)
Browser ─HTTPS─▶ cdn.jsdelivr.net (Bootstrap 5.3.3 / Icons 1.11.3 / SheetJS)
GitHub Actions ─OIDC(no keys)─▶ IAM role → s3:PutObject/DeleteObject + cloudfront:CreateInvalidation
```

## 3. Prerequisites

| Item | Note |
|------|------|
| AWS account | Commercial and/or **GovCloud** |
| AWS CLI | ≥ 2.13 (`--region us-gov-west-1` for Gov) |
| S3 bucket | private, OAC-only read |
| CloudFront distribution | with OAC to the bucket |
| ACM certificate | in `us-east-1` (Commercial) / `us-gov-west-1` (Gov) for CloudFront |
| Route 53 hosted zone | for the domain |
| GitHub OIDC provider | in IAM for role assumption |

## 4. Identity & credentials

**GitHub OIDC role — no static keys.** Trust policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Federated": "arn:aws:iam::<ACCOUNT>:oidc-provider/token.actions.githubusercontent.com" },
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {
      "StringEquals": { "token.actions.githubusercontent.com:aud": "sts.amazonaws.com" },
      "StringLike":   { "token.actions.githubusercontent.com:sub": "repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main" }
    }
  }]
}
```

Least-privilege permissions policy (Commercial ARNs; swap partition to
`aws-us-gov` for GovCloud):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": ["s3:PutObject","s3:DeleteObject","s3:ListBucket"],
      "Resource": ["arn:aws:s3:::cmmi-web-bucket","arn:aws:s3:::cmmi-web-bucket/*"] },
    { "Effect": "Allow", "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::<ACCOUNT>:distribution/<DIST_ID>" }
  ]
}
```

GovCloud: `arn:aws-us-gov:s3:::…` and `arn:aws-us-gov:cloudfront::…`.

## 5. Environment variables

The app has none. Deploy-pipeline variables, Commercial vs GovCloud:

| Variable | Commercial example | GovCloud example | Purpose |
|----------|--------------------|--------------------|---------|
| `AWS_PARTITION` | `aws` | `aws-us-gov` | ARN partition |
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Deploy region |
| `S3_BUCKET` | `cmmi-web-bucket` | `cmmi-web-bucket-gov` | Origin bucket |
| `S3_ENDPOINT` | `s3.us-east-1.amazonaws.com` | `s3-fips.us-gov-west-1.amazonaws.com` | FIPS endpoint in Gov |
| `STS_ENDPOINT` | `sts.amazonaws.com` | `sts.us-gov-west-1.amazonaws.com` | OIDC assume-role |
| `CF_DISTRIBUTION_ID` | `E123ABC…` | `E456GOV…` | Invalidation target |
| `ACM_CERT_REGION` | `us-east-1` | `us-gov-west-1` | CloudFront cert region |

## 6. Configuration references

Publish + invalidate (Commercial; add `--region us-gov-west-1` and FIPS endpoint
for Gov):

```bash
# Sync the REPO ROOT (so ../cmmidev3.js is present); keep the bucket private.
aws s3 sync . s3://cmmi-web-bucket/ \
  --exclude ".git/*" --exclude "cmmi/deployments/*" --exclude "cmmi/docs/*" \
  --delete --cache-control "public,max-age=3600"

# Shorter TTL on the entry HTML and the unversioned dataset so updates propagate:
aws s3 cp cmmi/index.html s3://cmmi-web-bucket/cmmi/index.html --cache-control "no-cache"
aws s3 cp cmmidev3.js     s3://cmmi-web-bucket/cmmidev3.js     --cache-control "no-cache"

aws cloudfront create-invalidation --distribution-id E123ABC --paths "/*"

# GovCloud FIPS example:
aws --region us-gov-west-1 --endpoint-url https://s3-fips.us-gov-west-1.amazonaws.com \
  s3 sync . s3://cmmi-web-bucket-gov/ --delete
```

CloudFront settings:

| Setting | Value | Purpose |
|---------|-------|---------|
| Default root object | `cmmi/index.html` (or a `/`→`/cmmi/` CloudFront Function) | Land users on the app |
| Origin access | **OAC** | Bucket stays private |
| Viewer protocol | Redirect HTTP→HTTPS | Enforce TLS |
| Response headers policy | HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` | Edge hardening |
| Min TLS | `TLSv1.2_2021` | Modern TLS |

## 7. Verification

```bash
# entry page + parent dataset via CloudFront
curl -I https://cmmi.example.com/cmmi/            # → 200
curl -I https://cmmi.example.com/cmmidev3.js      # → 200 (~227 KB)

# bucket is private (direct S3 should be denied)
curl -I https://cmmi-web-bucket.s3.amazonaws.com/cmmi/index.html   # → 403 (OAC-only)

# edge headers present
curl -sI https://cmmi.example.com/cmmi/ | grep -Ei 'strict-transport|x-content-type'

# GovCloud
curl -I https://cmmi.example.gov/cmmi/            # → 200
```

Browser: CSP clean, practices render/filter/search, status persists (`cmmi2_*`),
theme persists (`bsTheme`), branding applies, `.xlsx` export downloads, print
renders. There is **no** login, DB row, server upload, or object written by the
app to verify — the only S3 writes are the deploy pipeline's.

## 8. Day-2 operations

- **Deploy/update:** re-run `s3 sync` + `create-invalidation` from CI (OIDC
  role). Always sync the **repo root** so `cmmidev3.js` ships.
- **Cert rotation:** ACM auto-renews; nothing manual.
- **Credential rotation:** none — OIDC tokens are short-lived; no static keys
  exist.
- **Backups:** none required (no state); enable S3 versioning on the bucket for
  easy artifact rollback, and Git remains the source of truth.
- **Logs:** CloudFront access logs / S3 server access logs to a log bucket.
- **HA:** CloudFront is multi-edge by design; S3 is regionally durable.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `/cmmi/` blank | Only `cmmi/` synced; `../cmmidev3.js` 404 | Sync the **repo root** to the bucket |
| 403 via CloudFront | OAC not granting read / bucket policy missing | Attach the OAC bucket policy allowing the distribution |
| Stale content after deploy | No invalidation | `create-invalidation --paths "/*"` (and lower TTL on `cmmidev3.js`) |
| GovCloud calls fail | Wrong partition/endpoint | Use `aws-us-gov` ARNs + `s3-fips.us-gov-west-1` |
| AssumeRole denied | Trust `sub` mismatch | Match the repo/branch in the OIDC trust condition |
| Unstyled page | CDN blocked | Allow `cdn.jsdelivr.net` or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
