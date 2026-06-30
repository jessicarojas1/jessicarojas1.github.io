'use strict';
/* CITADEL — TOTP (RFC 6238) + Base32, dependency-free.
 * Used for optional two-factor authentication. Compatible with Google
 * Authenticator / Authy / 1Password / Microsoft Authenticator (SHA1, 6 digits,
 * 30s step). generateSecret() returns Base32; otpauthURL() builds the QR/manual
 * enrollment URI; verify() checks a code within a small time window.
 */
const crypto = require('crypto');

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Encode(buf) {
  let bits = 0, value = 0, out = '';
  for (let i = 0; i < buf.length; i++) {
    value = (value << 8) | buf[i]; bits += 8;
    while (bits >= 5) { out += B32[(value >>> (bits - 5)) & 31]; bits -= 5; }
  }
  if (bits > 0) out += B32[(value << (5 - bits)) & 31];
  return out;
}
function base32Decode(str) {
  const clean = String(str).toUpperCase().replace(/=+$/g, '').replace(/[^A-Z2-7]/g, '');
  let bits = 0, value = 0; const out = [];
  for (let i = 0; i < clean.length; i++) {
    value = (value << 5) | B32.indexOf(clean[i]); bits += 5;
    if (bits >= 8) { out.push((value >>> (bits - 8)) & 0xff); bits -= 8; }
  }
  return Buffer.from(out);
}

// A new random Base32 secret (20 bytes = 160 bits, the RFC-recommended size).
function generateSecret() { return base32Encode(crypto.randomBytes(20)); }

// HOTP code for a given counter.
function hotp(secret, counter, digits = 6) {
  const key = base32Decode(secret);
  const buf = Buffer.alloc(8);
  // 64-bit big-endian counter
  for (let i = 7; i >= 0; i--) { buf[i] = counter & 0xff; counter = Math.floor(counter / 256); }
  const hmac = crypto.createHmac('sha1', key).update(buf).digest();
  const off = hmac[hmac.length - 1] & 0xf;
  const bin = ((hmac[off] & 0x7f) << 24) | ((hmac[off + 1] & 0xff) << 16) | ((hmac[off + 2] & 0xff) << 8) | (hmac[off + 3] & 0xff);
  return String(bin % (10 ** digits)).padStart(digits, '0');
}

function totp(secret, step = 30, t = Date.now(), digits = 6) {
  return hotp(secret, Math.floor((t / 1000) / step), digits);
}

// Verify a user-supplied code within +/- `window` steps (clock skew tolerance).
function verify(secret, token, window = 1, step = 30) {
  if (!secret || !token) return false;
  const tok = String(token).replace(/\s+/g, '');
  if (!/^\d{6}$/.test(tok)) return false;
  const tokBuf = Buffer.from(tok);
  const counter = Math.floor((Date.now() / 1000) / step);
  // Constant-time: compare every candidate with timingSafeEqual and never
  // early-return on a match, so neither the digit values nor which window
  // matched leak through response timing.
  let ok = false;
  for (let w = -window; w <= window; w++) {
    const cand = Buffer.from(hotp(secret, counter + w));
    if (cand.length === tokBuf.length && crypto.timingSafeEqual(cand, tokBuf)) ok = true;
  }
  return ok;
}

// otpauth:// URI for authenticator enrollment (QR or manual entry).
function otpauthURL(secret, label, issuer) {
  const enc = encodeURIComponent;
  return `otpauth://totp/${enc(issuer || 'CITADEL')}:${enc(label)}?secret=${secret}&issuer=${enc(issuer || 'CITADEL')}&algorithm=SHA1&digits=6&period=30`;
}

module.exports = { generateSecret, totp, hotp, verify, otpauthURL, base32Encode, base32Decode };
