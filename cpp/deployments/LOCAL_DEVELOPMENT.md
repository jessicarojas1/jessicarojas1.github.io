# Local Development — CPP Tool Collection

**Applicability:** Fully applicable. This is the primary way to work with the
tools: install a C++17 toolchain, compile, and run each tool on a sample. There
is no server, health endpoint, login, database, or upload — verification is
"compiles, help/usage prints, runs on a sample and produces the expected
report."

## 1. Deployment architecture

A developer laptop/workstation with a C++17 compiler builds 12 independent
single-file tools into `cpp/bin`. Each binary is run directly from the shell.
Only `aes-vault` links OpenSSL; `entropy-scanner`/`log-correlator` use pthreads;
`memory-scanner` needs Linux `/proc`.

## 2. Topology

```
┌─────────────────────────── developer host ───────────────────────────┐
│  cpp/<tool>/<tool>.cpp  ──(g++/clang++ -std=c++17)──▶  cpp/bin/<tool> │
│                                                                        │
│   stdin / files ─▶ [ tool process ] ─▶ stdout (report) + stderr        │
│                                        exit code (0/1/2)                │
│   aes-vault ──▶ libssl/libcrypto (OpenSSL, dynamic)                     │
│   memory-scanner ──▶ /proc/<pid>/{maps,mem}  (Linux only)              │
└────────────────────────────────────────────────────────────────────────┘
No network. No daemon. No ports.
```

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| g++ or clang++ | C++17 (g++ ≥ 9 / clang ≥ 10) | verified g++ 13.3.0 |
| GNU make | any | canonical build |
| libssl-dev (OpenSSL) | ≥ 1.1.0 (tested 3.0.13) | `aes-vault` only |
| cmake | ≥ 3.16 | optional |
| Linux kernel `/proc` | any | to run `memory-scanner` |

Install (Debian/Ubuntu): `sudo apt-get install g++ make libssl-dev`
macOS: `xcode-select --install` then `brew install openssl@3` (build
`aes-vault` with `OPENSSL_LIBS`/`-I`/`-L` to the brew prefix; `memory-scanner`
won't build — it's Linux-only).

## 4. Identity & credentials

None for building or running. The only credential in the whole project is the
interactive `aes-vault` passphrase (typed at a no-echo prompt; never a flag or
env var). No cloud identity is involved in local dev.

## 5. Environment variables

The tools read **no environment variables** at runtime. Build-time knobs are
`make` variables, not env-config:

| Variable | Example | Purpose |
|----------|---------|---------|
| `CXX` | `clang++` | Compiler |
| `CXXFLAGS` | `-std=c++17 -O2 -g` | Compile flags (add `-g` for debugging) |
| `OPT` | `-O0` | Optimization level override |
| `OPENSSL_LIBS` | `-L/opt/homebrew/opt/openssl@3/lib -lssl -lcrypto` | OpenSSL link path for `aes-vault` |
| `PREFIX` | `$HOME/.local` | `make install` destination |

## 6. Configuration references

No runtime config files. Per-tool behavior is entirely CLI flags — see
`README.md` and `docs/ARCHITECTURE.md` for the full flag reference.

## 7. Verification

There is **no health/login/DB/upload** to verify — say so and verify the real
behaviors instead:

```bash
cd cpp
make -j"$(nproc)"                 # (a) compiles
ls bin | wc -l                    #     expect 12

# (b) help / usage
bin/memory-scanner --help
bin/log-correlator --help
bin/aes-vault                     # usage on no args
bin/yara-lite                     # usage on <2 args

# (c) runs on a sample → expected report
bin/mil1553-sim | grep -q "Bus Monitor Transcript" && echo "mil1553 OK"
bin/arinc429-decoder --demo | grep -q "Decoded value" && echo "arinc OK"
bin/gps-detector | grep -q "SPOOFED" && echo "gps OK"     # demo injects spoof
bin/rf-anomaly | grep -q "RF Anomaly Report" && echo "rf OK"

echo "hello plaintext content" > /tmp/s.txt
bin/entropy-scanner /tmp/s.txt --verbose | grep -q PLAINTEXT && echo "entropy OK"
bin/yara-lite --builtin bin/mil1553-sim; echo "yara exit=$? (2=ELF match)"

# aes-vault round-trip
printf secret123'\n'secret123'\n' | true   # (passphrase is interactive; see note)
```

> `aes-vault` reads its passphrase from the terminal with echo disabled, so a
> non-interactive round-trip needs a PTY (e.g. `expect`) — interactively:
> `bin/aes-vault encrypt --label "CUI//SP-CTI" /tmp/s.txt /tmp/s.vault` then
> `bin/aes-vault decrypt /tmp/s.vault /tmp/s.out` and `diff /tmp/s.txt /tmp/s.out`.

## 8. Day-2 operations

- **Rebuild after edits:** `make <tool>` (incremental per target) or `make -j`.
- **Debug build:** `make CXXFLAGS="-std=c++17 -O0 -g" <tool>` then `gdb bin/<tool>`.
- **Sanitizers (recommended for parser work):**
  `make CXXFLAGS="-std=c++17 -O1 -g -fsanitize=address,undefined" packet-analyzer`.
- **Clean:** `make clean`.
- **Install to your user prefix:** `make install PREFIX=$HOME/.local` and add
  `$HOME/.local/bin` to `PATH`.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `fatal error: openssl/evp.h: No such file` | OpenSSL dev headers missing | `apt-get install libssl-dev` (or brew, with `-I`/`-L`) |
| `undefined reference to SSL_*` | OpenSSL not linked | build via `make aes-vault` (adds `-lssl -lcrypto`) |
| `undefined reference to pthread_*` | missing `-pthread` | build `entropy-scanner`/`log-correlator` via `make` (adds `-pthread`) |
| `static assertion failed: requires Linux` | building `memory-scanner` off-Linux | expected; use `make portable` / build on Linux |
| `arpa/inet.h not found` (Windows) | `packet-analyzer`/`cui-classifier` need POSIX | build on Linux/macOS or WSL |
| `memory-scanner`: cannot open `/proc/<pid>/mem` | insufficient privilege | run with `sudo`/`CAP_PTRACE`; add `--ptrace` |
| No colored output when piping | color auto-disables on non-TTY (by design) | use `--json` for machine output |
