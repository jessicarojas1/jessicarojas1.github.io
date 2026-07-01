# Air-Gapped — CPP Tool Collection

**Applicability:** Highly applicable — this is the natural home for these tools.
They make **no network calls**, have (near-)zero third-party dependencies, and
each is a single C++17 file, so they compile and run fully offline. This guide
covers building with a **vendored toolchain**, **static linking**, producing a
**signed offline bundle**, and installing into a disconnected enclave. There is
no hosted AI/LLM feature anywhere, so the standard's "replace hosted AI with
Ollama" step is **N/A** (stated explicitly below).

## 1. Deployment architecture

On a connected staging host, produce an **offline bundle**: the source, an
offline toolchain (compiler + `libssl` static libs), a build script, and/or
prebuilt static binaries. Transfer the bundle across the air gap (approved
media). On the disconnected build host, compile from the vendored toolchain (or
just install the prebuilt static binaries), verify checksums/signatures, and
run. No registry, package index, CVE feed, or secret store on the internet is
contacted.

## 2. Topology

```
 ┌── connected staging ──┐        approved       ┌──── air-gapped enclave ────┐
 │ git source (pinned)   │        media          │ verify sig/checksum        │
 │ offline toolchain     │  ──tar+sign+hash──▶    │ (vendored g++ + libssl.a)  │
 │ static binaries       │                        │ make -static  OR  install  │
 │ SBOM + signatures     │                        │ run tools on local inputs  │
 └───────────────────────┘                        └────────────────────────────┘
        No internet on either side of the transfer at run time.
```

## 3. Prerequisites

- **Staging (connected):** git, the target's toolchain packages
  (`g++`/`gcc-c++`, `make`, static OpenSSL: `libssl-dev` + `libssl.a`/
  `libcrypto.a`), a signing key (GPG/cosign), `sha256sum`, `tar`.
- **Enclave (disconnected):** the transferred bundle and an approved media path.
  No package manager access required if you ship static binaries.

## 4. Identity & credentials

- **No runtime credentials** — the tools reach nothing. The only secret is the
  interactive `aes-vault` passphrase, typed locally at a no-echo prompt.
- **Bundle provenance is the trust anchor:** sign the bundle on staging and
  verify the signature + `sha256sum` inside the enclave before use. Keep the
  signing key off the enclave.
- Air-gapped OpenSSL for `aes-vault` should be a **FIPS-validated** build where
  required (see `docs/SECURITY.md`).

## 5. Environment variables

The tools read no env vars. Build/bundle variables only:

| Variable | Example | Purpose |
|----------|---------|---------|
| `CXX` | `g++` | Compiler (vendored) |
| `CXXFLAGS` | `-std=c++17 -O2 -static -static-libstdc++ -static-libgcc` | Fully static portable tools |
| `OPENSSL_LIBS` | `/opt/vendor/openssl/lib/libssl.a /opt/vendor/openssl/lib/libcrypto.a` | Static OpenSSL for `aes-vault` |
| `PREFIX` | `/opt/cpp` | Install location in the enclave |

## 6. Configuration references

No config files. Tool behavior is CLI flags; keep the invocation scripts in the
bundle. Offline **CVE/patch feeds** for the toolchain and OpenSSL are mirrored
out-of-band (approved media), not fetched — track the OpenSSL version in the
bundle's SBOM.

## 7. Verification

No health/login/DB/upload. Inside the enclave, verify integrity, build/install,
and sample runs:

```bash
# Integrity first
sha256sum -c cpp-bundle.sha256
gpg --verify cpp-bundle.tar.gz.sig cpp-bundle.tar.gz     # or: cosign verify-blob

tar xzf cpp-bundle.tar.gz && cd cpp

# Option A: build from vendored toolchain (fully static portable tools)
make portable CXXFLAGS="-std=c++17 -O2 -static -static-libstdc++ -static-libgcc"
make aes-vault OPENSSL_LIBS="/opt/vendor/openssl/lib/libssl.a /opt/vendor/openssl/lib/libcrypto.a"
# Option B: just install the prebuilt static binaries
#   install -m755 prebuilt/* /opt/cpp/bin/

# Sample runs (no inputs / no network)
bin/mil1553-sim | grep -q "Bus Monitor Transcript" && echo OK
bin/arinc429-decoder --demo | grep -q "Decoded value" && echo OK
bin/gps-detector | grep -q "SPOOFED" && echo OK
bin/rf-anomaly | grep -q "RF Anomaly Report" && echo OK
echo "plaintext sample" > /tmp/s.txt
bin/entropy-scanner /tmp/s.txt --verbose | grep -q PLAINTEXT && echo OK
ldd bin/mil1553-sim || echo "static (no dynamic deps) — good for air-gap"
```

## 8. Day-2 operations

- **Update bundles:** rebuild the signed bundle on staging from a new pinned
  git tag; transfer via approved media; re-verify signature/checksum before
  installing. Keep the previous bundle for rollback.
- **Toolchain refresh:** mirror new compiler/OpenSSL packages out-of-band;
  rebuild; record versions in the SBOM.
- **CVE handling:** when an OpenSSL CVE lands, rebuild `aes-vault` against the
  patched static OpenSSL in a new bundle (the other tools have no third-party
  deps to patch).
- **Backups:** git is the source of truth (mirror it inside the enclave if the
  enclave has its own SCM); keep signed bundles + SBOMs. See
  `docs/DISASTER_RECOVERY.md`.
- **Registry/images (if using containers offline):** mirror `debian:12-slim` and
  the built `cpp-tools` image into an **internal registry**; import with
  `docker load` from a saved tar. No pulls from Docker Hub.

## 9. Ollama / self-hosted LLM

**N/A.** No tool in this collection has an AI/LLM feature or calls a hosted AI
API, so there is nothing to replace with a self-hosted Ollama inference server.
If a future tool adds an LLM-backed capability, the air-gapped path would run
Ollama locally with a pre-pulled model — but today this is intentionally empty.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `libssl.so.3: cannot open` at runtime | dynamically linked OpenSSL absent in enclave | rebuild `aes-vault` with **static** `libssl.a`/`libcrypto.a` |
| `cannot find -lstdc++`/`-lgcc` when `-static` | static libstdc++/libgcc not installed | add them to the vendored toolchain (`libstdc++-*-dev`, `-static-libstdc++ -static-libgcc`) |
| Signature/checksum verify fails | tampered/corrupt transfer | re-transfer on clean media; do not install unverified bundles |
| `static assertion failed: requires Linux` | building `memory-scanner` off-Linux | build it on a Linux target only; `make portable` skips it |
| Build tries to reach the network | not truly offline (pkg-config/registry lookup) | build with explicit `OPENSSL_LIBS`; use `--offline`/vendored deps; confirm no DNS in build logs |
| `-march=native` binary crashes on other host | build pinned to staging CPU | never use `-march=native` for air-gap; use a generic `-O2` baseline |
