# Contributing to Sentinel QMS

Thank you for contributing. Sentinel QMS is a defense/aerospace quality platform that processes CUI and
export-controlled data; contributions must meet a high bar for **security, traceability, and quality**.
This document describes the development workflow, branching model, coding standards, and the mandatory
security review gate.

> **Export control / CUI.** Do **not** commit CUI, ITAR/EAR-controlled technical data, customer data,
> secrets, or `.env` files. Only `.env.example` with placeholders may be committed. All contributors
> handling controlled program data must be authorized per the organization's policies.

---

## 1. Development Environment

```bash
# backend
cd backend
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"           # ruff, pytest, pytest-cov, etc.
pytest -q

# full local stack
cd ../infra
cp .env.example .env              # edit JWT_SECRET (>=32 chars)
docker compose up --build
```

See [deployment/deployment-guide.md](deployment/deployment-guide.md) for the full local setup.

---

## 2. Branching & Workflow

- **`main`** is the protected, always-deployable default branch. Direct pushes are not allowed.
- Create a topic branch off `main`:
  - `feat/<short-desc>` — new functionality
  - `fix/<short-desc>` — bug fix
  - `docs/<short-desc>` — documentation
  - `chore/<short-desc>` — tooling/maintenance
  - `sec/<short-desc>` — security fix
- Open a **Pull Request** to `main`. PRs require: passing CI, at least one code review, and (for
  security-relevant changes) a security review (see §6).
- Keep PRs focused and small. Reference the issue/requirement. Squash-merge with a clear title.

### Commit messages
Use imperative, descriptive subjects (e.g., `Add MRB record to NCR disposition flow`). Explain the *why*
in the body. Reference issues. Do not include secrets or controlled data in messages.

---

## 3. Coding Standards

### Backend (Python 3.12 / FastAPI)
- **Lint/format:** `ruff` (lint + format). CI fails on violations.
- **Types:** full type hints; Pydantic v2 schemas for all request/response models.
- **Layering:** router → service → model (see [architecture/overview.md](architecture/overview.md) §2).
  Keep HTTP concerns in routers and domain logic in services.
- **Database:** SQLAlchemy 2.0 with **parameterized queries only** — never string-concatenate user input
  into SQL. Migrations via Alembic, forward-only, reviewed.
- **Errors:** raise typed exceptions handled centrally (`app/core/exceptions.py`); never leak stack traces
  in production responses.
- **Tests:** `pytest` with `httpx`; cover new endpoints and service logic; maintain the coverage gate.

### Frontend (React + TypeScript)
- TypeScript strict mode; components typed against the generated API client.
- Never make authorization decisions client-side — the API is authoritative.
- Preserve the **CUI banner**; do not log tokens or controlled data.

---

## 4. Security Requirements (every change)

These are non-negotiable and enforced in review and CI:

- **AuthN/AuthZ:** every protected route enforces authentication and the correct permission
  (`require_permission` / `require_roles`). No endpoint ships without an access decision.
- **Input validation:** all inputs validated via Pydantic; reject unexpected fields.
- **SQL injection:** parameterized queries only.
- **Secrets:** no hardcoded credentials; secrets from env/Secrets Manager/Key Vault; `.env` never
  committed.
- **File uploads:** MIME validation, extension allowlist, size cap, randomized stored filenames, SHA-256
  recorded.
- **Audit & signatures:** mutating actions emit an audit row; signature-bearing actions capture an
  e-signature manifest.
- **Redirects:** validate any redirect target / referer against a strict allowlist.
- **Crypto:** TLS in transit, CMK at rest; FIPS-validated services; no custom crypto.
- **Logging:** structured logs; never log secrets, tokens, or CUI.

---

## 5. CI/CD Gates

Every PR runs:

| Gate | Tool |
|------|------|
| Lint/format | ruff |
| Unit/integration tests + coverage | pytest, pytest-cov |
| SAST | static analysis |
| Dependency/SCA scan | vulnerability scanner |
| Secret scanning | secret detector |
| Container image scan | image scanner |

On merge to `main`, images are built, **signed (cosign)**, and an **SBOM** is produced; only signed images
deploy. See [architecture/architecture-diagram.md](architecture/architecture-diagram.md) §6.

---

## 6. Security Review Gate

A change requires explicit **security review** when it touches any of:

- Authentication, authorization, RBAC, or session handling
- The audit log or electronic-signature flows
- Cryptography, key usage, or secrets handling
- Network/IaC (Terraform/Helm), ingress, or storage policy
- File upload/download paths
- Anything in the CUI/export-control boundary

The security review confirms compliance with the requirements in §4 and the controls in
[architecture/security-architecture.md](architecture/security-architecture.md). A change in these areas
may not merge without an approving security review.

---

## 7. Documentation

- Update the relevant docs in `docs/` with any behavior, API, config, or control change.
- Keep the [CHANGELOG.md](CHANGELOG.md) current for user-facing or compliance-relevant changes.
- API changes must update the OpenAPI contract and the generated clients.

---

## 8. Definition of Done

- ☐ Code follows standards (ruff clean, typed, layered)
- ☐ Tests added/updated; coverage gate passes
- ☐ Security requirements (§4) satisfied; security review obtained if applicable
- ☐ Authz enforced on every new/changed route
- ☐ Audit/e-signature wired for mutating/signature-bearing actions
- ☐ Migrations forward-only and reviewed
- ☐ Docs + CHANGELOG updated
- ☐ CI green (lint, tests, SAST, SCA, secret, container scans)
- ☐ No secrets/CUI/controlled data committed
