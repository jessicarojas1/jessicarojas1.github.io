# Disaster Recovery — CPP Tool Collection

> **Applicability.** These are stateless CLI tools. They hold **no runtime
> state** — no database, no object storage, no service to fail over. The only
> durable, valuable state is **the source code** and the **build inputs**
> (toolchain + OpenSSL version). Recovery is therefore "rebuild from source,"
> which is fast, deterministic, and offline-capable.

## What holds state

| State | Where it lives | Criticality | Notes |
|-------|----------------|-------------|-------|
| Tool source (12 `.cpp`) | Git repository | **Critical** | The single source of truth |
| Build files (`Makefile`, `CMakeLists.txt`, `Dockerfile`) | Git | Critical | Define a reproducible build |
| Docs / deployment guides | Git | Important | Rebuildable knowledge |
| Compiled binaries (`./bin`, images, artifacts) | Build output / registry / S3 / Blob | **Rebuildable** | Never the source of truth; regenerate from git |
| Toolchain + OpenSSL versions | Base image / pinned CI | Important | Needed for byte-reproducibility |
| Tool *input* data (pcaps, logs, IQ, docs to scan) | Caller-owned | Out of scope | Backed up by whoever owns the data, not by this project |
| Tool *output* reports | Caller-owned sink | Out of scope | Re-runnable against retained inputs |

There is no per-tool persisted state between runs. `aes-vault` **output files**
(`.vault`) are user data owned by the caller — they are recoverable only with
the passphrase (see below), and are not part of this project's state.

## RPO / RTO targets

Because the artifact is fully rebuildable from git, targets are aggressive and
bounded by build time, not data restore:

| Metric | Target | Rationale |
|--------|--------|-----------|
| **RPO** (source) | 0 (last git commit) | Git is the authoritative store; nothing else is unique |
| **RTO** (rebuild all 12 tools) | Minutes | `make -j` completes in seconds–minutes on a laptop/CI |
| **RTO** (container image) | Minutes | `docker build` from the pinned Dockerfile |

There is no data-loss RPO for the tools themselves. For **caller data**
(`aes-vault` vault files, retained inputs/outputs), RPO/RTO are set by the
caller's own backup policy — out of scope here.

## Backups

- **Source:** the git remote(s). Mirror to a second remote for defense in depth.
  Tag releases so a specific tool version is reconstructable.
- **Toolchain:** record `g++ --version` / `cmake --version` and the OpenSSL
  version with each release; for air-gap, keep the offline toolchain bundle (see
  [AIRGAPPED](../deployments/AIRGAPPED.md)).
- **Binaries/images:** treat as cache. If you retain them, store in a registry
  or object store with versioning enabled — but they are always regenerable.
- **Encryption of backups:** source is not secret; if a mirror lives in an
  untrusted location, encrypt the bundle (e.g. with `aes-vault` itself, or the
  platform's SSE).

## Restore runbook (rebuild from source)

Copy-pasteable, numbered:

```bash
# 1. Obtain the source (clone the repo or restore the git mirror)
git clone <remote-url> portfolio && cd portfolio/cpp
#    (air-gap: extract the offline source bundle instead)

# 2. Check out the intended release
git checkout <tag-or-commit>

# 3. Restore the toolchain (skip if the host already has it)
sudo apt-get install -y g++ make libssl-dev      # Debian/Ubuntu
#    (air-gap: install from the vendored toolchain bundle)

# 4. Rebuild all tools
make clean && make -j"$(nproc)"

# 5. Verify (compile + demos + sample runs)
ls bin | wc -l                                   # expect 12
bin/mil1553-sim      | grep -q "Bus Monitor Transcript" && echo OK
bin/arinc429-decoder --demo | grep -q "Decoded value"   && echo OK
bin/gps-detector     | grep -q "SPOOFED"                && echo OK
bin/rf-anomaly       | grep -q "RF Anomaly Report"      && echo OK
echo "hello plaintext" > /tmp/s.txt
bin/entropy-scanner /tmp/s.txt --verbose | grep -q PLAINTEXT && echo OK

# 6. Reinstall / redistribute
sudo make install PREFIX=/usr/local
#    or rebuild the image:  docker build -t cpp-tools:latest .
```

### Recovering `aes-vault`-encrypted data
Vault files are self-contained (salt + IV + tag + ciphertext in the header) and
decrypt on **any** rebuilt `aes-vault` with the **correct passphrase**:

```bash
aes-vault decrypt report.vault report_out.pdf
```

There is **no passphrase recovery** — AES-256-GCM with PBKDF2 is designed so a
lost passphrase means lost data. Callers must back up passphrases in their own
secrets manager. This is a property of the tool, not a recoverable failure.

## Verification cadence (restore drills)

- **Per release / per PR (recommended):** CI (`.github/workflows/cpp-ci.yml`)
  builds all 12 tools on a GCC+Clang matrix and runs `tests/run_tests.sh` (the
  smoke/contract suite) on every push/PR touching `cpp/**` — this *is* a
  continuous restore drill (build-from-source proven on every change).
- **Quarterly:** perform a cold rebuild on a clean host (and, for air-gap, from
  the offline bundle) to prove toolchain + OpenSSL are still available and the
  binaries still pass the demo checks.
- **On OpenSSL CVE:** rebuild `aes-vault` against the patched OpenSSL and
  re-verify encrypt/decrypt round-trips.

## High availability

Not applicable in the service sense — there is nothing running to keep
available. "HA" for a tool collection means **build/distribution availability**:

- Multiple git remotes/mirrors so source is always reachable.
- Artifacts/images replicated across registries (and regions/partitions for
  Commercial vs GovCloud — see [AWS](../deployments/AWS.md) /
  [AZURE](../deployments/AZURE.md)).
- Because any host with the toolchain can rebuild in minutes, there is no
  single point of runtime failure to engineer around.
