# MERIDIAN — Executive Strategic Intelligence & Decision-Support System (v2)

MERIDIAN is a client-side, **open-source / non-classified** executive strategic-intelligence
engine. Its purpose is not to summarize news but to create **decision advantage**: it
identifies, fuses, challenges, and prioritizes lawful public information across global
security, U.S. national security, aerospace, defense, space, the defense industrial base,
government contracting, cybersecurity, AI, and emerging technology — using **only lawful,
publicly available information**.

**v2 doctrine** adds full analytic tradecraft (KNOWN/REPORTED/CLAIMED/ASSESSED/UNKNOWN/FORECAST),
confidence levels, indicators & warnings (GREEN→RED), actor-intent (capability vs intent),
alternative analysis, scenarios, orders of effect, decision memos, red-team review, and — the
core of decision advantage — **persistent intelligence threads** and a **forecast scorecard**
carried across reporting cycles.

It is a static app (HTML/CSS/vanilla JS), part of the `jessicarojas1.github.io` site,
in the same style as `citadel`. There is **no backend and no server-side storage**.

- **Open the app:** `meridian/index.html`
- **Seed brief included:** a real, fully-cited brief for **2026-09-06** loads out of the box.

---

## What it does

Given your own LLM key, MERIDIAN runs the v2 doctrine in [`js/prompt.js`](js/prompt.js)
against the provider you choose, with an optional **live web-search tool**, and renders a
full intelligence product:

- **BLUF** (what changed / why it matters / what it could change / what you need to know)
- **The 3 Things I Cannot Afford to Miss Today**
- **Executive Watchboard** — intelligence threads with status · direction · risk · opportunity · next indicator
- **10 fused domain sections** — items move through OBSERVATION → CONTEXT → CHANGE → SIGNIFICANCE →
  CAUSATION → ACTOR INTENT → IMPLICATIONS → 2nd/3rd-order → INDICATORS (GREEN→RED) → alternatives →
  scenarios → org & personal impact → recommendation, with confidence and cited sources
- **Cross-domain connections**, **resurfaced intelligence**, **weak signals & early warning**
- **Impact to the A&D industry**, **Impact to My Organization** (immediate/near/strategic/none),
  **How This Applies to Me**
- **Executive recommendations** (INFORM/WATCH/VALIDATE/REVIEW/PREPARE/ENGAGE/INVESTIGATE/ACT with
  rationale, evidence, timing, owner, trigger, risk-of-action/inaction), **decision memos**
- **Questions I should be asking / may be asked**, **watch horizons (24–72h / 7–30d / 3–12mo)**,
  **strategic surprise watch**, **What Could We Be Wrong About?**, **red-team review**
- **Source notes**, **Intelligence Performance Review** (forecast scorecard), and a mandatory
  **Assumptions, Intelligence Gaps & Analytic Confidence** section

### Continuity: threads & track record
Two views make continuity visible: **Threads** (`data/threads.json`) carries each major issue
forward with direction of travel, indicators, and a timeline; **Track Record** (`data/scorecard.json`)
logs prior forecasts and grades them as they resolve — incorrect calls are kept, not erased.
The daily engine reads and updates both each run.

