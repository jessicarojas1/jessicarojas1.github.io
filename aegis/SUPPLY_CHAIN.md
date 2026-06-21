# AEGIS — Supply-Chain Integrity (signed images & provenance)

AEGIS ships a **signed, attestable** container image so a deployment can prove the
image it runs was built by this repository's CI from this source — not swapped or
tampered with in the registry. This satisfies SSDF PS.3 / SLSA build-level
provenance and the CMMC / NIST 800-171 SI & CM supply-chain integrity controls.

## What the release pipeline produces

`.github/workflows/release-aegis-image.yml` builds the hardened image
(`docker/Dockerfile.hardened`), pushes it to GHCR **by digest**, and attaches:

- **Keyless cosign signature** (Sigstore) bound to the workflow's GitHub OIDC
  identity — there is **no long-lived signing key** to leak.
- **SLSA build provenance** — both BuildKit `provenance: mode=max` and a
  GitHub-native `attest-build-provenance` attestation.
- **SBOM** — an SPDX SBOM from BuildKit plus a CycloneDX SBOM (syft) attached as
  a cosign attestation, and uploaded as a workflow artifact.

The job's final step is a **`cosign verify` gate**: if the signature does not
verify against this repo's identity, the release job fails — an unsigned or
unverifiable image can never go green.

## Cutting a release

The pipeline runs only on **AEGIS-scoped tags** (`aegis-v*`) or manual dispatch —
never on pull requests (it needs trusted push/OIDC credentials). To release:

```bash
git tag aegis-v1.4.0
git push origin aegis-v1.4.0
```

The published image is `ghcr.io/<owner>/aegis-server`, tagged with the release
tag, a `sha-<commit>` tag, and (when the tag parses as semver) `MAJOR.MINOR` /
`MAJOR.MINOR.PATCH`. Always **deploy by digest** (`@sha256:…`), not by a mutable
tag.

## Verifying before you deploy

Verify the signature is valid and was produced by this repository's CI:

```bash
IMAGE=ghcr.io/<owner>/aegis-server
DIGEST=sha256:…            # the digest you intend to run

cosign verify "${IMAGE}@${DIGEST}" \
  --certificate-identity-regexp '^https://github.com/<owner>/<repo>/' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'
```

Verify the attached CycloneDX SBOM attestation:

```bash
cosign verify-attestation "${IMAGE}@${DIGEST}" --type cyclonedx \
  --certificate-identity-regexp '^https://github.com/<owner>/<repo>/' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'
```

Verify the GitHub-native SLSA build provenance:

```bash
gh attestation verify "oci://${IMAGE}@${DIGEST}" --owner <owner>
```

## Enforce it at admission (recommended)

Verifying by hand is necessary but not sufficient — enforce it in the cluster so
**only** signed images from this repo can run. With Sigstore
[policy-controller](https://docs.sigstore.dev/policy-controller/overview/) or
Kyverno, require:

- a valid cosign signature whose certificate identity matches
  `^https://github.com/<owner>/<repo>/` and OIDC issuer
  `https://token.actions.githubusercontent.com`, and
- the presence of the SLSA provenance + SBOM attestations.

Pin workloads to image **digests** so the admission policy can't be bypassed by
re-tagging.

## Notes

- The default `./Dockerfile` (Render port-80 model) is **not** the release
  artifact; the signed image is built from `docker/Dockerfile.hardened` and pairs
  with `deploy/k8s/` (non-root, read-only rootfs, dropped capabilities).
- `install.php` is removed from the runtime image; run migrations out-of-band as
  the owner/migration DB role (see `database/roles.sql`).
- For an air-gapped / IL4+ mirror, pin the base image by `@sha256` digest in your
  registry mirror and re-sign in your own trust domain.
