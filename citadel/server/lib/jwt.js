'use strict';
/* CITADEL — HS256 JWT, backed by the vetted `jsonwebtoken` library.
 *
 * Previously hand-rolled over crypto.HMAC. Swapped for `jsonwebtoken@9` so token
 * parsing/verification (alg pinning, exp/nbf handling, signature comparison) is
 * delegated to a maintained, widely-audited implementation rather than bespoke
 * code. The public contract is unchanged:
 *   sign(payload, secret, expSeconds) -> compact JWT string
 *   verify(token, secret) -> decoded payload | null
 * so no call site changes, and HS256 tokens already in flight over the same
 * CITADEL_JWT_SECRET stay valid across deploy. Rotate the secret to invalidate.
 */
const jwt = require('jsonwebtoken');

function sign(payload, secret, expSeconds = 43200 /* 12h */) {
  // expiresIn is seconds when numeric; iat is added automatically.
  return jwt.sign(payload, secret, { algorithm: 'HS256', expiresIn: expSeconds });
}

function verify(token, secret) {
  // Pin the algorithm: only HS256 is ever issued. Restricting algorithms here
  // forecloses alg-confusion (e.g. "none", or an RS256 token verified with the
  // public key as an HMAC secret) if an asymmetric path is ever introduced.
  // jsonwebtoken throws on any failure (bad signature, expired, malformed,
  // disallowed alg); we collapse all of those to null to preserve the caller
  // contract.
  try {
    return jwt.verify(String(token || ''), secret, { algorithms: ['HS256'] });
  } catch (e) {
    return null;
  }
}

module.exports = { sign, verify };
