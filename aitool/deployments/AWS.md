# AWS — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable, covering **AWS Commercial** and **AWS GovCloud
(us-gov)**. Host on **S3** (private bucket) fronted by **CloudFront** with **Origin
Access Control (OAC)**, **ACM** TLS, and **Route 53** DNS. There are **no app secrets**;
the only identity is a **CI deploy role assumed via OIDC** — never static keys.

## 1. Deployment architecture

Files live in a private S3 bucket. CloudFront (with OAC) is the only reader of the
bucket and serves the site globally over TLS with a response-headers policy (CSP/HSTS)
and cache behaviors. CI publishes via `aws s3 sync` + a CloudFront invalidation, using a
role assumed through GitHub OIDC. No compute, no database, no worker.

## 2. Topology

```
   Browser ──HTTPS──► CloudFront (TLS/ACM, WAF, cache, resp-headers policy)
                          │ OAC (SigV4)
                          ▼
                       S3 bucket (private, BPA on, SSE-KMS)
   Route 53 ─ DNS ─► CloudFront
   CI (GitHub) ──OIDC AssumeRole──► deploy role ──► s3:PutObject + cloudfront:CreateInvalidation
   Browser ──HTTPS──► cdn.jsdelivr.net (Bootstrap, SRI)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| AWS account | Commercial or **GovCloud (us-gov)** |
| AWS CLI v2 | configured for the target partition |
| S3 bucket | Block Public Access ON; OAC-only reads |
| CloudFront distribution + ACM cert | ACM cert in `us-east-1` (Commercial) / `us-gov-west-1` (GovCloud) |
| GitHub OIDC provider + IAM role | for keyless CI deploys |

## 4. Identity & credentials

- **Deploy via an IAM role assumed through GitHub OIDC** — no long-lived access keys.
  Trust policy conditions on `token.actions.githubusercontent.com` + `sub` = your repo/
  branch.
- **Least-privilege deploy policy** (Commercial ARNs shown; swap partition for GovCloud):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow",
      "Action": ["s3:PutObject","s3:DeleteObject","s3:ListBucket"],
      "Resource": ["arn:aws:s3:::aitool-site","arn:aws:s3:::aitool-site/*"] },
    { "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::<acct>:distribution/<dist-id>" }
  ]
}
```
> **GovCloud:** partition is `aws-us-gov` — e.g. `arn:aws-us-gov:s3:::aitool-site`,
> `arn:aws-us-gov:cloudfront::<acct>:distribution/<id>`.

- **Bucket policy:** allow only the CloudFront **OAC** service principal to `s3:GetObject`
  (SigV4); keep Block Public Access ON. **Runtime site has no credentials.**

## 5. Environment variables

The **site consumes none.** These are **pipeline/CLI** values:

| Variable | Example (Commercial) | Example (GovCloud) | Purpose |
|----------|----------------------|--------------------|---------|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Deploy region |
| Partition | `aws` | `aws-us-gov` | ARN partition |
| `S3_BUCKET` | `aitool-site` | `aitool-site-gov` | Origin bucket |
| `CF_DIST_ID` | `E123ABC...` | `E456...` | Distribution to invalidate |
| S3 endpoint | `s3.us-east-1.amazonaws.com` | `s3.us-gov-west-1.amazonaws.com` | Object endpoint |
| FIPS endpoint (Gov) | `s3-fips.us-east-1.amazonaws.com` | `s3-fips.us-gov-west-1.amazonaws.com` | FIPS 140-2/3 transport |
| STS endpoint | `sts.amazonaws.com` | `sts.us-gov-west-1.amazonaws.com` | Role assumption |
| ACM cert region | `us-east-1` (CloudFront requires) | `us-gov-west-1` | TLS cert |

> **GovCloud specifics:** use `aws-us-gov` partition, regional/FIPS endpoints
> (`*-fips.*.amazonaws.com`), and GovCloud STS. KMS keys for SSE-KMS must be GovCloud keys.

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Default root object | `index.html` (or `/aitool/index.html`) | Landing |
| Response headers policy | CSP + HSTS + nosniff | Security headers at the edge |
| Cache policy | `*.html` short TTL; assets long TTL | Freshness vs caching |
| SSE | SSE-KMS (or SSE-S3) | Encrypt objects at rest |
| Bucket public access | Block ALL | Private; OAC-only |

CloudFront **response headers policy** (equivalent CSP):
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none'; base-uri 'self'
Strict-Transport-Security: max-age=63072000; includeSubDomains
X-Content-Type-Options: nosniff
```

## 7. Verification

No login/DB/upload. Verify publish + edge + client behavior (Commercial shown; use
GovCloud endpoints/partition as above):

```bash
# Publish (whole repo so ../ assets resolve, or a vendored aitool build)
aws s3 sync ./ s3://aitool-site --delete
aws cloudfront create-invalidation --distribution-id $CF_DIST_ID --paths '/*'

# Verify
curl -I https://aitool.example.com/aitool/index.html          # 200
curl -sI https://aitool.example.com/aitool/index.html | grep -i 'content-security-policy\|strict-transport-security'
curl -I https://aitool.example.com/theme.css                  # 200 shared asset
aws s3api head-object --bucket aitool-site --key aitool/index.html   # confirms object written
```
Browser: styled page; theme toggle + Settings branding persist; tracker JSON export
downloads; no CSP/SRI console errors.

## 8. Day-2 operations

- **Deploy/update:** `s3 sync` + CloudFront invalidation from the OIDC role in CI.
- **TLS:** ACM auto-renews the CloudFront cert.
- **Scaling:** CloudFront handles global scale; nothing to size.
- **Backups:** enable **S3 versioning** (+ optional CRR to a second region/partition) —
  though git is the source of truth. See `../docs/DISASTER_RECOVERY.md`.
- **Logs:** CloudFront standard/real-time logs + S3 server access logs → CloudWatch/
  S3 for consumption auditing.
- **Rotation:** review the OIDC role trust policy periodically; no static keys to rotate.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 403 from CloudFront | OAC/bucket policy misconfigured | Grant CloudFront OAC `s3:GetObject`; keep BPA on |
| 404 on `../theme.css` | Only `aitool/` synced | Sync whole repo or vendor shared assets |
| AccessDenied on deploy | Role/partition wrong | Fix least-priv policy; GovCloud → `aws-us-gov` ARNs |
| Stale content | CloudFront cache | `create-invalidation --paths '/*'` |
| Cert invalid | ACM cert not in `us-east-1` (Commercial) | Issue cert in the CloudFront-required region |
| No security headers | Missing response-headers policy | Attach the CSP/HSTS policy to the behavior |
| FIPS required but not used (Gov) | Non-FIPS endpoint | Use `*-fips.*` endpoints |
