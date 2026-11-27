#!/usr/bin/env bash
# PAL deployment smoke test. Run after every deploy against the public URL:
#
#   deploy/smoke-test.sh https://pal.example.internal
#
# Read-only checks (no account needed): liveness, readiness (database, migrations, schema,
# storage), Host-header protection, security headers and CSP on the SPA, stored files not
# reachable from the web root, API refuses anonymous calls.
#
# With a test account (never a real administrator) it also signs in and exercises the
# upload pipeline. Set, for example:
#   PAL_SMOKE_USERNAME=smoke.tester PAL_SMOKE_PASSWORD=... deploy/smoke-test.sh https://...
# Optional:
#   PAL_SMOKE_EICAR=1      upload the EICAR test string and expect it to be blocked (real ClamAV)
#   PAL_SMOKE_SKIP_SPA=1   skip SPA header checks (when testing the API without nginx)
#   PAL_SMOKE_SKIP_HOST=1  skip the Host-header check (some proxies rewrite Host)
#
# Exits non-zero if any check fails. Needs bash and curl only. Uploads leave unattached test
# files, which pal:storage:unattached reclaims after its retention window.
set -u

BASE="${1:-}"
if [ -z "$BASE" ]; then
  echo "usage: $0 <base-url>" >&2
  exit 2
fi
BASE="${BASE%/}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
JAR="$WORK/cookies"
FAILED=0
pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n      %s\n' "$1" "$2"; FAILED=1; }
skip() { printf 'SKIP  %s\n' "$1"; }

