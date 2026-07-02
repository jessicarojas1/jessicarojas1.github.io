# Single Linux Server — CPP Tool Collection

**Applicability:** Applicable, reframed. There is no long-running service to
host. This guide covers **building and installing the binaries on one VM** and
running them either interactively or as **systemd oneshot services + timers**
for scheduled batch sweeps (e.g. nightly CUI or entropy scans). No web server,
TLS termination, login, or database is involved — those sections are adapted.

## 1. Deployment architecture

One Linux VM has the toolchain, compiles the 12 tools once, and installs them to
`/usr/local/bin`. Analysts run tools by hand over SSH, or systemd timers invoke
them on a schedule against a data directory, writing reports to a controlled
output path. Nothing listens on a socket.

## 2. Topology

```
┌──────────────────────────── Linux VM ────────────────────────────────┐
│  /opt/cpp (git checkout) ──make install──▶ /usr/local/bin/<12 tools>   │
│                                                                        │
│  systemd timer ─▶ oneshot unit ─▶ cui-classifier /data --json          │
│                                     │                                   │
│  /data (inputs) ───────────────────┘─▶ /var/log/cpp/*.json (reports)   │
│                                                                        │
│  admin ──SSH──▶ interactive tool runs                                   │
└────────────────────────────────────────────────────────────────────────┘
No inbound ports for the app. SSH is the only management plane.
```

## 3. Prerequisites

- A Linux VM (Debian 12 / Ubuntu 22.04+ / RHEL 9 UBI equivalent).
- `g++`, `make`, `libssl-dev` (`gcc-c++ make openssl-devel` on RHEL).
- systemd (for scheduled runs). SSH for administration.
- A data directory to scan and an output directory for reports.

## 4. Identity & credentials

- **No app credentials.** The only secret is the interactive `aes-vault`
  passphrase.
- Run scheduled sweeps as a **dedicated least-privilege service account**
  (e.g. `cppsvc`), not root. Grant it read on `/data` and write on the report
  dir only.
- `memory-scanner` is the exception: reading another process's memory needs
  root or `CAP_PTRACE`. If you schedule it, grant the capability narrowly
  (`AmbientCapabilities=CAP_SYS_PTRACE` on that one unit) rather than running the
  whole fleet as root.

## 5. Environment variables

The tools read no runtime env vars. systemd units may set build/install paths;
none are required by the binaries.

| Variable | Example | Purpose |
|----------|---------|---------|
| `PREFIX` (build) | `/usr/local` | install destination for `make install` |
| `CXXFLAGS` (build) | `-std=c++17 -O2` | build flags |
| `DATA_DIR` (your unit) | `/data` | convenience var referenced by your timer script |

## 6. Configuration references

No config files. Behavior is set by the flags in the systemd `ExecStart` line
(see the unit below). Keep the flags under version control alongside the unit.

## 7. Verification

No health/login/DB/upload. Verify build + install + a scheduled run:

```bash
# Build & install
cd /opt/cpp && make -j"$(nproc)" && sudo make install PREFIX=/usr/local
command -v cui-classifier entropy-scanner aes-vault    # all resolve in PATH

# Sample runs
mil1553-sim | grep -q "Bus Monitor Transcript" && echo OK
echo "test plaintext" > /tmp/s.txt
entropy-scanner /tmp/s.txt --verbose | grep -q PLAINTEXT && echo OK

# Trigger the scheduled sweep once and check the report + exit status
sudo systemctl start cpp-cui-sweep.service
systemctl show -p ExecMainStatus cpp-cui-sweep.service   # 0 clean, 2 = CUI found
ls -l /var/log/cpp/                                       # report written
```

## 8. Day-2 operations

**Install/update:**

```bash
cd /opt/cpp && git pull && make -j && sudo make install PREFIX=/usr/local
```

**Scheduled sweep — systemd oneshot + timer** (`/etc/systemd/system/`):

`cpp-cui-sweep.service`:
```ini
[Unit]
Description=CUI classification sweep of /data
After=local-fs.target

[Service]
Type=oneshot
User=cppsvc
Group=cppsvc
# exit 2 (CUI found) must not be treated as failure by systemd:
SuccessExitStatus=0 2
ExecStart=/usr/local/bin/cui-classifier /data --json
StandardOutput=append:/var/log/cpp/cui-sweep.json
StandardError=append:/var/log/cpp/cui-sweep.err
# Hardening (read-only except the log dir):
ProtectSystem=strict
ReadWritePaths=/var/log/cpp
PrivateTmp=true
NoNewPrivileges=true
```

`cpp-cui-sweep.timer`:
```ini
[Unit]
Description=Run CUI sweep nightly
[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true
[Install]
WantedBy=timers.target
```

```bash
sudo mkdir -p /var/log/cpp && sudo chown cppsvc:cppsvc /var/log/cpp
sudo systemctl daemon-reload
sudo systemctl enable --now cpp-cui-sweep.timer
```

Use the same pattern for `entropy-scanner /opt --min 7.2 --json` or
`log-correlator --window 300 /var/log/auth.log`. For `memory-scanner`, add
`AmbientCapabilities=CAP_SYS_PTRACE` and pass `--pid`/`--ptrace`.

- **Scaling:** these are batch jobs — parallelize with `--parallel`/`--threads`
  flags (entropy-scanner/log-correlator) and CPU count; stagger timers.
- **Backups:** the binaries are rebuildable (git). Back up only your unit files
  and any retained reports. See `docs/DISASTER_RECOVERY.md`.
- **Rotation:** the only "secret" is the `aes-vault` passphrase; rotate by
  decrypt-then-re-encrypt. Rotate report logs with `logrotate`.
- **Cert rotation:** N/A (no TLS/service). Keep OpenSSL patched for `aes-vault`.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Timer unit marked `failed` after a clean CUI find | tool exits `2` on detection | add `SuccessExitStatus=0 2` to the unit |
| `Permission denied` reading `/data` | service account lacks read | grant `cppsvc` read on `/data` |
| `memory-scanner`: `root or CAP_PTRACE required` | no ptrace capability | `AmbientCapabilities=CAP_SYS_PTRACE` on that unit |
| `aes-vault` hangs in a unit | it waits for an interactive passphrase | don't schedule interactive `aes-vault`; run it by hand or pipe a secret on stdin via a PTY |
| Report file empty | tool wrote to stderr / exited early | check `StandardError` log and exit status |
| `libssl.so.3: cannot open` after OS upgrade | OpenSSL ABI changed | rebuild: `make aes-vault && make install` |
