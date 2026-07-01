# CPP — Defense & Signal-Processing Tool Collection

A portfolio of **12 standalone C++17 command-line utilities** for defense,
aerospace, and cybersecurity work: authenticated encryption, memory & file
forensics, log/packet correlation to MITRE ATT&CK, avionics bus decoding
(MIL-STD-1553B, ARINC 429), RF/EW spectrum anomaly detection, GNSS
anti-spoofing, CUI/PII classification, a Zero-Trust ABAC policy engine, and a
lightweight YARA-style scanner.

Each tool is a **single self-contained `.cpp` translation unit**. There is no
shared library, no framework, no database, and **no network I/O** in any tool.
They read files / stdin and write reports to stdout (diagnostics to stderr),
which makes them easy to pipe, script, containerize, and run air-gapped.

> **This is not a web service.** There is no server, no login, no HTTP endpoint,
> and no persistent state. "Deployment" here means *build, install, integrate,
> and run* the binaries. See [`render.yaml`](render.yaml) (Applicability: N/A)
> and the [deployment guides](#deployment-models) for the batch/Job model.

---

## The 12 tools

| Binary | Source | Purpose | External deps |
|--------|--------|---------|---------------|
| `aes-vault` | `aes-vault/aes_vault.cpp` | AES-256-GCM file vault; key via PBKDF2-HMAC-SHA256 (100k iters); per-file random salt+IV; optional embedded CUI `--label` | **OpenSSL** |
| `entropy-scanner` | `entropy-scanner/entropy_scanner.cpp` | Shannon entropy + chi-square uniformity scan of files/trees; flags packed/encrypted content; JSON + parallel | pthreads |
| `memory-scanner` | `memory-scanner/memory_scanner.cpp` | Live Linux process-memory IOC scan via `/proc/<pid>/{maps,mem}`; built-in Meterpreter/Cobalt Strike/Mimikatz/Empire signatures; optional `ptrace` | Linux `/proc`, ptrace |
| `mil1553-sim` | `mil-std-1553/mil1553_sim.cpp` | MIL-STD-1553B avionics bus simulator (BC/RT/BM); decodes command/status/data words; 10-message demo transcript | none |
| `log-correlator` | `log-correlator/log_correlator.cpp` | Multi-threaded syslog (RFC 3164/5424) + Windows-CSV correlator; sliding-window rules → MITRE ATT&CK (T1110/T1078/T1021/T1074/T1003/T1059.001/T1136) | pthreads, `<regex>` |
| `packet-analyzer` | `packet-analyzer/packet_analyzer.cpp` | Dependency-free PCAP parser (Ethernet/IPv4/IPv6/TCP/UDP) with DNS/HTTP/TLS-SNI extraction and C2/exfil heuristics → ATT&CK | POSIX (`arpa/inet.h`) |
| `rf-anomaly` | `rf-anomaly/rf_anomaly.cpp` | RF/EW IQ anomaly detector; self-contained Cooley-Tukey FFT + PSD; narrowband/wideband-jam/hop/unknown-emission detection | none |
| `gps-detector` | `gps-antispoofing/gps_detector.cpp` | GNSS anti-spoofing over NMEA 0183 (GGA/RMC/GSV); 6-indicator heuristic → NOMINAL/SUSPICIOUS/SPOOFED | none |
| `arinc429-decoder` | `arinc429/arinc429_decoder.cpp` | ARINC 429 avionics word decoder (BNR/BCD, SSM, odd parity, label bit-reversal) | none |
| `cui-classifier` | `cui-classifier/cui_classifier.cpp` | Recursive CUI/PII scanner: ITAR, EAR/ECCN, DoD markings, PII/SSN, HIPAA PHI, CVE/CVSS, ICS/SCADA, PAN (Luhn); redaction + JSON | `<regex>`, POSIX |
| `zt-policy` | `zt-policy-engine/zt_policy.cpp` | Zero-Trust ABAC engine; deny-overrides; mandatory clearance/classification check; audit log; built-in + file policies | none |
| `yara-lite` | `yara-lite/yara_lite.cpp` | YARA-subset scanner: string/hex/wildcard patterns, `and`/`or`/`not`, `any/all of them`; built-in ruleset | none |

Full per-tool I/O contracts (args, stdin/stdout/files, exit codes) are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

---

## Why it exists

These tools model the kinds of small, auditable, offline-capable primitives that
show up in defense and aerospace environments where large dependency trees,
network egress, and hosted services are undesirable or prohibited. Keeping each
tool to a single file with (near-)zero dependencies makes them easy to review,
reproduce, static-link, and drop into an air-gapped enclave.

---

## Technology

- **Language / standard:** C++17 (uses `std::filesystem`, `<optional>`,
  structured bindings, `std::async`).
- **Compiler:** `g++` or `clang++`. GNU/Clang builtins (`__builtin_popcount`,
  `__builtin_bswap16/32`) are used, so a fully portable MSVC build is not
  guaranteed for every tool. Verified with **g++ 13.3.0**.
- **Only external library:** OpenSSL (`libssl`/`libcrypto`), used solely by
  `aes-vault`. All other tools use the standard library only.
- **Platform notes:** `memory-scanner` is **Linux-only** (`/proc` + ptrace).
  `packet-analyzer` and `cui-classifier` use POSIX headers
  (`arpa/inet.h`, `unistd.h`) → Linux/macOS. The rest are portable across
  Linux/macOS/MinGW.

---

## Prerequisites

| Requirement | Version | Needed for |
|-------------|---------|------------|
| `g++` or `clang++` | C++17-capable (g++ ≥ 9, clang ≥ 10) | all tools |
| `make` | any GNU make | top-level build |
| `libssl-dev` / OpenSSL headers+libs | ≥ 1.1.0 (tested 3.0.13) | `aes-vault` only |
| `cmake` (optional) | ≥ 3.16 | alternative build |
| Linux kernel with `/proc` | any | running `memory-scanner` |

Debian/Ubuntu: `sudo apt-get install g++ make libssl-dev`

---

## Quick start (local development)

```bash
cd cpp

# Build all 12 tools into ./bin
make -j"$(nproc)"

# Or build one tool
make aes-vault

# Or build everything except OpenSSL/Linux-only tools (e.g. on macOS)
make portable

# Run the self-contained demos (no input files needed)
./bin/mil1553-sim
./bin/arinc429-decoder --demo
./bin/gps-detector           # synthetic clean + spoofed fixes
./bin/rf-anomaly             # synthetic IQ: tone + wideband jam

# Run on real input
echo "hello plaintext" > sample.txt
./bin/entropy-scanner sample.txt --verbose
./bin/cui-classifier . --ext .md,.txt --json
./bin/yara-lite --builtin ./bin/mil1553-sim
./bin/zt-policy --builtin --request examples/request.txt   # see docs
```

CMake alternative:

```bash
cmake -S . -B build -DCMAKE_BUILD_TYPE=Release && cmake --build build -j
```

Container:

```bash
docker build -t cpp-tools:latest .
docker run --rm cpp-tools:latest mil1553-sim
docker run --rm -v "$PWD:/data" cpp-tools:latest cui-classifier /data --json
```

---

## Common commands

| Command | Effect |
|---------|--------|
| `make` / `make all` | Build all 12 tools into `./bin` |
| `make <tool>` | Build a single tool (e.g. `make yara-lite`) |
| `make portable` | Build the 10 tools with no OpenSSL/Linux dependency |
| `make install PREFIX=/usr/local` | Install binaries to `$(PREFIX)/bin` |
| `make clean` | Remove `./bin` |
| `docker build -t cpp-tools .` | Build the multi-stage build/runtime image |

---

## Deployment models

Because these are CLI tools, "deployment" = build + install + integrate + run.
Each guide below is adapted to that reality (verification = *compiles, help/usage
works, runs on a sample and produces the expected report* — there is no health
endpoint, login, upload, or DB).

- [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) — toolchain, compile, run with samples.
- [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) — install to a host; systemd oneshot/timer for scheduled sweeps.
- [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) — run tools as Jobs/CronJobs in a container.
- [`deployments/AZURE.md`](deployments/AZURE.md) — CI build (workload identity), Blob artifacts, Container Instances / batch (Commercial + Azure Government).
- [`deployments/AWS.md`](deployments/AWS.md) — CI build (OIDC role), S3 artifacts, Fargate/Batch (Commercial + GovCloud).
- [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md) — fully offline compile, static linking, vendored toolchain, signed binary bundles.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — the 12 tools, shared conventions, I/O contract, no-runtime-deps design.
- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — build systems, cross-compile, static linking, production checklist (Ollama/GPU: N/A).
- [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md) — git as source of truth, reproducible/pinned-toolchain builds.
- [`docs/SECURITY.md`](docs/SECURITY.md) — these *are* security tools: memory safety, untrusted-input handling, CUI handling, supply chain, reporting.

## Build status

No CI is configured in this repository yet. The build is verified locally with
`make -j` on g++ 13.3.0 (Ubuntu 24.04); all 12 tools compile and the demo modes
run. See [`OPEN_ITEMS.md`](OPEN_ITEMS.md) for the honest readiness register.

## License

MIT (per each source file header). Author: Jessica Rojas — Systems & Zero-Trust
Portfolio.
