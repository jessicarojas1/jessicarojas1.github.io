#!/usr/bin/env bash
# ============================================================================
#  run_tests.sh — smoke / contract test harness for the CPP tool collection
#  ---------------------------------------------------------------------------
#  Builds every tool (via the top-level Makefile) and runs each one against a
#  generated sample input, asserting the documented exit code AND a stable
#  output substring. This is a real, dependency-free harness: it needs only
#  bash, coreutils, and the same toolchain the Makefile uses (g++ + libssl-dev).
#
#  Exit-code contracts under test (see README / CLAUDE.md):
#    * entropy-scanner  → 2 when a PACKED/ENCRYPTED file is found
#    * packet-analyzer  → 2 when a MITRE ATT&CK finding is raised, else 0
#    * cui-classifier   → 2 when CUI/PII is detected
#    * yara-lite        → 2 when a rule matches
#    * zt-policy        → 0 ALLOW / 1 DENY / 2 error
#    * memory-scanner   → Linux-only; skipped on non-Linux hosts
#
#  Usage:
#    tests/run_tests.sh            # build (make all) then run every test
#    SKIP_BUILD=1 tests/run_tests.sh   # use already-built ./bin
#
#  Returns 0 if all tests pass, 1 otherwise.
# ============================================================================
set -u

# ---- Locate repo root (this script lives in cpp/tests) ---------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BIN="$ROOT/bin"
cd "$ROOT" || exit 1

PASS=0
FAIL=0
SKIP=0
FAILED_NAMES=()

RED=$'\033[31m'; GRN=$'\033[32m'; YLW=$'\033[33m'; RST=$'\033[0m'
if [ ! -t 1 ]; then RED=""; GRN=""; YLW=""; RST=""; fi

# ---- Build -----------------------------------------------------------------
if [ "${SKIP_BUILD:-0}" != "1" ]; then
    echo "== Building all tools (make all) =="
    if ! make -j"$(nproc 2>/dev/null || echo 2)" all; then
        echo "${RED}BUILD FAILED${RST}"
        exit 1
    fi
    echo
fi

echo "== Toolchain =="
"${CXX:-g++}" --version | head -1
echo

# ---- Fixtures --------------------------------------------------------------
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# High-entropy (should flag PACKED/ENCRYPTED) and plain-text (should not)
head -c 8192 /dev/urandom > "$WORK/random.bin"
{ for i in $(seq 1 80); do echo "the quick brown fox jumps over the lazy dog $i"; done; } > "$WORK/plain.txt"

# CUI/PII sample + clean sample (separate dirs so exit codes are unambiguous)
mkdir -p "$WORK/pii" "$WORK/clean"
printf 'Employee record\nSSN: 123-45-6789\nDOB: 01/02/1980\n' > "$WORK/pii/hr.txt"
printf 'Release notes: nothing sensitive in here at all.\n' > "$WORK/clean/notes.txt"

# Zero-Trust request that matches a built-in ALLOW policy (P6: analyst / UNCLASSIFIED / trusted / read)
cat > "$WORK/allow.req" <<'EOF'
subject.role: analyst
resource.classification: UNCLASSIFIED
env.network_zone: trusted
action: read
EOF

# Minimal but valid libpcap file: 24-byte global header, zero packets
#   magic a1b2c3d4 (LE on-disk d4 c3 b2 a1), ver 2.4, snaplen 65535, DLT_EN10MB(1)
printf '\xd4\xc3\xb2\xa1\x02\x00\x04\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00\x01\x00\x00\x00' > "$WORK/empty.pcap"

# Log sample for the correlator (format-agnostic; correlator always exits 0)
printf 'Jan  1 00:00:01 host sshd[1]: Failed password for root from 10.0.0.1 port 22 ssh2\n' > "$WORK/auth.log"

# aes-vault round-trip plaintext
printf 'CLASSIFIED PAYLOAD — do not disclose\n' > "$WORK/secret.txt"

# ---- Test helpers ----------------------------------------------------------
# run_case <name> <expected_exit> <expect_substr|-> <cmd...>
run_case() {
    local name="$1" want_exit="$2" want_sub="$3"; shift 3
    local out rc
    out="$("$@" 2>&1)"; rc=$?
    if [ "$rc" != "$want_exit" ]; then
        echo "${RED}FAIL${RST} $name — exit $rc, expected $want_exit"
        FAIL=$((FAIL+1)); FAILED_NAMES+=("$name"); return
    fi
    if [ "$want_sub" != "-" ] && ! printf '%s' "$out" | grep -qF "$want_sub"; then
        echo "${RED}FAIL${RST} $name — output missing '$want_sub'"
        FAIL=$((FAIL+1)); FAILED_NAMES+=("$name"); return
    fi
    echo "${GRN}PASS${RST} $name (exit $rc)"
    PASS=$((PASS+1))
}

