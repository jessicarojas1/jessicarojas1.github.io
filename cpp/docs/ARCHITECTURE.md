# Architecture — CPP Tool Collection

## Platform it's built on

Plain **C++17** compiled to native ELF/Mach-O executables. No framework, no
runtime, no interpreter. The "platform" is the C++ standard library plus the
host OS syscalls that a handful of tools use directly (Linux `/proc`, POSIX
`arpa/inet.h`/`unistd.h`). The only third-party library is **OpenSSL**, used by
exactly one tool (`aes-vault`).

## Design principles

1. **One file per tool.** Every utility is a single `.cpp` translation unit in
   its own subdirectory. Tools never link against each other.
2. **Near-zero dependencies.** Ten of twelve tools use only the standard
   library. This keeps them auditable, reproducible, static-linkable, and
   air-gap friendly.
3. **No network.** No tool opens a socket, resolves DNS, or makes an HTTP call.
   All data comes from files, stdin, or (for `memory-scanner`) `/proc`.
4. **Unix-composable.** Read files/stdin, write a human report to stdout, put
   diagnostics/summaries/audit on stderr, and set a **meaningful exit code** so
   the tools drop into pipelines and CI.
5. **Deterministic demos.** Tools that model hardware feeds ship a synthetic/
   demo mode seeded with a fixed PRNG so output is reproducible without real
   captures.

## Component overview — the 12 tools

Grouped by domain. Every tool is independent; the grouping is conceptual only.

### Cryptography & data protection
- **`aes-vault`** — AES-256-GCM file vault. Key = PBKDF2-HMAC-SHA256(passphrase,
  random 32-byte salt, 100 000 iterations). Per-file random 12-byte IV; 16-byte
  GCM tag. Self-describing 65-byte header (`AESV` magic, version, salt, IV, tag)
  followed by ciphertext. Optional `--label "CUI//SP-CTI"` embeds a
  null-terminated marking inside the authenticated plaintext.

### Host & file forensics
- **`entropy-scanner`** — computes Shannon entropy (0–8 bits/byte) **and** a
  chi-square uniformity test per file, over a file or a directory tree; the two
  statistics together separate encrypted (uniform) from merely compressed
  (structured) data. Optional multithreaded computation, JSON output.
- **`memory-scanner`** — **Linux-only.** Parses `/proc/<pid>/maps`, reads
  readable regions via `pread` on `/proc/<pid>/mem`, and Boyer-Moore-Horspool
  searches for built-in IOC byte signatures (Meterpreter, Cobalt Strike,
  Mimikatz, Empire, reflective-DLL, NOP sleds, memfd). Optional
  `PTRACE_ATTACH` to freeze the target for a consistent snapshot.
- **`yara-lite`** — parses a subset of the YARA rule language (string, hex, and
  `??`-wildcard patterns; `nocase`; `and`/`or`/`not`/parentheses;
  `any/all of them`) and scans files/dirs. Ships a built-in ruleset (MZ/ELF
  headers, PowerShell download cradles, C2 user-agents, base64-PE).

### Log, packet & signal analysis
- **`log-correlator`** — parses syslog (RFC 3164/5424) and Windows Event-Log
  CSV, classifies events, and correlates them across a sliding time window into
  MITRE ATT&CK detections (T1110 Brute Force, T1078 Valid Accounts, T1021
  Remote Services, T1074 Data Staged, T1003 Credential Dumping, T1059.001
  PowerShell, T1136 Create Account). Uses a small internal thread pool to parse
  files in parallel; correlation is single-threaded over the merged set.
- **`packet-analyzer`** — a self-written libpcap **file** reader (no libpcap
  dependency). Auto-detects endianness, strips Ethernet/802.1Q, decodes
  IPv4/IPv6 → TCP/UDP, and parses DNS names (with compression pointers), HTTP
  Host/User-Agent, and TLS ClientHello SNI. Heuristics map to ATT&CK
  (T1071.001/.004, T1041, T1568) plus a beaconing detector.
- **`rf-anomaly`** — reads raw IQ samples (`float32` or `int16`), applies a Hann
  window, runs a self-contained radix-2 Cooley-Tukey FFT, computes PSD in dBFS,
  and detects narrowband interference, wideband jamming, frequency hopping, and
  unknown emissions (vs a `--known` list). Has a synthetic IQ generator and demo.
- **`gps-detector`** — GNSS anti-spoofing over NMEA 0183 (`$GPGGA/$GPRMC/$GPGSV`).
  Six indicators (position jump, C/N0 clustering, C/N0 too high, velocity
  inconsistency, time-step jump, under-constrained fix) score each fix as
  NOMINAL / SUSPICIOUS / SPOOFED. Reads a file, stdin (`-`), or generates a
  synthetic clean+spoofed stream (`--gen`).

### Avionics bus decoding
- **`mil1553-sim`** — MIL-STD-1553B bus simulator with Bus Controller / Remote
  Terminal / Bus Monitor roles; encodes/decodes 16-bit command/status/data
  words with odd parity and prints a 10-message bus transcript. Demo-only I/O.
- **`arinc429-decoder`** — decodes 32-bit ARINC 429 words (8-bit label with
  LSB-first bit-reversal, SDI, 18-bit BNR/BCD data, SSM, odd parity) for a set
  of standard labels (airspeed, Mach, altitude, heading, lat/lon). Demo mode,
  file mode, or stdin (`-`) reading hex words one per line.

### Access control & data governance
- **`zt-policy`** — Zero-Trust **ABAC** engine. Loads policies (built-in and/or
  from a `.policy` file), evaluates a request (from a file or stdin) with a
  **deny-overrides** algorithm plus a mandatory clearance-vs-classification
  check, and writes an audit line to stderr. Exit code encodes ALLOW(0)/DENY(1).
