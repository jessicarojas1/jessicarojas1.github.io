# OPEN_ITEMS.md — Production-Readiness Register

Honest status of the CPP tool collection. "Done" means verified against the
actual sources in this directory; "Outstanding" means a real gap or limitation.
These are CLI tools, so several webapp-style concerns (auth, TLS, DB, uploads)
are **Not applicable** and are marked as such rather than faked.

Legend: ✅ done · 🟡 partial · ⬜ outstanding · N/A not applicable

---

## Build & toolchain

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Top-level `Makefile` builds all 12 tools | ✅ | Reproducible one-command build | — |
| `CMakeLists.txt` alternative build | ✅ | IDE / cross-platform builds | — |
| All 12 compile clean on g++ 13.3.0 | ✅ | Verified locally | — |
| Compiler warnings (`-Wall -Wextra`) resolved | 🟡 | The two called-out warnings are fixed — removed `yara-lite` `use_builtin` (dead) and `cui-classifier` `using S` (unused typedef). A handful of unused-parameter/function warnings remain (`gps_detector` `nmea_checksum`/`verify_checksum`/`speed_kt`/`verbose`, `rf_anomaly` `center_freq`, `packet_analyzer` `swap16`/`hex_str`, `mil1553` `RT_BROADCAST`) | Trim remaining dead code; then flip to `-Werror` |
| CI pipeline (build matrix, run demos) | ✅ | `.github/workflows/cpp-ci.yml`: Linux **GCC+Clang** matrix builds all 12 + runs `tests/run_tests.sh`; **static** job builds air-gap binaries; **macOS** job builds portable subset. Scoped to `cpp/**`. Verified: g++ and clang++ both build all 12 and pass all 19 tests locally | — |
| Reproducible / pinned toolchain | ✅ | Build pinned to C++17 + `-O2 -Wall -Wextra` in `Makefile`; CI installs the toolchain explicitly and runs `make version` (records `CXX --version` + `CXXFLAGS`) as build metadata; Dockerfile still pins `debian:12-slim` | Optionally pin an exact compiler patch version per release |
| Static-linked release artifacts | ✅ | `make static` links the 10 portable+threaded tools with `-static` → `./bin/*-static` (aes-vault/memory-scanner excluded by design); CI `static` job gates it; documented in `deployments/AIRGAPPED.md` | Optional: musl builds for smaller/more-portable artifacts |

## Testing & verification

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Built-in demo/synthetic modes | ✅ | `mil1553-sim`, `arinc429-decoder --demo`, `gps-detector`, `rf-anomaly` self-verify without inputs | — |
| Test harness (smoke/contract) | ✅ | `tests/run_tests.sh` builds all 12 and runs **19 checks**: every demo, the exit-2 detection contracts (entropy/cui/yara + clean counter-cases), `packet-analyzer` on a generated pcap, `zt-policy` ALLOW, `log-correlator`, a full `aes-vault` encrypt→decrypt round-trip, and `memory-scanner --help` (auto-skipped off Linux). `make test`. All pass on g++ 13.3.0 and clang 18 | — |
| Unit tests (deep per-parser) | 🟡 | The harness gives contract-level regression coverage, but there are no fine-grained unit/property tests of individual pcap/TLS/DNS/NMEA/ARINC parse paths | Add table-driven parser unit tests with malformed/edge inputs |
| Fuzzing of untrusted parsers | ⬜ | `packet-analyzer`, `yara-lite`, `gps-detector`, `arinc429-decoder`, `log-correlator` parse attacker-influenced bytes | libFuzzer/AFL++ harnesses; run under ASan/UBSan |
| Sanitizer build target | ⬜ | Memory-safety bugs would surface earlier | Add `make asan` / CMake `-fsanitize=address,undefined` preset |
| Golden-output samples | ✅ | `tests/run_tests.sh` asserts stable output substrings **and** exit codes for every tool, and CI runs it on GCC+Clang — outputs are now asserted, not just printed | Extend to full byte-diff golden files if output stabilizes further |

## Memory safety & robustness (these tools parse untrusted input)

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Bounds checks in pcap/TLS/DNS parsing | 🟡 | `packet-analyzer` checks most lengths, but hand-rolled offset math is a classic OOB source | Fuzz + ASan; consider `std::span` bounds wrappers |
| `std::stoi/stod/stoul` on untrusted fields | 🟡 | `gps-detector`, `arinc429-decoder`, `memory-scanner` parse numbers that can throw/overflow | Wrap in try/catch or `from_chars`; validate ranges |
| Large-input handling | 🟡 | `packet-analyzer`/`cui-classifier`/`yara-lite` load files/regions into memory | Document memory expectations; stream where feasible; cap sizes |
| `memory-scanner` privilege model | ✅ (documented) | Needs root/CAP_PTRACE; degrades with a warning | Keep least-privilege guidance in `docs/SECURITY.md` |

## Security tooling

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| `aes-vault` uses AES-256-GCM + PBKDF2-SHA256 (100k) + random salt/IV, verifies tag | ✅ | Sound authenticated encryption | Consider Argon2id KDF and a configurable iteration count |
| No hardcoded secrets | ✅ | Passphrases read from tty (no echo); nothing committed | — |
| CUI/PII redaction in `cui-classifier` | ✅ | SSN/PAN/passport/MRN/DOB redacted; summary to stderr | — |
| SBOM / dependency provenance | ⬜ | Only OpenSSL is external, but no SBOM emitted | Generate CycloneDX SBOM in build; pin/verify OpenSSL |
| Binary signing | ⬜ | Released binaries unsigned | Sign release bundles (see `deployments/AIRGAPPED.md`, `docs/SECURITY.md`) |

## Documentation & deployment set

| Item | Status |
|------|--------|
| `README.md`, `CLAUDE.md`, `OPEN_ITEMS.md` | ✅ |
| `docs/` ×4 (ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY) | ✅ |
| `deployments/` ×6 (LOCAL, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED) | ✅ |
| `Dockerfile` (multi-stage, non-root) | ✅ |
| `render.yaml` (Applicability: N/A header) | ✅ |
| `Makefile` + `CMakeLists.txt` | ✅ |

## Not applicable (CLI tools, not a service)

| Concern | Why N/A |
|---------|---------|
| HTTP health endpoint / login / RBAC UI | No server, no web layer, no accounts |
| Database schema / migrations | No database |
| File-upload MIME/extension validation | No upload surface; tools read local paths/stdin |
| TLS / open redirects / CSRF / CSP | No network or web output |
| Ollama / GPU acceleration | No AI/LLM feature in any tool |
| Worker/queue/cron *inside* the app | Batch scheduling is external (systemd timer / k8s CronJob / Render cron) |
