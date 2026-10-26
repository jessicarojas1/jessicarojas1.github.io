# ADR-0013: Phase 1 implementation decisions

- **Status:** Accepted (Phase 1, 2026-10-26)
- **Date:** 2026-10-26

Decisions taken while building the foundation, each with its reason.

## 1. In-house OIDC client on `firebase/php-jwt`, not an OAuth2 bundle
**Decision:** a small client in `src/Identity/Oidc/` implements the authorization-code flow with PKCE, state and nonce, plus client credentials for Graph. RS256 signature verification is delegated to `firebase/php-jwt` v7 (keys parsed with `JWK::parseKeySet($jwks, 'RS256')`, because Entra's JWKS omits `alg`). Issuer, audience, tenant, nonce, `exp`/`iat` presence and `oid` are checked explicitly.
**Why:** the alternatives evaluated were `knpuniversity/oauth2-client-bundle` + `thenetworg/oauth2-azure` (two layers; OIDC-specific checks not prominent in the API) and `jumbojett/openid-connect-php` (last release v1.0.2; pulls in phpseclib). The chosen approach adds one widely used dependency, keeps every security check visible, and lets each one be unit-tested with locally generated keys (`tests/Unit/Identity/IdTokenValidatorTest.php`: algorithm confusion, unsigned tokens, foreign keys, wrong issuer/audience/tenant/nonce, expiry).
**Consequence:** PAL owns this code. It is small and fully covered by tests. Client certificates (`private_key_jwt`) send both `x5t` and `x5t#S256` headers. This has **not yet been tested against a real Entra tenant**.

## 2. Doctrine DBAL, no ORM
**Why:** PAL's invariants live in SQL (exclusion constraints, ranges, append-only triggers, audit written in the same transaction). Explicit SQL keeps that visible and avoids identity-map surprises with immutable rows. Values from rows go through `App\Platform\Database\Row`, which fails loudly on type mismatches instead of casting.

## 3. PHPStan: `max` for `src/`, level 6 for `tests/`
**Why:** at max level, test findings are almost entirely "mixed from `json_decode()`" inside assertions. That adds noise without catching defects. Production code gets the strictest level as a CI gate.

## 4. Sessions in PostgreSQL on a separate PDO connection
**Why:** a session store needs no new infrastructure, revocation is immediate, and multiple web instances share sessions. A separate connection with advisory locks keeps session locking out of DBAL's transaction state.

## 5. Roles reloaded on every request; voter reads current roles
**Why:** directory changes (removing someone from an approver group, disabling an account) must apply without waiting for a sign-out. `PalUser::isEqualTo` ignores roles so a role change does not force re-authentication, and `PermissionVoter` evaluates the refreshed user's roles. A disabled user fails the refresh and becomes anonymous on their next request.

## 6. Database role separation, enforced in three layers
Owner role (migrations) vs app role (runtime): `deploy/sql/roles.sql`. The `migrate` container step re-applies the audit-trail REVOKE after every migration. Default privileges would otherwise grant UPDATE/DELETE on a newly created `audit_event` until the roles script re-runs. Triggers block UPDATE/DELETE/TRUNCATE even for the owner, and the hash chain detects anything that bypasses both. Verified against PostgreSQL: as the app role, UPDATE/DELETE/TRUNCATE/ALTER on `audit_event` are denied and INSERT is allowed.

## 7. One container image, roles selected at start
`web` (nginx + PHP-FPM under supervisord), `worker`, `migrate`, `console`. The image runs non-root (uid 10001), refuses to start without required configuration (exit 64), and defaults `TRUSTED_PROXIES=private_ranges` so client IPs and HTTPS detection work behind a load balancer.

## 8. Contrast-safe branding
Organisations choose any accent colour. The UI derives the text colour placed on the accent, and adjusts the accent wherever it is used as text, so WCAG AA (4.5:1) holds in both themes for any accent (`src/branding.ts`, with tests over hostile colours).
