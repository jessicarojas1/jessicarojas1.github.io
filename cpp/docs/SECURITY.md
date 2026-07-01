# Security — CPP Tool Collection

> These **are security tools**, and they deliberately ingest **untrusted input**
> — network packets, RF/IQ captures, syslog/Event-Log data, arbitrary files,
> and live process memory. The security posture below is therefore about the
> **robustness and correct handling of the tools themselves**, not about
> protecting a service (there is none: no network, no server, no accounts, no
> database).

## Threat model in one line

The adversary controls the *input* (a crafted pcap, IQ file, log line, NMEA/
ARINC stream, YARA target file, or the memory of a process under analysis). The
goal is that a tool never (a) executes attacker-controlled code, (b) corrupts
memory, or (c) exfiltrates data — it either produces a correct report or fails
safely with a nonzero exit code.

## Identity & authentication

**N/A** — no logins, sessions, tokens, or user accounts. The only credential
anywhere is the interactive `aes-vault` passphrase:

- Read from the terminal with echo disabled (`termios`/`SetConsoleMode`), never
  from a command-line flag or environment variable, never persisted.
- Confirmed twice on encryption to avoid lock-out from a typo.
- Used only to derive a key; the passphrase itself never touches disk.

For *deployment* identity (CI pushing artifacts/images), prefer **OIDC roles /
workload identity** over static keys — see [AWS](../deployments/AWS.md) and
[AZURE](../deployments/AZURE.md).

## Authorization

**N/A for the tools' own operation.** The one tool *about* authorization,
`zt-policy`, models Zero-Trust ABAC for a caller's own environment:

- **Deny-overrides** combining algorithm: any matching `DENY` wins.
- A **mandatory** clearance-vs-classification check runs before policy
  evaluation (a subject cleared below the resource's classification is denied
  regardless of policy).
- Default-deny when no policy matches.
- Every decision is written to an **audit line on stderr** with subject,
  resource, action, decision, matched policy, and reason.

Operating-system authorization still applies to running the tools:
`memory-scanner` requires **root or `CAP_PTRACE`** to read `/proc/<pid>/mem`;
it fails closed (with a warning) if it cannot attach.

## Data protection

### Encryption at rest (`aes-vault`)
- **AES-256-GCM** authenticated encryption.
- Key = **PBKDF2-HMAC-SHA256**, **100 000 iterations**, over a per-file random
  **32-byte salt**.
- Per-file random **12-byte IV** (96-bit GCM nonce) via `RAND_bytes`.
- **16-byte GCM tag** stored in the header and **verified before any plaintext
  is written** — a wrong passphrase or any tampering fails decryption with a
  nonzero exit and no output.
- Optional CUI `--label` is placed *inside* the authenticated plaintext, so the
  marking is integrity-protected and travels with the data.

### Encryption in transit
**N/A** — no tool sends data anywhere. Move vault files and reports with your
own encrypted transport (SSH/TLS, encrypted object storage).

