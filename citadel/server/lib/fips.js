'use strict';
/* CITADEL — FIPS 140 operating mode.
 *
 * When CITADEL_FIPS=1, request the OpenSSL FIPS provider at boot and steer the
 * app's own crypto choices to FIPS-approved algorithms. This only has teeth on a
 * Node runtime linked against a FIPS-validated OpenSSL (e.g. a FIPS build / RHEL
 * system OpenSSL in FIPS mode); on a stock build crypto.setFips(true) throws and
 * we report active:false rather than crash — so the flag is safe to set anywhere
 * and `status()` tells the operator the truth.
 *
 * Algorithm posture (what we actually use, and whether it is FIPS-approved):
 *   - Passwords:  scrypt is NOT FIPS-approved → in FIPS mode we hash with
 *                 PBKDF2-HMAC-SHA256 (SP 800-132). Hashes are self-describing
 *                 (see users.js) so both forms verify and the KDF can switch
 *                 without a schema change.
 *   - JWT:        HMAC-SHA256 (approved).
 *   - At-rest:    AES-256-GCM (approved).
 *   - Audit/OIDC: SHA-256 (approved).
 *   - RNG:        OpenSSL DRBG via crypto.randomBytes (approved).
 *   - TOTP:       HMAC-SHA1 per RFC 6238 — HMAC-SHA1 is permitted under FIPS;
 *                 a strict policy may still flag it, hence it is called out here.
 */
const crypto = require('crypto');

let _forced = null; // test seam: null = use real OpenSSL state

function requested() { return process.env.CITADEL_FIPS === '1'; }

// True when crypto is actually operating in FIPS mode right now.
function active() {
  if (_forced !== null) return _forced;
  try { return crypto.getFips() === 1 || crypto.getFips() === true; }
  catch (e) { return false; }
}

// PBKDF2 work factor for FIPS-mode password hashing (override per deployment).
function pbkdf2Iterations() {
  const n = parseInt(process.env.CITADEL_PBKDF2_ITER || '600000', 10);
  return Number.isFinite(n) && n >= 10000 ? n : 600000;
}

// Attempt to enter FIPS mode if requested. Idempotent; safe on non-FIPS builds.
// Returns the resulting active() state. Call once, early in bootstrap.
function enable() {
  if (!requested()) return false;
  if (active()) return true;
  try {
    crypto.setFips(true);
  } catch (e) {
    console.warn('[citadel] SECURITY: CITADEL_FIPS=1 was requested but this Node/OpenSSL build cannot enter FIPS mode (' +
      (e && e.message ? e.message : 'unsupported') + '). Run on a FIPS-validated OpenSSL to enforce it. Continuing in NON-FIPS mode.');
    return false;
  }
  if (active()) console.warn('[citadel] FIPS 140 mode ACTIVE — password hashing uses PBKDF2-HMAC-SHA256; non-approved algorithms are blocked by OpenSSL.');
  return active();
}

function status() {
  const on = active();
  return {
    requested: requested(),
    active: on,
    passwordKdf: on ? 'pbkdf2-hmac-sha256' : 'scrypt',
    pbkdf2Iterations: on ? pbkdf2Iterations() : undefined
  };
}

// Test seam only — force the active() result so the PBKDF2 code path can be
// exercised deterministically without a FIPS-linked OpenSSL build.
function _forceActive(v) { _forced = (v === null ? null : !!v); }

module.exports = { requested, active, enable, status, pbkdf2Iterations, _forceActive };
