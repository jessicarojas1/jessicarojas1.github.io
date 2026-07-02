# AWS — `cmmc2` (Commercial + GovCloud)

Host the CMMC 2.0 Readiness Assessment Platform on **S3 static hosting + CloudFront (OAC)**
with **ACM** TLS and **Route 53** DNS. Deploy via a **GitHub OIDC → IAM role** (no static
keys), least-privilege to `s3:PutObject`/`s3:DeleteObject` on the bucket plus
`cloudfront:CreateInvalidation`.

> **Realistic target:** because `cmmc2` serves DoD contractors handling CUI, **AWS GovCloud
> (US)** — partition `aws-us-gov`, FIPS endpoints, regions `us-gov-west-1` /
> `us-gov-east-1` — is the production target. Commercial is documented for dev/demo. Both
> are covered below with a split where they differ.

## 1. Deployment architecture

Static files (the repo tree, `cmmc2` at `/cmmc2/`) live in a **private** S3 bucket.
**CloudFront** serves them globally with **Origin Access Control (OAC)** so the bucket stays
private (no public bucket policy, no website endpoint exposed). **ACM** provides the TLS
cert; **Route 53** maps the domain to the distribution. There is no compute, database, or
secret. The browser fetches Bootstrap/Icons/SheetJS from jsDelivr (or use the vendored
air-gapped build for disconnected/regulated posture).

## 2. Topology

```
 Developer ──git push──▶ GitHub Actions ──OIDC AssumeRole──▶ IAM role (least-priv)
                                   │ aws s3 sync + create-invalidation
                                   ▼
        Route 53 ──▶ CloudFront (OAC, TLS via ACM) ──▶ S3 bucket (PRIVATE)
             │                                              cmmc2/index.html, branding.js,
        Browser ◀── HTTPS ── edge cache                     theme.css, *.js, index.html
             └── fetches Bootstrap/Icons/SheetJS from cdn.jsdelivr.net
```

## 3. Prerequisites

| Item | Note |
|---|---|
| AWS account | Commercial and/or **GovCloud (US)** (GovCloud requires a sponsored account) |
| Domain in Route 53 | hosted zone in the target partition |
| ACM certificate | **in `us-east-1`** for CloudFront (Commercial) / **`us-gov-west-1`** for GovCloud |
| AWS CLI v2 | `aws` configured for the partition |
| GitHub repo | for OIDC federation (or another CI OIDC provider) |
| Terraform/CloudFormation (optional) | to codify the below |

## 4. Identity & credentials

**Use an IAM role assumed via GitHub OIDC — never static access keys.**

1. Create an OIDC provider for `token.actions.githubusercontent.com` (Commercial) or the
   GovCloud equivalent.
2. Create a deploy role with a trust policy scoped to your repo/branch, and this
   least-privilege permissions policy (swap partition `aws` → `aws-us-gov` for GovCloud):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublishSite",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:DeleteObject", "s3:ListBucket"],
      "Resource": [
        "arn:aws:s3:::cmmc2-site",
        "arn:aws:s3:::cmmc2-site/*"
      ]
    },
    {
      "Sid": "InvalidateCache",
      "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::<ACCOUNT_ID>:distribution/<DIST_ID>"
    }
  ]
}
```

Trust policy (Commercial ARN shown; use `arn:aws-us-gov:iam::...` for GovCloud):

```json
{
  "Effect": "Allow",
  "Principal": { "Federated": "arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com" },
  "Action": "sts:AssumeRoleWithWebIdentity",
  "Condition": {
    "StringEquals": { "token.actions.githubusercontent.com:aud": "sts.amazonaws.com" },
    "StringLike": { "token.actions.githubusercontent.com:sub": "repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main" }
  }
}
```

There are **no app secrets** — the role's only job is to publish files and invalidate cache.

## 5. Environment variables

The app has **no runtime env vars**. These are **CI/deploy** variables:

| Variable | Commercial example | GovCloud example | Purpose |
|---|---|---|---|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Deploy region |
| `AWS_PARTITION` | `aws` | `aws-us-gov` | ARN partition |
| `S3_BUCKET` | `cmmc2-site` | `cmmc2-site-gov` | Origin bucket |
| `CF_DISTRIBUTION_ID` | `E123ABC...` | `E456DEF...` | For invalidation |
| `AWS_ROLE_ARN` | `arn:aws:iam::…:role/cmmc2-deploy` | `arn:aws-us-gov:iam::…:role/cmmc2-deploy` | OIDC role to assume |
| `AWS_USE_FIPS_ENDPOINT` | `false` (optional) | `true` | Force FIPS endpoints (GovCloud) |

**Endpoint differences (GovCloud / FIPS):**
- S3: `s3-fips.us-gov-west-1.amazonaws.com`; STS: `sts.us-gov-west-1.amazonaws.com`;
  KMS/ACM regional gov endpoints. Set `AWS_USE_FIPS_ENDPOINT=true` or `--endpoint-url` FIPS
  variants for CLI calls.
- ARNs use `arn:aws-us-gov:...`. CloudFront in GovCloud is available per-account; confirm
  distribution ARNs use the gov partition.

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Bucket access | Private + **OAC** | Bucket never public; only CloudFront reads it |
| Default root object | `index.html` | Serves portfolio home; app at `/cmmc2/` |
| Cache policy (html) | `Cache-Control: no-cache` | Ship updates promptly |
| Cache policy (assets) | `Cache-Control: public, max-age=86400` | Cache css/js/ico |
| Response headers policy | CSP + HSTS + nosniff + `frame-ancestors 'none'` | CloudFront **Response Headers Policy** (see below) |
| TLS | ACM cert on the distribution | HTTPS + TLS1.2+ |
| Bucket encryption | SSE-S3 or SSE-KMS | At-rest encryption of static files |
| Versioning | Enabled | Rollback / DR |

Attach a CloudFront **Response Headers Policy** carrying the CSP (mirrors the page `<meta>`;
adds `frame-ancestors 'none'`), `Strict-Transport-Security`, `X-Content-Type-Options`,
`Referrer-Policy`, `X-Frame-Options`. This delivers the CSP as a real HTTP header (the
`<meta>` alone can't set `frame-ancestors`).

### Publish (from repo root)
```bash
aws s3 sync . s3://$S3_BUCKET --delete \
  --exclude ".git/*" \
  --cache-control "public, max-age=86400"
