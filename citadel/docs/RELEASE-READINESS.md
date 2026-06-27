# CITADEL — Release Readiness & Security Gate

> **Status:** Phase 1. This document describes the behavior defined by the
> Phase 1 shared contract. Anything not yet implemented is marked **Roadmap /
> TODO** and lives in the [Roadmap](#12-roadmap-phase-2) section.

The **Release Readiness & Security Gate** consumes every signal CITADEL already
produces during a scan, adds four new heuristic reviewers, and rolls everything
up into per-dimension scores, a single 0–100 readiness score, and a **gate
decision** with three audience-specific reports. It is the layer that answers
the question CITADEL's individual engines do not: *"Given everything we found,
is this build ready to ship — and if not, what has to change first?"*

---

## Table of contents

1. [Overview](#1-overview)
2. [Architecture & data flow](#2-architecture--data-flow)
3. [The unified finding schema](#3-the-unified-finding-schema)
4. [How severity & confidence are assigned](#4-how-severity--confidence-are-assigned)
5. [Readiness scoring](#5-readiness-scoring)
6. [The security gate decision rules](#6-the-security-gate-decision-rules)
7. [Compliance mapping interpretation](#7-compliance-mapping-interpretation)
8. [The three reports](#8-the-three-reports)
9. [Reviewing false positives & risk acceptance](#9-reviewing-false-positives--risk-acceptance)
10. [How to add a new scanner / reviewer](#10-how-to-add-a-new-scanner--reviewer)
11. [Security assumptions & known limitations](#11-security-assumptions--known-limitations)
12. [Roadmap (Phase 2+)](#12-roadmap-phase-2)

---

## 1. Overview

The Release Readiness & Security Gate is a **post-scan synthesis layer**. By the
time it runs, the CITADEL scan pipeline has already produced a single assembled
`report` object containing:

- **SAST findings** — the regex + intra-file taint rule engine (`report.findings`).
- **Dependency review** — `report.depreview` (deps, runtime services, env vars,
  ports, infra, external services, CVE/supply-chain, licenses, docs, gaps).
- **SBOM** — `report.sbom` (CycloneDX 1.5 components).
- **Secrets** — entropy-based secret findings folded into `report.findings`.
- **License posture** — `report.licenses` (`denied` / `review` / `allowed` / `detected`).
- **Rule packs** — IaC, CI/CD, API, and PII findings (also in `report.findings`).
- **Compliance posture** — `report.posture` (per-framework control mapping).

On top of those existing signals it runs **four new reviewers**:

| Reviewer | Module | Produces |
| --- | --- | --- |
| Logging / auditability | `review-logging.js` | Findings + logging coverage summary |
| Test coverage | `review-testing.js` | Findings + test-presence/coverage summary |
| Threat model | `review-threatmodel.js` | A generated STRIDE threat-model artifact (no findings) |
| Architecture risk | `review-architecture.js` | Heuristic architecture-risk findings + summary |
| Container security | `review-container.js` | Dockerfile / docker-compose / K8s container-hardening findings + summary |

The orchestrator then computes:

- **Per-dimension scores** across **12 dimensions** (`security`, `dependency`,
  `secrets`, `configuration`, `container`, `auth`, `api`, `data`, `logging`, `cicd`, `test`,
  `compliance`).
- An **overall 0–100 readiness score**.
- A **gate decision**, one of:
  **Approved** · **Conditional Approval** · **Requires Manual Review** · **Rejected**.
- **Three audience reports**: Executive Summary, Developer Remediation, and
  Auditor / Compliance Evidence.

The new reviewers attach their output to `report.reviews`; the orchestrator
attaches the rolled-up result to `report.readiness`; the UI module renders the
**Release Readiness** tab and wires the exports.

---

## 2. Architecture & data flow

### Where it sits in the pipeline

```
ingest ─▶ classify ─▶ scan (SAST, secrets, SBOM, binary, rule packs,
                          quality, deployment, scoring, frameworks, depreview)
        ─▶ [ reviews: logging · testing · threat-model · architecture ]
        ─▶ [ readiness: dimensions · overall · gate decision ]
        ─▶ report (renders the Release Readiness tab)
        ─▶ export (MD / HTML / JSON / CSV / PDF; audience reports)
```

The reviewers run **after** the core scan has assembled the `report`, because
they read from already-computed keys (`report.findings`, `report.depreview`,
`report.deployment`, `report.sbom`, `report.posture`, `report.licenses`,
`report.scoring`). The readiness orchestrator runs **after** the reviewers,
because it rolls their findings + summaries into dimensions.

### Execution model

- **Browser-first, vanilla JS.** Each module is an IIFE that attaches itself to
  the shared `window.CITADEL` namespace:
  ```js
  (function (root) {
    'use strict';
    const CITADEL = root.CITADEL = root.CITADEL || {};
    // ...
    CITADEL.reviewLogging = { analyze };
  })(window);
  ```
- **Runs in both the main thread and the scan Web Worker.** `worker.js` sets
  `self.window = self`, so the analysis modules load identically in either
  context. **Exception:** the report / UI module (`review-report.js`) is
  **main-thread only** — all DOM and export wiring is guarded behind a
  `typeof document !== 'undefined'` check (the same pattern `depreview.js` uses).
- **Pure and defensive.** `analyze()` functions perform **no network and no DOM**
  access, and **never throw** — every analysis path is wrapped in `try/catch`
  and skips gracefully on bad input, so a reviewer failure can never break the
  underlying scan.
- **Safe rendering.** All interpolated HTML is escaped; there are **no inline
  event handlers** (delegated `data-*` listeners only); no hardcoded hex in
  app-DOM styles (Bootstrap classes / CSS variables only).

### New modules and responsibilities

| Module | Export | Responsibility |
| --- | --- | --- |
| `js/review-logging.js` | `CITADEL.reviewLogging.analyze(entries, report)` | Detect security-event logging coverage and bad logging practices; emit findings + a logging summary. |
| `js/review-testing.js` | `CITADEL.reviewTesting.analyze(entries, report)` | Detect test presence/kinds, coverage config, and CI test/coverage gates; emit findings + a testing summary. |
| `js/review-threatmodel.js` | `CITADEL.reviewThreatModel.analyze(entries, report)` | Build a STRIDE threat model from detected surfaces. **Generated artifact — does not push into `report.findings`.** |
| `js/review-architecture.js` | `CITADEL.reviewArchitecture.analyze(entries, report)` | Heuristic architecture-risk review (Low/Medium confidence); emit findings + an architecture summary. |
| `js/review-container.js` | `CITADEL.reviewContainer.analyze(entries)` | Container-hardening review of Dockerfiles / docker-compose / K8s container specs; emit findings + a container summary. |
| `js/review-readiness.js` | `CITADEL.readiness.analyze(report)` | **Orchestrator + gate.** Roll reviewer + scan signals into 12 dimensions, compute the overall score, and apply the gate decision rules → `report.readiness`. |
| `js/review-report.js` | `CITADEL.reviewReport.*` | **Main-thread UI + exporters.** Render the Release Readiness tab; produce the three audience reports and MD/HTML/JSON/CSV exports. |

The reviewers attach to `report.reviews` (e.g. `report.reviews.logging`,
`report.reviews.testing`, `report.reviews.threatModel`,
`report.reviews.architecture`), and the orchestrator attaches
`report.readiness`.

> The shared contract notes that `CITADEL.readiness.analyze` is **owned by the
> parent** (the integrator), not by an individual reviewer agent, and the
> report/UI module is owned by the UI agent. The reviewer modules are the only
> pieces that emit findings into the scan.

---

## 3. The unified finding schema

All findings — whether from the existing scanner or the new reviewers — share
one shape. Existing scanner fields **must keep being emitted**; reviewers emit
those **plus** the enterprise extras.

| Field | Type / values | Source | Notes |
| --- | --- | --- | --- |
| `ruleId` | string | existing | Stable rule identifier. |
| `name` | string | existing | Human-readable finding title. |
| `category` | string | existing | e.g. `secrets`, `supply-chain`, `privacy`, `auth`, `api`. |
| `severity` | `critical` \| `high` \| `medium` \| `low` \| `info` | existing | **Lowercase** — must match existing values. |
| `confidence` | `high` \| `medium` \| `low` | existing | See [§4](#4-how-severity--confidence-are-assigned). |
| `cwe` | string | existing | e.g. `CWE-1104`; optional for non-code findings. |
| `file` | string | existing | Path or `name@version` for dependency findings. |
| `line` | number | existing | `0` when not line-specific. |
| `snippet` | string | existing | Short matched/context text. |
| `remediation` | string | existing | How to fix it. |
| `source` | string | existing | e.g. `heuristic`. |
| `module` | string | **extra** | Producing module — `logging` \| `testing` \| `architecture` \| … |
| `impact` | string | **extra** | Short impact sentence. |
| `likelihood` | `high` \| `medium` \| `low` | **extra** | Likelihood of exploitation/occurrence. |
| `remediationEffort` | `low` \| `medium` \| `high` | **extra** | Estimated effort to fix. |
| `references` | string[] | **extra** | Reference URLs. |
| `complianceMappings` | `{ framework, control, note }[]` | **extra** | See [§7](#7-compliance-mapping-interpretation). |

`complianceMappings[].framework` is one of, e.g., `NIST SP 800-171`, `CMMC L2`,
`ISO 27001`, `OWASP`, `OWASP API`, `SOC 2`, `CIS`.

> **Triage fields are Phase-2.** Per-finding persistence fields — `status`,
> `false_positive`, and `reviewer_notes` — are **not part of Phase 1**. They are
> a Phase-2 persistence item (server models + API); see [§12](#12-roadmap-phase-2).
> In Phase 1, triage/risk-acceptance is handled by the existing scan-history
> risk-acceptance mechanism rather than fields on the finding.

---

## 4. How severity & confidence are assigned

### Severity

Severity reflects the **impact if the issue is real**, on the existing lowercase
scale: `critical` > `high` > `medium` > `low` > `info`. Reviewers assign it from
the nature of the gap, for example:

- A logged secret / full credential, or missing auth on a protected route → high
  or critical.
- Missing security-event logging coverage, no CI test gate, or an architectural
  risk that weakens defense-in-depth → typically medium/low depending on blast
  radius.
- Informational observations → `info`.

The scanner's scoring engine (`js/scanner.js`) treats any unknown/missing
severity as `medium` so the score can never become `NaN`; reviewers should still
always set an explicit, correct value.

### Confidence

Confidence reflects **how sure the detector is that the finding is true**:
`high` \| `medium` \| `low`.

- **Deterministic / pattern-confirmed** signals (e.g. an exposed secret matched
  with high specificity, a denied license string, a CVE from OSV) are `high`.
- **Heuristic reviewers are intentionally Low/Medium confidence.** The
  **architecture** reviewer, the **threat model**, and several **logging /
  testing inferences** are derived from indirect signals (directory shapes,
  presence/absence of config, response-shape patterns). The contract requires
  these to be emitted at **Low or Medium confidence** and to **say so** — they
  flag areas that **require manual review**, not confirmed defects.

This distinction is load-bearing for the gate: the decision rules in
[§6](#6-the-security-gate-decision-rules) escalate to **Rejected** only on
**high-confidence** critical/secret/auth findings, so heuristic Low/Medium
findings drive **Conditional Approval** or **Requires Manual Review** rather than
an outright rejection.

---

## 5. Readiness scoring

`CITADEL.readiness.analyze(report)` produces `report.readiness`:

```js
{
  generatedAt,                    // ISO timestamp
  policyName,                     // active policy name
  dimensions: [
    { key, label, score: 0..100, weight, findings: int,
      critical: int, high: int, status: 'pass'|'warn'|'fail', notes }
  ],
  overall: 0..100,
  decision: 'Approved'|'Conditional Approval'|'Requires Manual Review'|'Rejected',
  rationale: [string],
  blockers: [string],
  requiredRemediation: [string],
  afterRemediation: [string],
  riskAcceptanceRequired: boolean,
  approverRoles: [string]
}
```

### The 11 dimensions

| `key` | Rolls up from |
| --- | --- |
| `security` | SAST findings + overall security scoring. |
| `dependency` | `report.depreview` + SBOM (dependency CVE / supply-chain / outdated). |
| `secrets` | Secret findings from the secrets scanner. |
| `configuration` | IaC / config rule-pack findings, env separation, config storage. |
| `auth` | Authentication / authorization findings (e.g. missing auth on routes). |
| `api` | API rule-pack findings (public/auth API surface). |
| `data` | PII / data-handling findings. |
| `logging` | `review-logging.js` coverage + bad-practice findings. |
| `cicd` | CI/CD rule-pack findings + presence of CI test/coverage gates. |
| `test` | `review-testing.js` test presence, kinds, and coverage gate. |
| `compliance` | `report.posture` framework control mapping. |

### Roll-up

Each dimension yields a `score` (0–100), a count of its `findings` (and
`critical` / `high` sub-counts), and a `status` of `pass` / `warn` / `fail`. The
**overall** score (0–100) is the weighted combination of the dimension scores
using each dimension's `weight`. Reviewer summaries that already expose a
`score: 0..100` (logging, testing, architecture) feed their respective
dimensions directly.

### Configurable policy

The default policy — dimension weights and the decision thresholds — is the
baseline below, but the **parent makes it configurable** via a global:

```js
window.CITADEL.readinessPolicy = {
  // weights, thresholds, and decision-rule overrides
};
```

`report.readiness.policyName` records which policy was applied. Set
`CITADEL.readinessPolicy` before scanning to override the defaults (the same
override pattern used for `CITADEL.licensePolicy` in `scanner.js`).

---

## 6. The security gate decision rules

The gate maps the rolled-up dimensions to one of four decisions. These are the
**defaults** from the contract; they are overridable via
`CITADEL.readinessPolicy`.

| Condition | Decision |
| --- | --- |
| Any **exposed secret** (secrets dimension has a **high-confidence** finding) | **Rejected** — remove **and rotate** the secret. |
| Any **Critical, high-confidence** finding | **Rejected** *(unless risk-accepted)*. |
| **Missing auth on protected routes** (auth dimension `fail`) | **Rejected**. |
| **Unknown licenses** present | At least **Requires Manual Review**. |
| **Multiple High** findings | **Conditional Approval** or **Rejected** (by category). |
| **No SBOM / no security-event logging / no CI gates / missing test gate** (and no crit/high) | **Conditional Approval**. |
| **Low / informational only** | **Approved** (or Conditional). |

### Gate outputs

Alongside `decision`, the gate emits:

| Output | Meaning |
| --- | --- |
| `blockers` | The top reasons the build cannot ship as-is (shown first). |
| `requiredRemediation` | What must be fixed **before** release. |
| `afterRemediation` | What should be addressed **after** release (lower priority). |
| `riskAcceptanceRequired` | `true` when shipping requires a formal risk-acceptance sign-off. |
| `approverRoles` | Who must approve, e.g. `['Security Lead','Risk Owner']` for **Rejected**, `['Engineering Manager']` for **Conditional Approval**. |
| `rationale` | Human-readable explanation of how the decision was reached. |

Risk acceptance is the only path by which a Critical, high-confidence finding can
avoid an automatic **Rejected** — and only with the required approver roles. See
[§9](#9-reviewing-false-positives--risk-acceptance).

---

## 7. Compliance mapping interpretation

> **No false certainty.** CITADEL **never asserts that a project is compliant or
> non-compliant** with any framework. It is a heuristic static analyzer, not an
> assessment authority.

When a finding or dimension carries a `complianceMappings` entry, the `note`
field uses **deliberately hedged language** — one of:

- **"Mapped control impact"** — this finding touches the named control.
- **"Potential evidence support"** — this signal *could* support evidence for the
  control.
- **"Potential control weakness"** — this *may* indicate a weakness in the control.
- **"Requires compliance owner review"** — a human compliance owner must decide.

These phrasings are mandatory. A mapping points an assessor at the **relevant
control to examine**; it is **not** a pass/fail verdict and must never be
presented as one. The Auditor report ([§8](#8-the-three-reports)) frames every
mapping as *evidence to review*, not as a compliance claim.

### Frameworks referenced

The readiness layer's mappings reference:

- **NIST SP 800-171** (e.g. the AU / audit-logging family, `3.3.x`)
- **CMMC Level 2** (AU and related practices)
- **ISO/IEC 27001**
- **NIST Cybersecurity Framework (CSF)**
- **SOC 2**
- **OWASP Top 10** and **OWASP API Security Top 10** (e.g. A09 Logging & Monitoring Failures)
- **CIS Controls**

> The wider CITADEL platform maps to 20+ frameworks (see the repo `README.md` /
> `FRAMEWORKS.md`). The list above is the set the readiness reviewers map to in
> Phase 1.

---

## 8. The three reports

`review-report.js` renders the **Release Readiness** dashboard (`#tab-readiness`)
and produces three audience-specific reports plus generic exports.

| Report | API | Audience | Contains |
| --- | --- | --- | --- |
| **Executive Summary** | `executive(report)` | Leadership / release approvers | Gate decision, overall score, top blockers, risk acceptance status, approver roles. Plain-language, no code. |
| **Developer Remediation** | `developer(report)` | Engineers | Findings grouped by dimension/severity, `requiredRemediation` vs `afterRemediation`, remediation steps, effort, file/line. |
| **Auditor / Compliance Evidence** | `auditor(report)` | Assessors / compliance owners | Dimension status, compliance mappings framed as *evidence to review* (never a verdict), the STRIDE threat model, and logging/test posture. |

### Export formats

| Format | API | Notes |
| --- | --- | --- |
| Markdown | `markdown(report)` | Full readiness report. |
| HTML | `html(report)` | Self-contained; also used for PDF. |
| JSON | `json(report)` | Machine-readable `report.readiness` + reviews. |
| CSV | `csv(report)` | **Findings register.** |
| PDF | — | Generated by printing the HTML report (browser print dialog). |

The parent wires the `[data-readiness-export]` buttons and the three audience
report buttons via delegated listeners (no inline handlers), mirroring the
`[data-dep-export]` wiring in `depreview.js`.

---

## 9. Reviewing false positives & risk acceptance

The browser engine is **heuristic** — verify findings before acting on them.

### Interpreting findings as a reviewer

1. **Check confidence first.** `high` = pattern/secret/CVE-confirmed; `low`/`medium`
   = heuristic inference that **requires manual review** (architecture, threat
   model, some logging/test inferences). Treat Low/Medium findings as *areas to
   investigate*, not confirmed defects.
2. **Read the `snippet`, `file`, and `line`** to confirm the match is live code
   (the SAST engine already skips fully-commented lines except for secrets/PII).
3. **Use `impact`, `likelihood`, and `remediationEffort`** to prioritize.
4. **Use `complianceMappings`** to understand which controls an assessor would
   care about — remembering these are *pointers*, not verdicts ([§7](#7-compliance-mapping-interpretation)).

### Risk acceptance and the gate

Risk acceptance is the formal path to ship despite an open finding:

- A **Critical, high-confidence** finding triggers **Rejected** *unless* it is
  risk-accepted. When acceptance is in play, `riskAcceptanceRequired` is `true`
  and `approverRoles` lists who must sign off (e.g. Security Lead + Risk Owner).
- Accepting risk does **not** delete the finding — it records an explicit,
  approved decision to proceed with the residual risk.

**Current (Phase 1):** risk acceptance / suppression uses CITADEL's existing
scan-history **risk-acceptance** feature (suppress findings, tracked locally).
Per-finding triage fields (`status`, `false_positive`, `reviewer_notes`) are
**not** persisted on the finding object yet.

**Phase 2:** durable, server-side finding triage (status / false-positive /
reviewer notes) with models + API — see [§12](#12-roadmap-phase-2).

---

## 10. How to add a new scanner / reviewer

New reviewers follow one repeatable pattern.

### The module pattern

1. **Create `js/review-<name>.js`** as an IIFE that attaches to `CITADEL`:
   ```js
   (function (root) {
     'use strict';
     const CITADEL = root.CITADEL = root.CITADEL || {};

     function analyze(entries, report) {
       try {
         const findings = [];
         // ...pure analysis over entries + already-computed report.* keys...
         return { findings, summary: { /* score: 0..100, ... */ } };
       } catch (e) {
         return { findings: [], summary: {} };
       }
     }

     CITADEL.reviewName = { analyze };
   })(window);
   ```
2. **Keep `analyze(entries, report)` pure and defensive** — no network, no DOM,
   never throw. Read from existing report keys (`report.findings`,
   `report.depreview`, `report.deployment`, `report.sbom`, `report.posture`,
   `report.licenses`) rather than re-deriving them.
3. **Emit findings in the unified schema** ([§3](#3-the-unified-finding-schema)),
   setting `module`, correct `severity`/`confidence`, and — for heuristic
   detectors — **Low/Medium confidence** with language that says manual review
   is needed.
4. **Attach output to the report** (`report.reviews.<name>`) and ensure the
   readiness orchestrator can fold your findings + `summary.score` into the
   right dimension.
5. **Map to compliance with hedged `note` phrasing** ([§7](#7-compliance-mapping-interpretation)).

### Integration seams

| Seam | File | What to add |
| --- | --- | --- |
| Scan call site | `js/scanner.js` | Invoke the reviewer after the core scan, guarded like the existing `CITADEL.depreview` call (`if (CITADEL.reviewX && CITADEL.reviewX.analyze) …`). |
| Worker import | `js/worker.js` | Add the new module to `importScripts` so it loads in the scan worker. |
| Tab + script tag | `index.html` | Add the **Release Readiness** tab (if not present) and a `<script>` tag for the module. |
| Render hook | `js/report.js` / `js/review-report.js` | Call the render hook so the new output appears in the readiness tab. |

### Reference material

- **The authoritative spec** is the shared contract:
  `readiness-contract.txt` (module boundaries, finding schema,
  `report.readiness` shape, decision rules).
- **The closest code references** for style and structure are
  `js/depreview-security.js` and `js/depreview-report.js`, and the orchestrator
  pattern in `js/depreview.js`.
- Conventions: 2-space indent, single quotes, semicolons, no unused vars; keep
  `node --check` clean. Validate a module with a throwaway Node harness that
  stubs `window`/`document`, then delete the harness.

---

## 11. Security assumptions & known limitations

- **Static analysis only — no code execution.** CITADEL parses and pattern-matches
  source; it never runs the target code.
- **Heuristic detection.** The browser engine is pattern-based. The architecture
  reviewer, the threat model, and several logging/test inferences are **Low/Medium
  confidence** and flag *areas to review*, not confirmed defects. Verify findings
  before acting on them.
- **No false compliance certainty.** Compliance mappings are *pointers to controls
  to examine*, never pass/fail verdicts ([§7](#7-compliance-mapping-interpretation)).
- **Browser-first / no database on the live deploy.** The Release Readiness layer
  runs entirely client-side (main thread + scan Web Worker). The live static
  deployment has **no server-side persistence**; findings, history, and risk
  acceptance live in the browser (`localStorage` / scan history).
- **Offline analysis.** Reviewers make **no network calls**. The only live
  external lookup anywhere in CITADEL is the existing OSV.dev CVE cross-check in
  the dependency path — the readiness reviewers add no new registry/CVE calls.
- **Bounded inputs.** Text files up to 2 MB carry full `content`; very large
  binaries are inspected only at the byte level. Detection depends on the files
  actually being present in the upload.

### Deferred past Phase 1 (now delivered in Phases 2–6)

- Per-finding triage **persistence** (`status` / `false_positive` /
  `reviewer_notes`) — delivered in Phase 2.
- A standalone, deep container module — delivered in Phase 3.
- An editable threat model (the Phase 1 STRIDE model was generated, read-only) —
  delivered in Phase 4.
- DB-backed readiness **history / trend** of the gate decision — delivered in
  Phase 5.

---

## 12. Roadmap (Phase 2+)

> **Status: the original Phase 2+ roadmap is fully delivered** (Phases 2–6). The
> items below are kept for history with their delivering phase; new ideas follow.

**Delivered**

- ✅ **Finding-triage persistence** — durable `status` / `false_positive` and
  **reviewer notes** on findings (server models + API, localStorage fallback);
  `accepted` / `false-positive` dispositions now feed the gate. *(Phase 2)*
- ✅ **Standalone Container Security module** — `review-container.js` deep-checks
  Dockerfiles / docker-compose / K8s container specs; a `container` gate
  dimension. *(Phase 3)*
- ✅ **Editable threat model** — reviewers add / edit / remove STRIDE threats +
  mitigations + residual risk; a per-project overlay persisted to
  localStorage + Postgres. *(Phase 4)*
- ✅ **ASVS / CIS expansion** — OWASP ASVS + CIS Controls map across every
  finding category, including the new test-readiness dimension. *(Phase 6)*
- ✅ **DB-backed readiness history & trend** — each scan persists its gate
  decision + overall score; the History tab shows a readiness trend. *(Phase 5)*

**Possible next ideas** (not yet planned)

- Risk-acceptance **expiry + approver attribution** (who accepted, until when),
  surfaced as auditor evidence and re-flagged on expiry.
- Cross-project **portfolio dashboard** — gate decisions + trend across all
  projects in one view.
- **Server-side readiness recompute** for deep scans, so DB history is populated
  without relying on the client pass.
