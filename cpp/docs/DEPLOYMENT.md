# Deployment — CPP Tool Collection

> **Applicability.** These are 12 standalone C++ CLI tools, not a web service.
> "Deployment" = **build → install → integrate → run**. There is no server,
> port, health endpoint, login, database, migration, or background worker inside
> the app. Sections that would cover those are marked N/A with the nearest real
> equivalent.

## Contents

1. [Deployment models](#deployment-models)
2. [Prerequisites](#prerequisites)
3. [Build systems](#build-systems)
4. [Cross-compilation](#cross-compilation)
5. [Static linking (for air-gap / portability)](#static-linking)
6. [Configuration & secrets](#configuration--secrets)
7. [Database migrations](#database-migrations) — N/A
8. [Background/worker process](#backgroundworker-process)
9. [Ollama configuration](#ollama-configuration) — N/A
10. [GPU acceleration](#gpu-acceleration) — N/A
11. [Verification](#verification)
12. [Production checklist](#production-checklist)

## Deployment models

| Model | What it means here | Guide |
|-------|--------------------|-------|
| Managed PaaS | Build the image; run tools as one-off/cron jobs (no persistent service) | [Render note](../render.yaml), [AWS](../deployments/AWS.md), [Azure](../deployments/AZURE.md) |
| Single server | Compile + `make install` to a VM; run by hand or via systemd timer | [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | Containerize; run as Jobs / CronJobs for batch processing | [KUBERNETES](../deployments/KUBERNETES.md) |
| Cloud (AWS / Azure) | CI build with workload identity → artifact store → Fargate/Batch/Container Instances | [AWS](../deployments/AWS.md), [AZURE](../deployments/AZURE.md) |
| Air-gapped | Fully offline compile, static link, signed bundle | [AIRGAPPED](../deployments/AIRGAPPED.md) |
| Local dev | Toolchain + `make` + run demos | [LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) |

## Prerequisites

- `g++` ≥ 9 or `clang++` ≥ 10 (C++17). Verified: **g++ 13.3.0**.
- GNU `make` (canonical build) or CMake ≥ 3.16 (optional).
- OpenSSL headers + libs (`libssl-dev`) — **only** for `aes-vault`.
- Linux with `/proc` — **only** to *run* `memory-scanner`.

Debian/Ubuntu: `sudo apt-get install g++ make libssl-dev`
RHEL/UBI: `dnf install gcc-c++ make openssl-devel`

## Build systems

**Make (canonical):**

```bash
cd cpp
make -j"$(nproc)"                 # all 12 → ./bin
make aes-vault                    # one tool
make portable                     # skip OpenSSL/Linux-only tools
make install PREFIX=/usr/local    # copy binaries to $(PREFIX)/bin
make clean
```

Overridable variables: `CXX`, `CXXSTD`, `OPT`, `WARN`, `CXXFLAGS`,
`OPENSSL_LIBS`, `PTHREAD`, `PREFIX`, `DESTDIR`. Example with clang and a custom
OpenSSL:

```bash
make CXX=clang++ OPENSSL_LIBS="-L/opt/openssl/lib -lssl -lcrypto"
```

**CMake (optional):**

```bash
cmake -S . -B build -DCMAKE_BUILD_TYPE=Release
cmake --build build -j
cmake --install build --prefix /usr/local   # optional
```

CMake auto-skips `aes-vault` if OpenSSL is absent and `memory-scanner` on
non-Linux, still building the remaining tools.

**Docker (multi-stage build image):**

```bash
docker build -t cpp-tools:latest .
docker run --rm cpp-tools:latest mil1553-sim
```

Stage 1 (`debian:12-slim` + `g++ make libssl-dev`) runs `make all`; stage 2
(`debian:12-slim` + `libssl3 libstdc++6`) copies `/usr/local/bin` and runs as
non-root user `tools` (uid 10001).

## Cross-compilation

The portable tools cross-compile with a cross g++ (no external libs). Example
(aarch64):

```bash
make CXX=aarch64-linux-gnu-g++ portable
```

`aes-vault` additionally needs an OpenSSL built for the target
(`OPENSSL_LIBS`/sysroot). `memory-scanner` only makes sense on a Linux target.
For Windows, MinGW builds most tools, but note GNU builtins and POSIX headers
(`arpa/inet.h`, `/proc`, ptrace) limit `packet-analyzer`, `cui-classifier`, and
`memory-scanner` — build those on Linux.

## Static linking

For maximum portability / air-gap, statically link the standard library on the
portable tools. The `static` make target does this for the 10 portable+threaded
tools (output: `./bin/<tool>-static`; `aes-vault`/`memory-scanner` excluded):

```bash
make static
# or drive the flags directly:
make portable CXXFLAGS="-std=c++17 -O2 -static -static-libstdc++ -static-libgcc"
```

`aes-vault` needs a static OpenSSL (`libssl.a`/`libcrypto.a`) to fully static
link. See [AIRGAPPED](../deployments/AIRGAPPED.md).

## Configuration & secrets

- **App config:** none. All behavior is via CLI flags (see `README.md` /
  `docs/ARCHITECTURE.md`). No config files or env vars are read at runtime.
- **Secrets:** the only secret is the **`aes-vault` passphrase**, read
  interactively from the terminal with echo disabled — it is never taken from a
  flag or environment variable and never written to disk. Do not add a
  passphrase flag. When automating, pipe on stdin from a secrets manager and
  treat the vault file's location as sensitive.

## Database migrations

**N/A** — no database, no persistent state, no migrations. Nearest equivalent:
version the built binaries and rebuild from pinned sources (git is the source of
truth; see [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).

## Background/worker process

**No worker inside the app.** Any "scheduling" is external:

- systemd `oneshot` service + timer (single server) — see
  [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md).
- Kubernetes `CronJob`/`Job` (batch) — see [KUBERNETES](../deployments/KUBERNETES.md).
- AWS EventBridge → Batch/Fargate, Azure Container Instances + Logic Apps /
  Scheduler — see [AWS](../deployments/AWS.md) / [AZURE](../deployments/AZURE.md).

## Ollama configuration

**N/A** — no tool has an AI/LLM feature, so there is no hosted-AI API to replace
with self-hosted Ollama. If a future tool adds an LLM feature, this section will
document an offline Ollama endpoint per the standard.

## GPU acceleration

**N/A** — no tool uses a GPU. `rf-anomaly` runs an FFT on the CPU; its source
mentions the optional `-march=native` flag for CPU vectorization, which is a
build-time CPU optimization, not GPU offload. (Avoid `-march=native` for
portable/air-gap binaries; it pins the build to the builder's CPU.)

## Verification

There is no health/login/DB/upload to check. Verify a deployment by confirming
each tool **compiles, prints help/usage, and runs on a sample producing the
expected report**:

```bash
# 1) Compiles
make -j && ls bin | wc -l          # expect 12

# 2) Help / usage prints (explicit --help where supported)
bin/memory-scanner --help
bin/log-correlator --help
bin/zt-policy                      # prints usage on no args
bin/aes-vault                      # prints usage on no args

# 3) Runs on a sample → expected output
bin/mil1553-sim | grep -q "Bus Monitor Transcript" && echo OK
bin/arinc429-decoder --demo | grep -q "Decoded value" && echo OK
bin/gps-detector | grep -q "SPOOFED" && echo OK          # demo injects a spoof
bin/rf-anomaly  | grep -q "RF Anomaly Report" && echo OK
echo "hello plaintext" > /tmp/s.txt
bin/entropy-scanner /tmp/s.txt --verbose | grep -q PLAINTEXT && echo OK
bin/yara-lite --builtin bin/mil1553-sim; echo "exit=$?"   # 2 = ELF matched
bin/cui-classifier . --ext .md >/dev/null; echo "exit=$?" # 0 clean / 2 found
```

In a container, the same commands run via `docker run --rm cpp-tools:latest
<tool> <args>`; the Dockerfile `HEALTHCHECK` simply executes `mil1553-sim` to
prove the binaries are runnable.

## Production checklist

### Secrets & identity
- [ ] `aes-vault` passphrases come from an interactive prompt or piped secret,
      never a flag/env/committed file.
- [ ] CI build uses **workload identity / OIDC role** (no static cloud keys) to
      push artifacts/images — see AWS/Azure guides.
- [ ] `memory-scanner` runs under the least privilege that still allows ptrace;
      not as blanket root where avoidable.

### Transport & exposure
- [ ] No tool is exposed as a network service (by design — confirm nothing wraps
      them in a listener).
- [ ] Artifact/image registries are private; pull over TLS.

### Hardening
- [ ] Release builds use `-O2` and (recommended) `-D_FORTIFY_SOURCE=2`,
      `-fstack-protector-strong`; consider `-Wall -Wextra -Werror` once warnings
      are cleared (see `OPEN_ITEMS.md`).
- [ ] Untrusted-input parsers fuzzed and run under ASan/UBSan before shipping
      (`packet-analyzer`, `yara-lite`, `gps-detector`, `arinc429-decoder`,
      `log-correlator`).
- [ ] Container runs as non-root (it does), read-only root filesystem where the
      tool only reads input.
- [ ] `cui-classifier` output routed to a controlled sink (it may contain CUI).

### Resilience & operations
- [ ] Pinned toolchain / reproducible build recorded with each artifact.
- [ ] OpenSSL version tracked and patched for `aes-vault`.
- [ ] Batch jobs have timeouts and resource limits; large inputs bounded.
- [ ] Exit codes wired into pipeline/Job success criteria.
