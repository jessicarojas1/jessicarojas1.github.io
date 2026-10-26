# ADR-0008: Backend: PHP 8.4+ on Symfony (LTS)

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed (owner selected PHP, D1, 2026-10-25; framework choice is D13)
- **Date:** 2026-10-25
- **Supersedes:** ADR-0002

## Context
The owner selected PHP. PAL's riskiest areas are a strict lifecycle state machine, resource-level authorization, durable idempotent background jobs, raw PostgreSQL features (range types, exclusion constraints) and a security baseline of OWASP ASVS L2. The code will be handed to a company and maintained for years by people other than its author.

## Options
| | **Symfony (LTS)** | Laravel | In-house framework (as used in this repo's AEGIS / Verity / REDOUBT / PALADIN) |
|---|---|---|---|
| State machine | **Workflow component**: state machines with guards, transition events and marking stores, built in | Third-party packages | Hand-written |
| Resource authorization | **Security voters**: per-object, per-attribute decisions | Policies/gates (good) | Hand-written `Auth::` helpers |
| Durable jobs without Redis | **Messenger** with a Doctrine (PostgreSQL) transport; **Scheduler** component | Queues with database driver; scheduler via cron (good) | Hand-written |
| DB / migrations | Doctrine DBAL/ORM + Migrations; raw SQL migrations for exclusion constraints | Eloquent + migrations (raw SQL for exclusion constraints) | Hand-written SQL |
| HTML sanitizing (needed for the editor) | `symfony/html-sanitizer` | Third-party | Hand-written |
| Support model | LTS releases with ~3 years of bug fixes and 4 of security fixes | Shorter per-major support window, faster cadence | None outside this repo |
| Hiring / handover | Common in enterprise PHP | Most popular PHP framework | Unknown to any new team |

## Decision (recommended)
**Symfony, current LTS line** (7.4 LTS at time of writing; re-verify at Phase 1 start), **PHP 8.4+** with `declare(strict_types=1)` everywhere and **PHPStan at its strictest level in CI**. Key components: Workflow, Security (voters), Messenger (Doctrine transport) + Scheduler, Doctrine Migrations, HtmlSanitizer, Mailer, Monolog. Served by nginx + PHP-FPM. Background consumers run as separate containers with memory and time limits and automatic restart.

Laravel is an acceptable alternative if the receiving team already knows it. Its gaps (no built-in state machine; a shorter support window) are manageable.

The in-house framework is **not recommended** for PAL. It is consistent with this portfolio, but handing a company a custom, single-author framework creates a maintenance and security-patching liability that a mainstream framework avoids. The repo's security rules (CSP nonces, CSRF on every POST, output escaping, parameterized SQL, no inline handlers) are kept and implemented with the framework's equivalents.

## Consequences
- Every lifecycle transition becomes a Workflow transition with guards that call voters. The Workflow component makes illegal transitions impossible by construction, and a DB trigger enforces content immutability as a second layer.
- Entra/OIDC uses a maintained OIDC client. Candidates (e.g. `knpuniversity/oauth2-client-bundle` with `thenetworg/oauth2-azure`, or `jumbojett/openid-connect-php`) are evaluated in Phase 1 for PKCE, nonce, and full ID-token validation.
- PHP-FPM is shared-nothing per request, which suits the BFF session model. Long-running workers are the main PHP-specific operational risk, mitigated by `--memory-limit`/`--time-limit` and supervisor restart.
