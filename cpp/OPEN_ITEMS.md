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
| Compiler warnings (`-Wall -Wextra`) resolved | 🟡 | A few unused-variable/`clang-format` warnings remain (e.g. `yara-lite` `use_builtin`, `cui-classifier` `using S`) | Trim dead code; consider `-Werror` once clean |
| CI pipeline (build matrix, run demos) | ⬜ | No automated proof of build health on push | Add GitHub Actions: `make -j` + run each demo; matrix gcc/clang, Linux/macOS |
| Reproducible / pinned toolchain | 🟡 | Dockerfile pins `debian:12-slim`; host builds float | Pin compiler version in CI; record `g++ --version` in build metadata |
| Static-linked release artifacts | ⬜ | Air-gap installs currently rely on target libs | Provide `-static` / musl builds for portable tools (see `deployments/AIRGAPPED.md`) |

## Testing & verification

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Built-in demo/synthetic modes | ✅ | `mil1553-sim`, `arinc429-decoder --demo`, `gps-detector`, `rf-anomaly` self-verify without inputs | — |
| Unit tests | ⬜ | No regression safety net for parsers/detectors | Add tests for pcap/TLS/DNS/NMEA/ARINC parsers and detection thresholds |
| Fuzzing of untrusted parsers | ⬜ | `packet-analyzer`, `yara-lite`, `gps-detector`, `arinc429-decoder`, `log-correlator` parse attacker-influenced bytes | libFuzzer/AFL++ harnesses; run under ASan/UBSan |
| Sanitizer build target | ⬜ | Memory-safety bugs would surface earlier | Add `make asan` / CMake `-fsanitize=address,undefined` preset |
| Golden-output samples | 🟡 | Demos print, but outputs aren't asserted | Commit expected demo outputs; diff in CI |

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