# Re-set no-cache on HTML entry points
aws s3 cp cmmc2/index.html s3://$S3_BUCKET/cmmc2/index.html \
  --cache-control "no-cache" --content-type "text/html"
aws cloudfront create-invalidation --distribution-id $CF_DISTRIBUTION_ID --paths "/*"
```
(For GovCloud add `--region us-gov-west-1` and, if required, `--endpoint-url https://s3-fips.us-gov-west-1.amazonaws.com`.)

## 7. Verification

No login/DB/server-upload/object-write-by-users — the "object written" step here is the
**deploy** writing site objects to S3; verify that plus client behavior:

```bash
# Deploy artifact present in S3
aws s3 ls s3://$S3_BUCKET/cmmc2/index.html
aws s3 ls s3://$S3_BUCKET/theme.css

# Entry page 200 via CloudFront + CSP header present
curl -sI https://cmmc.example.com/cmmc2/ | head -n1                 # HTTP/2 200
curl -sI https://cmmc.example.com/cmmc2/ | grep -i content-security-policy

# Parent assets resolve through the CDN
for u in theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js; do
  printf '%-14s ' "$u"; curl -so /dev/null -w '%{http_code}\n' "https://cmmc.example.com/$u"
done

# Bucket is private (direct S3 URL should be denied)
curl -sI https://$S3_BUCKET.s3.amazonaws.com/cmmc2/index.html | head -n1   # 403
```

Browser: no CSP violations; branding applies; theme persists; marking a control updates the
SPRS score; `.xlsx` export downloads.

## 8. Day-2 operations

| Task | Action |
|---|---|
| Deploy | `aws s3 sync` + `create-invalidation` (CI on merge to main) |
| Rollback | Restore prior S3 object versions (versioning on) or redeploy previous commit; invalidate |
| Cert rotation | ACM auto-renews DNS-validated certs |
| Header/CSP change | Edit the CloudFront Response Headers Policy; no redeploy of content needed |
| Logs | CloudFront standard/real-time logs → S3; S3 server access logs |
| Cost/scaling | Serverless; scales at the edge automatically |
| DR | Bucket versioning + git (see [`../docs/DISASTER_RECOVERY.md`](../docs/DISASTER_RECOVERY.md)) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| 403 via CloudFront | OAC not granted in bucket policy | Add the OAC principal to the bucket policy; disable public access is fine |
| 404 on `/cmmc2/` | Files not synced under `cmmc2/` prefix | Sync from **repo root** so keys include `cmmc2/…` |
| Unstyled page | Parent assets missing at bucket root | Ensure `theme.css`/`*.js`/`index.html` synced to root |
| Stale content | Cache not invalidated | `create-invalidation --paths "/*"` (or `/cmmc2/*`) |
| CSP header absent | No Response Headers Policy attached | Attach it to the default behavior |
| `AssumeRole` denied in CI | Trust `sub`/`aud` mismatch or wrong partition | Fix trust `StringLike` for repo/branch; use `aws-us-gov` ARNs in GovCloud |
| FIPS/endpoint errors (Gov) | Commercial endpoints used | Set `AWS_USE_FIPS_ENDPOINT=true` / region `us-gov-west-1` |

See also: [`AZURE.md`](AZURE.md) · [`AIRGAPPED.md`](AIRGAPPED.md) · [`../docs/SECURITY.md`](../docs/SECURITY.md).