# For tools that read a passphrase from stdin (aes-vault)
run_case_stdin() {
    local name="$1" want_exit="$2" want_sub="$3" input="$4"; shift 4
    local out rc
    out="$(printf '%s' "$input" | "$@" 2>&1)"; rc=$?
    if [ "$rc" != "$want_exit" ]; then
        echo "${RED}FAIL${RST} $name — exit $rc, expected $want_exit"
        FAIL=$((FAIL+1)); FAILED_NAMES+=("$name"); return
    fi
    if [ "$want_sub" != "-" ] && ! printf '%s' "$out" | grep -qF "$want_sub"; then
        echo "${RED}FAIL${RST} $name — output missing '$want_sub'"
        FAIL=$((FAIL+1)); FAILED_NAMES+=("$name"); return
    fi
    echo "${GRN}PASS${RST} $name (exit $rc)"
    PASS=$((PASS+1))
}

echo "== Running tests =="

# --- Demo / synthetic self-verifying tools (exit 0) ---
run_case "mil1553-sim demo"        0 "MIL-STD-1553"          "$BIN/mil1553-sim"
run_case "arinc429-decoder demo"   0 "ARINC 429 Decoder"     "$BIN/arinc429-decoder" --demo
run_case "rf-anomaly demo"         0 "RF Anomaly Detector"   "$BIN/rf-anomaly"
run_case "gps-detector demo"       0 "GPS Anti-Spoofing"     "$BIN/gps-detector"
run_case "gps-detector --gen"      0 "GPGSV"                 "$BIN/gps-detector" --gen

# --- Detection tools: exit 2 on positive, 0 on clean ---
run_case "entropy-scanner packed"  2 "-"  "$BIN/entropy-scanner" "$WORK/random.bin"
run_case "entropy-scanner clean"   0 "-"  "$BIN/entropy-scanner" "$WORK/plain.txt"
run_case "cui-classifier PII"      2 "-"  "$BIN/cui-classifier" "$WORK/pii"
run_case "cui-classifier clean"    0 "-"  "$BIN/cui-classifier" "$WORK/clean"
run_case "yara-lite match (ELF)"   2 "MATCH"     "$BIN/yara-lite" --builtin "$BIN/mil1553-sim"
run_case "yara-lite no-match"      0 "No matches" "$BIN/yara-lite" --builtin "$WORK/plain.txt"

# --- packet-analyzer: valid pcap, clean capture -> exit 0 ---
run_case "packet-analyzer clean pcap" 0 "-" "$BIN/packet-analyzer" "$WORK/empty.pcap"

# --- zt-policy: ALLOW request -> exit 0 ---
run_case "zt-policy allow" 0 "ALLOW" "$BIN/zt-policy" --builtin --request "$WORK/allow.req"

# --- log-correlator: parses files, exits 0 ---
run_case "log-correlator run" 0 "Total events" "$BIN/log-correlator" "$WORK/auth.log"

# --- aes-vault: encrypt then decrypt round-trip, byte-identical output ---
run_case_stdin "aes-vault encrypt" 0 "Encrypted" $'passw0rd!\npassw0rd!\n' \
    "$BIN/aes-vault" encrypt "$WORK/secret.txt" "$WORK/secret.vault"
run_case_stdin "aes-vault decrypt" 0 "Decrypted" $'passw0rd!\n' \
    "$BIN/aes-vault" decrypt "$WORK/secret.vault" "$WORK/secret.out"
if cmp -s "$WORK/secret.txt" "$WORK/secret.out"; then
    echo "${GRN}PASS${RST} aes-vault round-trip (plaintext restored)"; PASS=$((PASS+1))
else
    echo "${RED}FAIL${RST} aes-vault round-trip — decrypted output differs"
    FAIL=$((FAIL+1)); FAILED_NAMES+=("aes-vault round-trip")
fi

# --- memory-scanner: Linux-only; --help must work where built ---
if [ "$(uname -s)" = "Linux" ] && [ -x "$BIN/memory-scanner" ]; then
    run_case "memory-scanner --help" 0 "-" "$BIN/memory-scanner" --help
    run_case "memory-scanner no-pid" 1 "-" "$BIN/memory-scanner"
else
    echo "${YLW}SKIP${RST} memory-scanner (Linux-only; not built on this host)"
    SKIP=$((SKIP+1))
fi

# ---- Summary ---------------------------------------------------------------
echo
echo "== Summary =="
echo "  ${GRN}pass=$PASS${RST}  ${RED}fail=$FAIL${RST}  ${YLW}skip=$SKIP${RST}"
if [ "$FAIL" -ne 0 ]; then
    printf '  failed: %s\n' "${FAILED_NAMES[*]}"
    exit 1
fi
echo "  ${GRN}ALL TESTS PASSED${RST}"
exit 0