- **`cui-classifier`** — recursively scans a directory for regex indicators of
  Controlled Unclassified Information: ITAR/USML, EAR/ECCN, DoD markings
  (CUI/FOUO/NOFORN), PII/SSN/passport/DOB, HIPAA PHI, CVE/CVSS, ICS/SCADA, and
  payment-card PANs (with Luhn validation). Redacts sensitive matches, supports
  `--ext` filtering and `--json`, and returns exit code `2` when CUI is found.

## Monorepo placement & internal layout

This project lives at `cpp/` inside the larger portfolio repo. It is
self-contained — its own build files, docs, and deployment guides.

```
cpp/
├── aes-vault/aes_vault.cpp            arinc429/arinc429_decoder.cpp
├── cui-classifier/cui_classifier.cpp  entropy-scanner/entropy_scanner.cpp
├── gps-antispoofing/gps_detector.cpp  log-correlator/log_correlator.cpp
├── memory-scanner/memory_scanner.cpp  mil-std-1553/mil1553_sim.cpp
├── packet-analyzer/packet_analyzer.cpp rf-anomaly/rf_anomaly.cpp
├── yara-lite/yara_lite.cpp            zt-policy-engine/zt_policy.cpp
├── Makefile  CMakeLists.txt  Dockerfile  render.yaml
├── README.md  CLAUDE.md  OPEN_ITEMS.md
├── docs/{ARCHITECTURE,DEPLOYMENT,DISASTER_RECOVERY,SECURITY}.md
└── deployments/{LOCAL_DEVELOPMENT,SINGLE_LINUX_SERVER,KUBERNETES,AZURE,AWS,AIRGAPPED}.md
```

Note the binary name differs from the directory for two tools:
`gps-antispoofing/gps_detector.cpp` → `gps-detector`, and
`mil-std-1553/mil1553_sim.cpp` → `mil1553-sim`. The `Makefile` encodes the
authoritative source→binary mapping.

## Configuration model

There is **no config file and no environment configuration** for the tools
themselves. All behavior is set by command-line flags parsed inline in each
`main()`. Build-time configuration is via `make` variables (`CXX`, `CXXFLAGS`,
`OPT`, `OPENSSL_LIBS`, `PREFIX`) or CMake cache variables. There are no runtime
environment variables the tools read.

## Request & error contract (CLI contract)

Since there is no HTTP layer, the "request/response contract" is the CLI
contract:

- **Input:** positional path(s) and/or `--flag value` options; some tools also
  accept `-` or stdin. Number formats and structured inputs (pcap, NMEA, ARINC
  hex, syslog, `.policy`/request files, `.yrl` rules) are documented per tool in
  their source header and in `README.md`.
- **Output:** primary human-readable report on **stdout**; several tools offer
  `--json` (`entropy-scanner`, `packet-analyzer`, `cui-classifier`).
  Diagnostics, summaries, and the `zt-policy` audit line go to **stderr**.
- **Errors:** printed to stderr as `Error: <what>` or a `usage:` message.
- **Exit codes** (the machine-readable "status"):

| Code | Meaning (tools that use it) |
|------|-----------------------------|
| `0` | Success / clean — nothing suspicious found; policy ALLOW |
| `1` | Usage or I/O error (bad args, unreadable/unopenable input); policy DENY |
| `2` | A detection fired: `entropy-scanner` (packed/encrypted file), `packet-analyzer` (ATT&CK finding), `cui-classifier` (CUI found), `yara-lite` (rule match); or an unhandled exception in `aes-vault`/`memory-scanner`/`zt-policy` |

Demo/decoder tools (`mil1553-sim`, `arinc429-decoder`, `gps-detector`,
`rf-anomaly`) return `0` on success and `1` only on input errors.

## Security model

These are **defensive** tools that ingest **untrusted data** (packets, RF
captures, logs, arbitrary files, and live process memory). The security model
is therefore about the tools' own robustness, not about protecting a service:

- **No attack surface from the network** — nothing listens or dials out.
- **Least privilege** — only `memory-scanner` needs elevation (root/CAP_PTRACE);
  it degrades to a warning if it cannot attach.
- **Input is bounds-checked** at parse boundaries (pcap/TLS/DNS length fields,
  NMEA/ARINC field counts) — see `docs/SECURITY.md` and `OPEN_ITEMS.md` for the
  hardening backlog (fuzzing, sanitizers).
- **Data handling** — `cui-classifier` redacts sensitive matches and treats its
  own output as potentially CUI; `aes-vault` verifies the GCM tag before writing
  any plaintext.

Full detail in [`SECURITY.md`](SECURITY.md).

## Observability

No metrics/traces/health endpoints (nothing runs continuously). Observability is
the classic CLI trio:

- **stdout** — the report (optionally JSON for machine ingest / SIEM).
- **stderr** — progress, warnings, per-decision audit (`zt-policy`), scan
  summaries (`cui-classifier`, `yara-lite`).
- **exit code** — success/failure/detection, consumed by shells, `xargs`, CI,
  systemd, and k8s Job status.

When run as a container/Job, capture stdout/stderr with the platform's log
driver; the exit code becomes the Job's success/failure signal.

## Deployment topology

There is no server topology. The runtime unit is a process invoked ad hoc, from
a systemd timer, or as a Kubernetes/Batch **Job**. See
[`DEPLOYMENT.md`](DEPLOYMENT.md) and the `deployments/` guides for build →
install → integrate → run across local, single-server, Kubernetes, Azure, AWS,
and air-gapped targets.