### Key management
`aes-vault` derives keys from a user passphrase; there is no key store to
manage. Callers are responsible for passphrase custody (a lost passphrase is
unrecoverable by design — see [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).
Consider Argon2id and configurable iterations as a future hardening
(`OPEN_ITEMS.md`).

## Memory safety (the core of this project's security)

These parsers hand-decode binary/text from untrusted sources, which is exactly
where C++ memory bugs live. Current state and obligations:

- **Bounds checks at parse boundaries.** `packet-analyzer` validates pcap
  record lengths, IHL/TCP-header lengths, and TLS/DNS extension/label lengths
  before indexing, follows DNS compression pointers with a jump cap, and skips
  absurd packet sizes. `yara-lite` checks `data.size() >= pattern.size()` before
  matching. `arinc429`/`gps`/`mil1553` operate on fixed-width fields.
- **Known sharp edges (see `OPEN_ITEMS.md`):** hand-rolled offset arithmetic in
  the pcap/TLS/DNS paths, and `std::stoi/stod/stoul` on untrusted numeric fields
  (`gps-detector`, `arinc429-decoder`, `memory-scanner`) which can throw or
  overflow. These are the priority fuzz/hardening targets.
- **Required before shipping to production:** compile the untrusted-input
  parsers with **ASan + UBSan**, and **fuzz** them (libFuzzer/AFL++):
  `packet-analyzer`, `yara-lite`, `gps-detector`, `arinc429-decoder`,
  `log-correlator`, and `entropy-scanner`.
- **Recommended release flags:** `-O2 -D_FORTIFY_SOURCE=2
  -fstack-protector-strong` (and PIE, which is default on modern GCC).

## Network exposure

**None by design.** No tool opens a socket, resolves DNS, or makes an HTTP
request. `packet-analyzer` reads pcap **files** (not live capture);
`log-correlator` reads log **files**; `rf-anomaly`/`gps-detector` read capture
files or synthesize data; `memory-scanner` reads `/proc`. This eliminates an
entire class of remote attack surface and makes the tools suitable for
air-gapped enclaves. Do not add network I/O.

## Auditability

- `zt-policy` emits a structured **audit line per decision** to stderr
  (timestamp, subject, resource, action, decision, policy, reason).
- `cui-classifier`, `yara-lite`, `log-correlator`, and the scanners print
  **scan summaries** and per-finding detail; JSON modes exist for
  `entropy-scanner`, `packet-analyzer`, and `cui-classifier` for SIEM ingest.
- When run as a container/Job, stdout+stderr and the **exit code** form the
  audit trail; ship them to your log sink.

## Classification & DLP (CUI / data handling)

- **`cui-classifier`** is the DLP primitive: it detects ITAR/USML, EAR/ECCN, DoD
  markings (CUI/FOUO/NOFORN/ORCON), PII (SSN/passport/DOB), HIPAA PHI, CVE/CVSS,
  ICS/SCADA references, and payment-card PANs (validated with the **Luhn**
  algorithm to cut false positives).
- **Its output can itself be CUI.** The tool **redacts** SSN/PAN/passport/
  MRN/DOB matches (keeps first/last char), writes the human summary to
  **stderr**, and its source explicitly warns against logging matched content to
  a shared syslog facility. Route its stdout/JSON to a controlled, access-limited
  sink.
- Binary files are skipped (NUL-byte sniff) to avoid dumping raw binary as
  "context."
- **`aes-vault --label`** carries a CUI marking inside the authenticated
  ciphertext so classification survives encryption.

## FIPS readiness

`aes-vault` uses OpenSSL's EVP APIs (AES-256-GCM, PBKDF2-HMAC-SHA256) — all
FIPS-approved algorithms. For a FIPS posture, build/link against a
**FIPS-validated OpenSSL** (OpenSSL 3.x FIPS provider) and enable the FIPS
provider on the host; the tool's algorithm choices already fall within the
approved set. The other tools perform no cryptography. In GovCloud/Gov
deployments, use **FIPS regional endpoints** for any artifact transport (see AWS/
Azure guides).

## Operator responsibilities

- Run `memory-scanner` with least privilege; audit who can ptrace processes.
- Treat `cui-classifier` output and `aes-vault` vault files as sensitive; control
  their storage and transport.
- Keep OpenSSL patched and rebuild `aes-vault` on CVEs.
- Verify binary provenance: build from pinned source, prefer signed release
  bundles (see [AIRGAPPED](../deployments/AIRGAPPED.md)), pull images from
  private registries over TLS.
- Do not introduce network calls or new dependencies (supply-chain minimization
  is a security control here).

## Secrets rotation

The only secret is the `aes-vault` passphrase. To rotate: `aes-vault decrypt`
with the old passphrase, then `aes-vault encrypt` with the new one (a fresh
random salt+IV is generated automatically). For deployment (CI) credentials,
prefer short-lived OIDC role sessions that rotate automatically.

## Supply chain

- **Minimal dependencies** (only OpenSSL, one tool) shrink the attack surface.
- Pin the base image (`debian:12-slim`) and toolchain; record versions per
  release (see [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).
- **Recommended:** generate an SBOM (CycloneDX), scan the image, and sign
  release binaries/images (cosign) — tracked in `OPEN_ITEMS.md`.
- Build offline from a vendored toolchain for air-gapped targets so no
  build-time network fetch can be tampered with.

## Reporting

Report suspected vulnerabilities (memory-safety crashes, parser OOB, incorrect
crypto behavior) privately to the maintainer — **Jessica Rojas** — with a
minimal reproducing input and the tool + commit hash. Do not file public issues
with exploit inputs. Target acknowledgement: **72 hours**; triage/fix timeline
communicated on acknowledgement. Because there is no deployed service, there is
no incident to contain beyond patching the source and rebuilding affected
binaries.
