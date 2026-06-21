'use strict';
/* CITADEL — application-layer secret encryption (envelope, AES-256-GCM).
 *
 * Protects the few high-value secrets we must persist (TOTP seeds, the JWT
 * signing key) so that a leak of the durable store (JSON file or a Postgres
 * dump/backup) does not directly hand an attacker the ability to mint sessions
 * or generate valid 2FA codes. This is defense-in-depth at rest — it does NOT
 * replace disk/DB encryption or a real KMS; it complements them by ensuring the
 * values are ciphertext inside our own store.
 *
 * Key: CITADEL_DATA_KEY, a 32-byte key supplied as 64 hex chars or base64
 * (ideally injected from a secrets manager). When unset/invalid, encryption is
 * DISABLED and values are stored as-is — so existing deployments keep working
 * and adoption is opt-in. Reads always transparently accept BOTH sealed and
 * legacy-plaintext values, so enabling the key migrates data lazily on next
 * write with no downtime and no migration script.
 *
 * Format: "enc:v1:" + base64( iv(12) || authTag(16) || ciphertext ). The "enc:v1:"
 * prefix is what distinguishes a sealed value from legacy plaintext on read.
 */
const crypto = require('crypto');

const PREFIX = 'enc:v1:';
const IV_LEN = 12;   // GCM standard nonce
const TAG_LEN = 16;

let _key; // undefined = not yet resolved, null = disabled, Buffer = active
let _warned = false;

// Resolve the 32-byte key from CITADEL_DATA_KEY (hex or base64). Cached. A bad
// key disables encryption (and warns once in production) rather than crashing —
// availability over a hard fail, with the plaintext path still functional.
function key() {
  if (_key !== undefined) return _key;
  const raw = process.env.CITADEL_DATA_KEY;
  if (!raw) { _key = null; return _key; }
  let buf = null;
  const s = String(raw).trim();
  if (/^[0-9a-fA-F]{64}$/.test(s)) buf = Buffer.from(s, 'hex');
  else { try { const b = Buffer.from(s, 'base64'); if (b.length === 32) buf = b; } catch (e) { /* not base64 */ } }
  if (!buf || buf.length !== 32) {
    if (!_warned && process.env.NODE_ENV === 'production') {
      _warned = true;
      console.warn('[citadel] SECURITY: CITADEL_DATA_KEY is set but is not a valid 32-byte key (64 hex chars or base64). At-rest secret encryption is DISABLED.');
    }
    _key = null; return _key;
  }
  _key = buf; return _key;
}

function enabled() { return !!key(); }
function isSealed(v) { return typeof v === 'string' && v.startsWith(PREFIX); }

// Encrypt a string. No-op (returns input unchanged) when encryption is disabled,
// the value is empty, or it is already sealed — so seal() is idempotent and safe
// to call on every write regardless of prior state.
function seal(plaintext) {
  if (plaintext == null || plaintext === '') return plaintext;
  if (isSealed(plaintext)) return plaintext;
  const k = key();
  if (!k) return plaintext;
  const iv = crypto.randomBytes(IV_LEN);
  const cipher = crypto.createCipheriv('aes-256-gcm', k, iv);
  const ct = Buffer.concat([cipher.update(String(plaintext), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return PREFIX + Buffer.concat([iv, tag, ct]).toString('base64');
}

// Decrypt a sealed value back to its plaintext. Legacy plaintext (no prefix) is
// returned unchanged so old stores keep reading. A sealed value that can't be
// opened (no key, or tampered/wrong key — GCM auth fails) returns null: callers
// treat that as "secret unavailable" (e.g. MFA can't verify) rather than trust
// forged bytes.
function open(value) {
  if (!isSealed(value)) return value;
  const k = key();
  if (!k) {
    if (!_warned) { _warned = true; console.warn('[citadel] SECURITY: encountered an encrypted secret but CITADEL_DATA_KEY is not configured — cannot decrypt.'); }
    return null;
  }
  try {
    const raw = Buffer.from(value.slice(PREFIX.length), 'base64');
    const iv = raw.subarray(0, IV_LEN);
    const tag = raw.subarray(IV_LEN, IV_LEN + TAG_LEN);
    const ct = raw.subarray(IV_LEN + TAG_LEN);
    const decipher = crypto.createDecipheriv('aes-256-gcm', k, iv);
    decipher.setAuthTag(tag);
    return Buffer.concat([decipher.update(ct), decipher.final()]).toString('utf8');
  } catch (e) {
    return null;
  }
}

// Test seam: drop the cached key so a changed CITADEL_DATA_KEY is re-read.
function _reset() { _key = undefined; _warned = false; }

module.exports = { enabled, isSealed, seal, open, _reset };
