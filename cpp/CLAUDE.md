# CLAUDE.md — CPP Tool Collection

Project guidance for this app. This directory (`cpp/`) is treated as **one
project**: a portfolio of 12 standalone C++17 command-line utilities for
defense/aerospace security and signal processing.

## What this is

Twelve single-file C++ tools, each independent. No shared library, no build
framework beyond a plain `Makefile`/`CMakeLists.txt`, **no network I/O**, no
database, no web server, no auth. Tools read files/stdin and write reports to
stdout (diagnostics/audit/summary to stderr). See `README.md` and
`docs/ARCHITECTURE.md` for the full tool list and per-tool I/O contracts.

## Stack & conventions

- **C++17**, built with `g++`/`clang++`. GNU/Clang builtins are used
  (`__builtin_popcount`, `__builtin_bswap*`) — do not assume MSVC portability.
- **One `.cpp` per tool**, in its own subdirectory (`aes-vault/aes_vault.cpp`,
  etc.). Keep tools self-contained; do not introduce cross-tool linkage.
- **Dependencies:** only `aes-vault` links OpenSSL. `entropy-scanner` and
  `log-correlator` need `-pthread`. `memory-scanner` is Linux-only (`/proc` +
  ptrace). Everything else is standard-library-only. Preserve this — new
  dependencies are a regression here; the near-zero-dep property is the point.
- **CLI style:** flags are hand-parsed (`--flag value`); most tools print a
  usage message on bad/no args; `memory-scanner` and `log-correlator` accept an
  explicit `--help`. JSON output where present is emitted by
  `entropy-scanner`, `packet-analyzer`, and `cui-classifier`.
- **Exit codes carry meaning** for pipeline use (e.g. entropy-scanner /
  packet-analyzer / cui-classifier / yara-lite return `2` when they *find*
  something). Preserve these contracts.

## Where things live

```
cpp/
├── <tool>/<tool>.cpp     # 12 tool sources (one dir each)
├── Makefile              # canonical build → ./bin  (verify targets when adding a tool)
├── CMakeLists.txt        # optional CMake build
├── Dockerfile            # multi-stage build/runtime image, non-root
├── render.yaml           # Applicability: N/A (CLI tools) — header comment only
├── README.md OPEN_ITEMS.md CLAUDE.md
├── docs/                 # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
└── deployments/          # LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED
```

## Build / run / test

```bash
make -j"$(nproc)"        # build all into ./bin
make <tool>              # build one
make portable            # skip OpenSSL/Linux-only tools
make clean
```

There is no automated test suite. "Testing" a tool = it **compiles**, its
help/usage prints, and it **runs on a sample input and produces the expected
report** (many tools have a built-in demo/synthetic mode: `mil1553-sim`,
`arinc429-decoder --demo`, `gps-detector`, `rf-anomaly`). Use those to verify
changes. There is no health check, login, upload, or DB to verify.

## Security notes for contributors

These tools ingest **untrusted input** (packets, RF captures, logs, arbitrary
files, process memory). Follow `docs/SECURITY.md`:
- Validate lengths/bounds before indexing into parsed buffers (pcap, TLS/DNS,
  ARINC/NMEA fields). Never trust length fields from the input.
- Keep tools **offline** — do not add sockets, DNS lookups, or HTTP clients.
- `cui-classifier` output may itself contain CUI/PII — treat its stdout/JSON as
  sensitive; it already redacts SSN/PAN/passport/MRN and writes summaries to
  stderr. Do not add content to shared syslog.
- `aes-vault`: keep PBKDF2 iterations and AES-256-GCM parameters; the auth tag
  must be verified before output (it is).
- `memory-scanner` requires elevated privilege (ptrace/root) — document, do not
  silently escalate.

## Standing rule — keep the doc set current

This project ships the standard documentation & deployment set:
`deployments/` (×6), `docs/` (×4), `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`,
`Dockerfile`, `render.yaml`, plus the `Makefile`/`CMakeLists.txt`. Whenever a
tool is added, removed, or its I/O contract / dependency / build flags change,
update the affected `Makefile`, `CMakeLists.txt`, `Dockerfile`, `README.md`, the
tool table in `docs/ARCHITECTURE.md`, and `OPEN_ITEMS.md` in the same change.
Verify every claim against the real source — do not invent flags, deps, or
paths.