### Delivery
- On-screen executive view
- **Copy Email** — puts a styled HTML body **and** a plain-text version on your clipboard
- **PDF** — print-optimized layout with your branding header
- **Send** — via [EmailJS](https://www.emailjs.com) (client-side; you supply the service/template/keys)
- **Export JSON** — save any brief; import it later or on another device

---

## Setup

Everything is configured in **Settings** and stored **only in your browser** (`localStorage`).

### 1. Intelligence engine (required to generate)
- **Provider:** Anthropic (Claude) or OpenAI
- **Model:** e.g. `claude-opus-4-8` / `claude-sonnet-5`, or an OpenAI Responses-API model such as `gpt-4o`
- **API key:** sent directly to the provider over HTTPS. MERIDIAN never transmits it anywhere else and never commits it.
- **Web search:** ON by default — the model performs a live open-source sweep so the brief reflects current
  reporting. If the tool is unavailable for your account/model, MERIDIAN retries without it and marks the
  brief lower-confidence rather than failing.
- **Test connection** confirms the key/model before you spend a full generation.

> Calling a provider API directly from the browser requires that provider to allow it. For Anthropic,
> MERIDIAN sends the `anthropic-dangerous-direct-browser-access` header. Keys used this way are exposed to
> your own browser session; use a scoped key you are comfortable using client-side.

### 2. Recipient profile (optional, recommended)
Role, focus areas, and **non-sensitive** organization context. This shapes the *Impact to My Work* and
*What I Should Know Today* sections. **Do not enter sensitive internal, program, network, or personnel
detail** — the doctrine keeps organizational impact at the strategic/industry level by design.

### 3. Email delivery (optional)
Create an EmailJS account + template exposing `{{subject}}`, `{{to_email}}`, and `{{html}}` (or `{{message}}`),
then paste the **public key**, **service ID**, **template ID**, and **recipient**.

### 4. Branding (optional)
Organization/product name, logo (URL or uploaded data-URL), and accent color. Applied live to the header,
document title, print/PDF header, and email export. Logo URLs are restricted to `http(s)://` or `data:image/…`.

---

## Security & integrity model

- **No inline event handlers** — all interactions use `data-action` / `data-nav` delegation (CSP-friendly).
- **Content Security Policy** meta restricts `connect-src` to the provider APIs and EmailJS only.
- **XSS-safe rendering** — model/user content is inserted via `textContent`/DOM nodes, never `innerHTML`;
  source URLs are scheme-checked (`http(s)` only) so a hostile `javascript:` "source" is rendered inert.
- **Logo sanitization** — only `http(s)`/`data:image` URLs are accepted; strings are injected via DOM, not markup.
- **No secrets in the repo** — keys and settings live only in the browser; nothing is committed.
- **Anti-fabrication doctrine** — the analyst prompt forbids inventing specifics and requires marking
  intelligence gaps and lowering confidence instead. Adversarial-state reporting is treated as narrative,
  not fact. OPSEC rules keep organizational analysis at the strategic level.

> **Honesty note on the seed brief:** the included `2026-09-06` brief was compiled from an open-source web
> sweep. Every consequential claim is cited to a primary/corroborating URL, and single-source or
> machine-summarized claims are explicitly flagged in the *Assumptions, Intelligence Gaps & Confidence* section.

---

## Data model

Briefs are plain JSON validated/normalized by [`js/render.js`](js/render.js) and documented as a strict
contract in [`js/prompt.js`](js/prompt.js) (`OUTPUT_CONTRACT`). Seed briefs are listed in
[`data/briefs.json`](data/briefs.json) and loaded on first run if not already in your archive.

### Database
MERIDIAN uses **no database** — all persistence is browser `localStorage`. A `database/schema.sql` file is
therefore **not applicable** to this app (that project rule applies to database-backed apps such as `aegis`).

---

## Files

```
meridian/
├── index.html            App shell (all views)
├── css/meridian.css      Theme-aware styling (dark-first, Bootstrap tokens)
├── js/
│   ├── prompt.js         v2 doctrine + strict JSON output contract
│   ├── store.js          localStorage persistence (settings, keys, archive)
│   ├── branding.js       Logo / name / accent (sanitized)
│   ├── feeds.js          Curated open-source portals (passive launch links)
│   ├── llm.js            Anthropic + OpenAI providers, web-search tool, thread continuity, JSON extraction
│   ├── render.js         Brief object → DOM (XSS-safe, v2-aware)
│   ├── export.js         Email HTML/text, clipboard, print, EmailJS send, JSON I/O
│   └── app.js            Controller: routing, settings, generate flow, archive, threads, track record
├── data/
│   ├── briefs.json       Seed manifest (newest 14)
│   ├── brief-2026-09-06.json   Seed brief (real, cited, v2)
│   ├── threads.json      Persistent intelligence threads
│   └── scorecard.json    Forecast track record
└── README.md
```

---

*MERIDIAN produces analytic judgments from public information. It is not a classified product and never
implies access to classified, restricted, or non-public intelligence.*