# status <method> <path> [curl args...] -> prints HTTP status, body in $WORK/body, headers in $WORK/headers
status() {
  local method="$1" path="$2"
  shift 2
  : > "$WORK/body"
  : > "$WORK/headers"
  # curl prints 000 itself when it cannot connect; do not add another.
  curl -sS -m 20 -o "$WORK/body" -D "$WORK/headers" -w '%{http_code}' -X "$method" -b "$JAR" -c "$JAR" "$@" "$BASE$path" 2>"$WORK/err" || true
}
header() { grep -i "^$1:" "$WORK/headers" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ //'; }
json_field() { sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" "$WORK/body" | head -1; }

# 1. Liveness (stop here if PAL cannot be reached at all: every later check would mislead)
code=$(status GET /health/live)
if [ "$code" = 000 ]; then
  fail "reach $BASE" "$(cat "$WORK/err")"
  exit 1
fi
[ "$code" = 200 ] && pass "liveness" || fail "liveness" "GET /health/live returned $code"

# 2. Readiness: every check ok (database, migrations, schema_version, storage, ...)
code=$(status GET /health/ready)
if [ "$code" = 200 ] && grep -q '"status":"ready"' "$WORK/body" && ! grep -q '"fail"' "$WORK/body"; then
  pass "readiness $(tr -d '\n' < "$WORK/body")"
else
  fail "readiness" "GET /health/ready returned $code: $(head -c 300 "$WORK/body")"
fi

# 3. Host header protection
if [ "${PAL_SMOKE_SKIP_HOST:-0}" = 1 ]; then
  skip "host header protection"
else
  code=$(status GET /health/live -H 'Host: evil.example')
  [ "$code" = 400 ] && pass "unknown Host header refused" || fail "unknown Host header refused" "expected 400, got $code (check TRUSTED_HOSTS)"
fi

# 4. SPA security headers and CSP
if [ "${PAL_SMOKE_SKIP_SPA:-0}" = 1 ]; then
  skip "SPA security headers"
else
  code=$(status GET /)
  csp="$(header Content-Security-Policy)"
  if [ "$code" = 200 ] && echo "$csp" | grep -q "script-src 'self'" && echo "$csp" | grep -q "style-src 'self'" && ! echo "$csp" | grep -q "unsafe-inline"; then
    pass "strict CSP on the SPA"
  else
    fail "strict CSP on the SPA" "status $code, CSP: ${csp:-<none>}"
  fi
  [ "$(header X-Frame-Options)" = DENY ] && pass "X-Frame-Options DENY" || fail "X-Frame-Options DENY" "got '$(header X-Frame-Options)'"
  [ "$(header X-Content-Type-Options)" = nosniff ] && pass "X-Content-Type-Options nosniff" || fail "X-Content-Type-Options nosniff" "got '$(header X-Content-Type-Options)'"
  case "$BASE" in
    https://*) [ -n "$(header Strict-Transport-Security)" ] && pass "HSTS" || fail "HSTS" "no Strict-Transport-Security header over HTTPS" ;;
    *) skip "HSTS (not HTTPS)" ;;
  esac
fi

# 5. Stored files are never served from the web root (they must only come through the API).
leak=0
for path in /var/blobs/sha256/ /blobs/sha256/ /var/test-blobs/sha256/; do
  code=$(status GET "$path")
  type="$(header Content-Type)"
  if [ "$code" = 000 ] || { [ "$code" = 200 ] && ! echo "$type" | grep -qi 'text/html'; }; then leak=1; fi
done
[ "$leak" = 0 ] && pass "no stored files under the web root" || fail "no stored files under the web root" "a blob path answered 200 with non-HTML content"

# 6. The API refuses anonymous calls
code=$(status GET /api/me)
[ "$code" = 401 ] && pass "anonymous API call refused" || fail "anonymous API call refused" "GET /api/me returned $code"

# 7. Signed-in checks (optional)
if [ -z "${PAL_SMOKE_USERNAME:-}" ] || [ -z "${PAL_SMOKE_PASSWORD:-}" ]; then
  skip "signed-in checks (set PAL_SMOKE_USERNAME and PAL_SMOKE_PASSWORD)"
else
  case "$BASE" in
    http://localhost*|http://127.0.0.1*|https://*) ;;
    *) echo "WARNING: sending the test account's password over plain HTTP to $BASE" >&2 ;;
  esac
  status GET /auth/methods >/dev/null
  login_csrf="$(json_field csrfToken)"
  # JSON-escape backslashes and quotes (printf is a shell builtin: nothing appears in ps).
  esc() { local v="${1//\\/\\\\}"; printf '%s' "${v//\"/\\\"}"; }
  printf '{"username":"%s","password":"%s"}' "$(esc "$PAL_SMOKE_USERNAME")" "$(esc "$PAL_SMOKE_PASSWORD")" > "$WORK/login.json"
  code=$(status POST /auth/password -H 'Content-Type: application/json' -H "X-CSRF-Token: $login_csrf" --data-binary "@$WORK/login.json")
  rm -f "$WORK/login.json"
  if [ "$code" != 200 ]; then
    fail "sign in" "POST /auth/password returned $code: $(head -c 200 "$WORK/body")"
  elif grep -q '"mustChangePassword":true' "$WORK/body"; then
    fail "sign in" "the smoke account still has a temporary password; sign in once and change it"
  else
    pass "sign in"
    code=$(status GET /api/me)
    csrf="$(json_field csrfToken)"
    [ "$code" = 200 ] && [ -n "$csrf" ] && pass "session works (GET /api/me)" || fail "session works" "GET /api/me returned $code"

    printf 'PAL smoke test %s\n' "$(date -u +%FT%TZ)" > "$WORK/smoke.txt"
    code=$(status POST /api/uploads -H "X-CSRF-Token: $csrf" -F "file=@$WORK/smoke.txt;type=text/plain")
    [ "$code" = 201 ] && pass "file upload scanned clean and stored" || fail "file upload" "returned $code: $(head -c 200 "$WORK/body") (503 = scanner unavailable)"

    # 1x1 PNG: proves GD is installed (images are re-encoded without metadata).
    printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' | base64 -d > "$WORK/pixel.png" 2>/dev/null || printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' | base64 -D > "$WORK/pixel.png"
    code=$(status POST /api/uploads -H "X-CSRF-Token: $csrf" -F purpose=image -F "file=@$WORK/pixel.png;type=image/png")
    [ "$code" = 201 ] && pass "image upload re-encoded and stored" || fail "image upload" "returned $code: $(head -c 200 "$WORK/body") (503 image_processing_unavailable = PHP gd missing)"

    if [ "${PAL_SMOKE_EICAR:-0}" = 1 ]; then
      printf '%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > "$WORK/eicar.txt"
      code=$(status POST /api/uploads -H "X-CSRF-Token: $csrf" -F "file=@$WORK/eicar.txt;type=text/plain")
      grep -q malware_detected "$WORK/body" && pass "malware blocked (EICAR)" || fail "malware blocked (EICAR)" "returned $code: $(head -c 200 "$WORK/body")"
    else
      skip "malware check (set PAL_SMOKE_EICAR=1 with real ClamAV)"
    fi
    status POST /api/session/logout -H "X-CSRF-Token: $csrf" >/dev/null
  fi
fi

if [ "$FAILED" = 0 ]; then
  echo "All checks passed."
else
  echo "Some checks FAILED." >&2
fi
exit "$FAILED"
