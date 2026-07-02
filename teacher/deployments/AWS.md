# Teacher Hub — AWS (Commercial + GovCloud)

**Target:** host Teacher Hub as a static site on **S3 + CloudFront (OAC)** with
**ACM** TLS and **Route 53** DNS. Deploy from CI using an **IAM role assumed via
GitHub OIDC** — never static access keys.

> **Applicability:** Fully applicable and the recommended production model for a
> static site. Covers **AWS Commercial** and **AWS GovCloud (us-gov)** for
> consistency across the portfolio. Realistically a K-12 classroom tool lands on
> **Commercial** (or a district's chosen host); **GovCloud** is documented as
> "available if a district requires the `aws-us-gov` partition / FIPS endpoints,"
> not as a likely default.

Related: [AZURE.md](AZURE.md) · [KUBERNETES.md](KUBERNETES.md) ·
[../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

Static files are synced to a **private** S3 bucket (no public bucket ACLs).
CloudFront serves the bucket via **Origin Access Control (OAC)**; ACM provides the
cert; Route 53 aliases the domain to the distribution. A **CloudFront response
headers policy** injects the **security headers + CSP the HTML lacks**. There is
no compute, no database, no login, no server upload, and no runtime IAM — the site
is inert files plus browser `localStorage`.

Publish the **repo-root layout** into the bucket so `../theme.css` /
`../favicon.ico` resolve: object keys `theme.css`, `favicon.ico`,
`teacher/index.html`, `teacher/branding.js`. Set the distribution's default root
object or a CloudFront Function to route `/` → `/teacher/index.html` if you want
the hub at the apex.

## 2. Topology

```
   GitHub Actions ──(OIDC AssumeRole)──► IAM role (deploy)
        │  aws s3 sync ; cloudfront create-invalidation
        ▼
   ┌─────────────┐   OAC (SigV4)    ┌──────────────────────┐
   │  S3 bucket  │◄─────────────────│  CloudFront (TLS/ACM) │◄── Route 53 alias
   │ (private)   │                  │  + Response Headers   │
   │ theme.css   │                  │    Policy (CSP/HSTS)  │
   │ teacher/*   │                  └───────────┬──────────┘
   └─────────────┘                              │ 443
                                                ▼
                                     Teacher/Student browser
                                      ├─ Bootstrap/Icons ← jsDelivr (or vendor)
                                      └─ localStorage (all app data, per device)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| AWS account | Commercial **or** GovCloud (`aws-us-gov`) |
| Route 53 hosted zone | for the domain (or district DNS) |
| ACM cert | **in `us-east-1`** for CloudFront (Commercial); `us-gov-west-1` for GovCloud |
| AWS CLI v2 | `aws --version` |
| GitHub OIDC provider | `token.actions.githubusercontent.com` registered as an IAM OIDC IdP |

## 4. Identity & credentials

**Deploy-pipeline identity only** (the site itself has no AWS identity). Use an IAM
role assumed via GitHub OIDC — **no long-lived keys**.

Trust policy (Commercial ARN shown; GovCloud uses `arn:aws-us-gov:…`):

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Federated": "arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com" },
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {
      "StringEquals": { "token.actions.githubusercontent.com:aud": "sts.amazonaws.com" },
      "StringLike":   { "token.actions.githubusercontent.com:sub": "repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main" }
    }
  }]
}
```

Least-privilege permissions policy (publish + invalidate only):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ListBucket", "Effect": "Allow", "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::teacherhub-site" },
    { "Sid": "WriteObjects", "Effect": "Allow",
      "Action": ["s3:PutObject","s3:DeleteObject"],
      "Resource": "arn:aws:s3:::teacherhub-site/*" },
    { "Sid": "Invalidate", "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::<ACCOUNT_ID>:distribution/<DIST_ID>" }
  ]
}
```

> **GovCloud:** replace every `arn:aws:` with `arn:aws-us-gov:`, use STS regional
> endpoints (`sts.us-gov-west-1.amazonaws.com`), and FIPS S3 endpoints
> (`s3-fips.us-gov-west-1.amazonaws.com`). The OIDC IdP must be registered in the
> GovCloud account.

The S3 bucket is private (Block Public Access ON); CloudFront reads it via OAC
using a bucket policy that allows only the distribution's service principal.

## 5. Environment variables

The app reads none. CI uses these (as workflow inputs/vars, not app config):

| Variable | Example (Commercial) | Example (GovCloud) | Purpose |
|----------|----------------------|--------------------|---------|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | CLI/region; ACM for CF must be `us-east-1` (Commercial) |
| `AWS_ROLE_ARN` | `arn:aws:iam::123…:role/teacherhub-deploy` | `arn:aws-us-gov:iam::123…:role/teacherhub-deploy` | role assumed via OIDC |
| `S3_BUCKET` | `teacherhub-site` | `teacherhub-site` | destination bucket |
| `CF_DISTRIBUTION_ID` | `E123ABC…` | `E123ABC…` | invalidation target |
| `AWS_USE_FIPS_ENDPOINT` | _(unset)_ | `true` | force FIPS endpoints in GovCloud |
| `AWS_PARTITION` | `aws` | `aws-us-gov` | partition used in ARNs |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| CloudFront default root object | `teacher/index.html` | serve hub at apex |
| Cache policy (HTML) | short/`no-cache` | pick up new deploys quickly |
| Cache policy (css/js/ico) | `max-age=604800` | long cache for static assets |
| Response Headers Policy | CSP/HSTS/nosniff (below) | inject headers the HTML lacks |
| Vendor pin | Bootstrap `5.3.3`, Icons `1.11.3` | in `index.html` (or vendor for offline) |

CloudFront Response Headers Policy (CSP keeps `'unsafe-inline'` for the inline
handlers; tighten after externalizing — see [../OPEN_ITEMS.md](../OPEN_ITEMS.md)):

```
Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
```

Example deploy step:

```bash
aws s3 sync . s3://$S3_BUCKET \
  --exclude ".git/*" --exclude "*/deployments/*" --exclude "*/docs/*" \
  --cache-control "public,max-age=604800" --delete
# override HTML to no-cache
aws s3 cp teacher/index.html s3://$S3_BUCKET/teacher/index.html \
  --cache-control "no-cache" --content-type text/html
aws cloudfront create-invalidation --distribution-id $CF_DISTRIBUTION_ID --paths "/*"
```

## 7. Verification

No health endpoint/login/secret/upload/DB — verify delivery + headers + client
behavior:

```bash
# Object written to S3
aws s3 ls s3://$S3_BUCKET/teacher/index.html
# Entry page 200 through CloudFront
curl -sSI https://teacherhub.school.k12.us/teacher/ | head -1              # HTTP/2 200
# Security headers injected by CloudFront
curl -sSI https://teacherhub.school.k12.us/teacher/ | grep -iE 'content-security-policy|strict-transport'
# Parent-relative asset resolves
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.school.k12.us/theme.css   # 200
# Bucket is private (should NOT be publicly readable)
curl -sS -o /dev/null -w '%{http_code}\n' https://$S3_BUCKET.s3.amazonaws.com/teacher/index.html  # 403
```

Then browser: theme persists, 10 tabs switch, plan + gradebook entry save and
survive reload, **CSV download**, template print, branding applies
([LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) §7).

## 8. Day-2 operations

| Task | How |
|------|-----|
| Release | `s3 sync` + `create-invalidation` (CI, via the OIDC role) |
| Rollback | re-sync a previous git tag; enable S3 versioning to restore prior objects |
| Rotate credentials | none to rotate — role is assumed short-term via OIDC |
| Cert rotation | ACM auto-renews the CloudFront cert |
| Logs | CloudFront standard/real-time logs to an S3 log bucket |
| Cost guardrails | it's cents/month; set a small budget alert |
| Backups | git is source of truth; enable S3 versioning for object history ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 403 via CloudFront | OAC/bucket policy misconfigured | grant the distribution's service principal `s3:GetObject` on the bucket |
| 404 `/theme.css` | root-level objects not uploaded | sync repo-root layout so `theme.css`/`favicon.ico` exist as keys |
| Cert error on CF | ACM cert not in `us-east-1` (Commercial) | issue/import the cert in `us-east-1`; GovCloud uses `us-gov-west-1` |
| Stale content | HTML cached long | set `no-cache` on `index.html`; invalidate `/*` after deploy |
| OIDC AssumeRole denied | `sub`/`aud` condition mismatch | align trust policy `sub` with the exact repo/ref; `aud=sts.amazonaws.com` |
| GovCloud ARN errors | wrong partition | use `arn:aws-us-gov:` and FIPS/gov regional endpoints |
