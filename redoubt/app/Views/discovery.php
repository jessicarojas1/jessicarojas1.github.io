<?php
/**
 * REDOUBT — Product + Architecture Discovery Package (view).
 * Rendered by public/index.php. Read-only. $NONCE is supplied by the controller.
 */
$NONCE = $NONCE ?? '';
$today = '18 September 2026';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>REDOUBT — GMRE Program Portal Framework | Discovery Package</title>
<meta name="description" content="Product &amp; Architecture Discovery Package for the GMRE Program / Subcontractor Team Portal (REDOUBT).">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style nonce="<?= $NONCE ?>">
:root{
  --navy:#0a1a30; --navy-2:#0f2543; --steel:#1b3a5c; --line:#e2e8f0;
  --ink:#0f1b2d; --muted:#5a6a7e; --bg:#f6f8fb; --card:#ffffff;
  --accent:#1e6fb8; --accent-2:#12808c; --gold:#c8992e;
  --ok:#1f9d55; --warn:#c8992e; --risk:#c0392b; --info:#2d6cdf;
  --radius:14px; --shadow:0 1px 2px rgba(10,26,48,.06),0 8px 24px rgba(10,26,48,.06);
  --maxw:1140px;
}
@media (prefers-color-scheme:dark){
  :root{
    --navy:#0a1526; --navy-2:#0e1f38; --steel:#28post; --line:#1e2c3f;
    --ink:#e7eef7; --muted:#9fb0c4; --bg:#0a1220; --card:#0f1b2d;
    --line:#20304a; --accent:#4a97dd; --accent-2:#2bb3c0;
    --shadow:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);
  }
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;background:var(--bg);color:var(--ink);
  font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  line-height:1.6;font-size:16px;-webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
code,.mono{font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;font-size:.86em}

/* ---------- Masthead ---------- */
.masthead{background:linear-gradient(135deg,var(--navy),var(--steel));color:#fff;
  border-bottom:3px solid var(--gold)}
.mast-inner{max-width:var(--maxw);margin:0 auto;padding:34px 24px 30px}
.eyebrow{letter-spacing:.22em;text-transform:uppercase;font-size:11px;font-weight:700;
  color:#9fc6ea;margin:0 0 10px}
.mast-inner h1{margin:0;font-size:clamp(28px,4vw,44px);font-weight:800;letter-spacing:-.5px}
.mast-inner h1 span{color:var(--gold)}
.mast-sub{margin:10px 0 0;color:#cfe0f2;font-size:clamp(15px,2vw,19px);max-width:820px}
.mast-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.chip{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18);
  padding:6px 12px;border-radius:999px;font-size:12.5px;color:#eaf2fb;font-weight:500}
.chip b{color:#fff}
.classline{max-width:var(--maxw);margin:0 auto;padding:8px 24px;font-size:11px;
  letter-spacing:.14em;text-transform:uppercase;color:#eaf2fb;font-weight:700;text-align:center}
.class-band{background:#14532d}

/* ---------- Layout ---------- */
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 24px;display:grid;
  grid-template-columns:248px 1fr;gap:36px;align-items:start}
.toc{position:sticky;top:16px;align-self:start;max-height:calc(100vh - 32px);
  overflow:auto;padding:18px 8px 40px;font-size:13.5px}
.toc h4{margin:14px 8px 6px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.toc a{display:block;color:var(--muted);padding:5px 10px;border-radius:8px;line-height:1.35}
.toc a:hover{background:var(--card);color:var(--ink);text-decoration:none}
.content{padding:26px 0 90px;min-width:0}
.navtoggle{display:none}

section.block{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:26px 28px;margin:0 0 20px;box-shadow:var(--shadow);scroll-margin-top:16px}
section.block>h2{margin:0 0 4px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent)}
section.block>h2 .n{color:var(--muted);margin-right:8px}
section.block>h3.title{margin:2px 0 16px;font-size:23px;font-weight:800;letter-spacing:-.3px;line-height:1.25}
h4.sub{margin:22px 0 8px;font-size:16px;font-weight:700;color:var(--ink)}
p{margin:0 0 12px}
ul,ol{margin:0 0 12px;padding-left:20px}
li{margin:4px 0}
.lead{font-size:17px;color:var(--ink)}
.muted{color:var(--muted)}

/* ---------- Tables ---------- */
.tablewrap{overflow-x:auto;margin:14px 0;border:1px solid var(--line);border-radius:10px}
table{border-collapse:collapse;width:100%;font-size:13.5px;min-width:560px}
th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
th{background:var(--bg);font-weight:700;color:var(--ink);position:sticky;top:0}
tbody tr:last-child td{border-bottom:none}
tbody tr:nth-child(even){background:color-mix(in srgb,var(--bg) 55%,transparent)}

/* ---------- Badges / callouts ---------- */
.badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;
  border:1px solid transparent;white-space:nowrap}
.b-mvp{background:color-mix(in srgb,var(--ok) 15%,transparent);color:var(--ok);border-color:color-mix(in srgb,var(--ok) 35%,transparent)}
.b-p2{background:color-mix(in srgb,var(--info) 14%,transparent);color:var(--info);border-color:color-mix(in srgb,var(--info) 34%,transparent)}
.b-p3{background:color-mix(in srgb,var(--accent-2) 15%,transparent);color:var(--accent-2);border-color:color-mix(in srgb,var(--accent-2) 34%,transparent)}
.b-fut{background:color-mix(in srgb,var(--muted) 15%,transparent);color:var(--muted);border-color:color-mix(in srgb,var(--muted) 34%,transparent)}
.b-block{background:color-mix(in srgb,var(--risk) 14%,transparent);color:var(--risk);border-color:color-mix(in srgb,var(--risk) 34%,transparent)}
.b-high{background:color-mix(in srgb,var(--warn) 16%,transparent);color:var(--warn);border-color:color-mix(in srgb,var(--warn) 36%,transparent)}
.b-low{background:color-mix(in srgb,var(--muted) 14%,transparent);color:var(--muted);border-color:color-mix(in srgb,var(--muted) 34%,transparent)}

.callout{border-left:4px solid var(--accent);background:color-mix(in srgb,var(--accent) 7%,transparent);
  padding:12px 16px;border-radius:0 10px 10px 0;margin:14px 0}
.callout.warn{border-left-color:var(--warn);background:color-mix(in srgb,var(--warn) 9%,transparent)}
.callout.risk{border-left-color:var(--risk);background:color-mix(in srgb,var(--risk) 8%,transparent)}
.callout .k{font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:var(--muted)}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.tile{border:1px solid var(--line);border-radius:12px;padding:14px 16px;background:var(--bg)}
.tile h5{margin:0 0 6px;font-size:14px}
.tile p{margin:0;font-size:13.5px;color:var(--muted)}

/* ---------- Wireframe ---------- */
.wf{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--card);margin:14px 0}
.wf-top{background:var(--navy);color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.wf-brand{display:flex;align-items:center;gap:10px;font-weight:800}
.wf-logo{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,var(--gold),#e6c05a);display:inline-block}
.wf-nav{background:var(--navy-2);color:#cfe0f2;padding:9px 16px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;font-weight:600}
.wf-body{padding:16px}
.wf-banner{background:color-mix(in srgb,var(--gold) 18%,transparent);border:1px dashed color-mix(in srgb,var(--gold) 55%,transparent);padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px}
.wf-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.wf-kpi{border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:var(--bg)}
.wf-kpi .big{font-size:22px;font-weight:800}
.wf-kpi .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.wf-cols{display:grid;grid-template-columns:1.3fr 1fr;gap:14px}
.wf-card{border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--bg)}
.wf-card h6{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
.wf-row{display:flex;justify-content:space-between;border-bottom:1px dashed var(--line);padding:6px 0;font-size:13px}
.wf-row:last-child{border-bottom:none}
.wf-quick{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.wf-q{border:1px solid var(--line);border-radius:10px;padding:12px;text-align:center;font-size:12.5px;font-weight:600;background:var(--bg)}

/* ---------- Mermaid ---------- */
.mermaid{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:16px;margin:14px 0;overflow-x:auto}
pre.diagram-src{display:none;white-space:pre-wrap;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:14px;font-size:12px}

footer{max-width:var(--maxw);margin:0 auto;padding:26px 24px 60px;color:var(--muted);font-size:13px}
hr.soft{border:none;border-top:1px solid var(--line);margin:20px 0}

@media (max-width:900px){
  .wrap{grid-template-columns:1fr}
  .toc{position:static;max-height:none;order:2;border-top:1px solid var(--line);margin-top:20px}
  .navtoggle{display:inline-flex;margin-top:16px;background:rgba(255,255,255,.12);color:#fff;
    border:1px solid rgba(255,255,255,.25);padding:8px 14px;border-radius:8px;font-weight:600;cursor:pointer}
  .toc[data-collapsed="true"]{display:none}
  .grid2,.grid3,.wf-kpis,.wf-quick,.wf-cols{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="classline class-band">Notional / Pre-Decisional — For GMRE Internal Discovery Use — Classification banner is configurable per program instance</div>

<header class="masthead">
  <div class="mast-inner">
    <p class="eyebrow">Automation Core Team · Product &amp; Architecture Discovery Package</p>
    <h1><span>REDOUBT</span> — GMRE Program Portal Framework</h1>
    <p class="mast-sub">A secure, reusable enterprise platform that becomes the authoritative entry point for proposal and program execution. Like its namesake — a self-contained, defensible stronghold — it is built to hold controlled information (up to <b>CUI</b> and <b>ITAR/export-controlled</b> data) in an authorized enclave, with <b>one framework</b> powering <b>many isolated program instances</b>.</p>
    <div class="mast-meta">
      <span class="chip"><b>Prepared for:</b> Leadership · Program Mgmt · Enterprise Systems · ACT</span>
      <span class="chip"><b>Stack:</b> PHP 8.2 · PostgreSQL · Docker</span>
      <span class="chip"><b>Hosting:</b> On-prem CUI enclave · M365 GCC High back end (CUI//ITAR)</span>
      <span class="chip"><b>SoR:</b> Microsoft 365 / SharePoint via Graph</span>
      <span class="chip"><b>Status:</b> Discovery / Pre-decisional</span>
      <span class="chip"><b>Date:</b> <?= $today ?></span>
    </div>
    <button class="navtoggle" id="navtoggle" type="button" aria-expanded="false" aria-controls="toc">☰ Contents</button>
  </div>
</header>

<div class="wrap">
  <nav class="toc" id="toc" aria-label="Table of contents">
    <h4>Package</h4>
    <a href="#s1">1 · Executive Summary</a>
    <a href="#s2">2 · Problem Definition</a>
    <a href="#s3">3 · Product Vision</a>
    <a href="#s4">4 · Users &amp; Personas</a>
    <a href="#s5">5 · Current vs Future State</a>
    <a href="#s6">6 · Portal Capabilities</a>
    <a href="#s7">7 · Information Architecture</a>
    <a href="#s8">8 · Homepage Concept</a>
    <a href="#s9">9 · Role-Based Experience</a>
    <a href="#s10">10 · User Journeys</a>
    <a href="#s11">11 · Data Classification</a>
    <a href="#s12">12 · Security Architecture</a>
    <a href="#s13">13 · Identity &amp; Access</a>
    <a href="#s14">14 · Application Architecture</a>
    <a href="#s15">15 · SharePoint / M365</a>
    <a href="#s16">16 · Enterprise Integration</a>
    <a href="#s17">17 · Data Model</a>
    <a href="#s18">18 · Administration Model</a>
    <a href="#s19">19 · Program Replication</a>
    <a href="#s20">20 · MVP</a>
    <a href="#s21">21 · Acceptance Criteria</a>
    <a href="#s22">22 · Roadmap</a>
    <a href="#s23">23 · Security / Compliance</a>
    <a href="#s24">24 · Risk Register</a>
    <a href="#s25">25 · Success Metrics</a>
    <a href="#s26">26 · Discovery Questions</a>
    <a href="#s27">27 · Next Actions</a>
    <h4>Annexes</h4>
    <a href="#wf">A · Homepage Wireframe</a>
    <a href="#tenancy">B · Multi-Tenancy Analysis</a>
    <a href="#rbac">C · Authorization Matrix</a>
    <a href="#nfr">D · Non-Functional Reqs</a>
    <a href="#gov">E · Governance / RACI</a>
    <a href="#decisions">F · Decisions Needed</a>
  </nav>

  <main class="content">

  <!-- 1 -->
  <section class="block" id="s1">
    <h2><span class="n">01</span> Executive Summary</h2>
    <h3 class="title">Build one secure portal framework, stand up many program instances</h3>
    <p class="lead">GMRE should build <b>REDOUBT</b>: a role-aware, secure program portal that becomes the single authoritative entry point for a program's prime staff, subcontractors, and (where contractually permitted) the Government customer. It replaces ad-hoc email, calls, and file-sharing with a governed "one-stop shop."</p>
    <p>The decisive architectural choice is <b>platform, not project</b>. REDOUBT is delivered as a reusable framework — a custom <b>PHP web application</b> (Dockerized, <b>self-hosted on-prem</b> on Kubernetes or a hardened Linux host) acting as the <b>presentation and orchestration layer</b>, while <b>Microsoft 365 / SharePoint Online (GCC High) remains the authoritative system of record</b> for documents, surfaced through Microsoft Graph. New programs are created by <b>configuration</b>, not by forking code.</p>
    <div class="callout">
      <span class="k">Design commitment — CUI / ITAR are in scope</span>
      <p style="margin:6px 0 0">REDOUBT is <b>designed to hold controlled information up to CUI and ITAR/export-controlled data</b>. The selected deployment is <b>hybrid</b>: the application is <b>self-hosted on-prem</b> inside GMRE's own CUI boundary, with <b>Microsoft 365 / Azure GCC High</b> providing identity (Entra ID GCC High) and the document system of record (SharePoint Online GCC High). GMRE therefore owns the app-tier boundary (physical, network, FIPS, 800-171/CMMC) and inherits Microsoft's authorization only for the M365 tier. Commercial Render is used only for the non-CUI discovery microsite. The remaining decisions are the on-prem host/runtime specifics and the offboarding SLA — not <em>whether</em> the system may carry CUI. See §11, §12, §23, §26.</p>
    </div>
    <h4 class="sub">What we recommend building first (MVP)</h4>
    <p>A single program instance delivering: Program Home, Announcements, a permission-aware Document Library (Customer/COR + Project + Subcontractor-shared zones fronting SharePoint), Task Orders (metadata + linked docs), Job Requisitions, Program Directory, Quick Links, permission-aware Search, and a <b>self-service Content Administration</b> console — all behind SSO with MFA and full audit logging.</p>
    <h4 class="sub">Why it matters</h4>
    <p>It removes recurring administrative load from the PM, gives subcontractors self-service access to authoritative information, presents a mature program-execution capability to the customer, and — because it is a reusable framework — becomes a genuine <b>proposal differentiator</b> rather than a one-off tool.</p>
  </section>

  <!-- 2 -->
  <section class="block" id="s2">
    <h2><span class="n">02</span> Problem Definition</h2>
    <h3 class="title">Coordination cost scales with every added subcontractor</h3>
    <p>As subcontractors and geographically separated staff grow, coordination is forced through email, phone, Teams meetings, direct PM contact, ad-hoc file shares, and individually distributed announcements. There is <b>no centralized, authoritative, permission-aware location</b> for program information.</p>
    <div class="grid3">
      <div class="tile"><h5>Administrative overload</h5><p>The PM and execution team are a human router for information that should be self-service.</p></div>
      <div class="tile"><h5>Information sprawl</h5><p>Authoritative content lives across disconnected systems, inboxes, and repositories — freshness and version integrity are unmanaged.</p></div>
      <div class="tile"><h5>Disclosure risk</h5><p>Manual sharing across prime/sub/customer boundaries risks cross-subcontractor and cross-program leakage of proprietary, FCI, or CUI data.</p></div>
    </div>
    <p style="margin-top:12px"><b>Root cause (not just symptom):</b> the operating model has no <em>system of engagement</em> layered over the existing <em>systems of record</em>. Adding people multiplies 1:1 communication paths (roughly n²). The fix is a governed hub that collapses those paths to hub-and-spoke.</p>
  </section>

  <!-- 3 -->
  <section class="block" id="s3">
    <h2><span class="n">03</span> Product Vision</h2>
    <h3 class="title">The authoritative, role-aware home base for program execution</h3>
    <p class="lead">Any authorized member — prime, subcontractor, or customer — opens REDOUBT and immediately sees <b>what matters to them</b>: their announcements, their documents, their task orders, their actions, their milestones. Content owners maintain it themselves. Security and isolation are enforced by design. Enterprise Systems can stand up a new program in days, not months.</p>
    <ul>
      <li><b>Secure by design</b> — least privilege, MFA, program &amp; company isolation, full auditability.</li>
      <li><b>Configuration over custom code</b> — new programs and modules toggle on; developers are not in the content path.</li>
      <li><b>Authoritative systems of record</b> — the portal aggregates and orchestrates; it does not become a shadow copy of the truth.</li>
      <li><b>Reusable &amp; modular</b> — one framework, many isolated program instances, one design system.</li>
    </ul>
  </section>

  <!-- 4 -->
  <section class="block" id="s4">
    <h2><span class="n">04</span> Users &amp; Personas</h2>
    <h3 class="title">Three trust zones, many roles</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Persona</th><th>Zone</th><th>Primary goal</th><th>Key pain today</th><th>Sensitivity of access</th></tr></thead>
      <tbody>
        <tr><td>Program Manager / Deputy</td><td>Internal</td><td>Broadcast, coordinate, reduce admin load</td><td>Human router for all info</td><td>Broad within program</td></tr>
        <tr><td>Program Execution Team</td><td>Internal</td><td>Publish status, docs, milestones</td><td>Manual distribution</td><td>Program-internal</td></tr>
        <tr><td>Contracts</td><td>Internal</td><td>Control task orders / contractual docs</td><td>Version &amp; audience control</td><td>Contracts zone (restricted)</td></tr>
        <tr><td>Finance</td><td>Internal</td><td>Expose <em>appropriate</em> financials only</td><td>Over/under-sharing</td><td>Financial zone (restricted)</td></tr>
        <tr><td>Recruiting / HR</td><td>Internal</td><td>Post &amp; manage job requisitions</td><td>Reposting across channels</td><td>Reqs public-ish; PII restricted</td></tr>
        <tr><td>Enterprise Systems</td><td>Internal</td><td>Provision programs, integrations, config</td><td>One-off custom apps</td><td>App admin (no content authority)</td></tr>
        <tr><td>Cybersecurity</td><td>Internal</td><td>Enforce controls, review access, monitor</td><td>Limited visibility</td><td>Security admin / read audit</td></tr>
        <tr><td>Leadership</td><td>Internal</td><td>Program health at a glance</td><td>Manual status roll-ups</td><td>Dashboards, read-mostly</td></tr>
        <tr><td>Subcontractor PM</td><td>External</td><td>Task orders, program info for their firm</td><td>Waiting on prime emails</td><td>Own company + shared only</td></tr>
        <tr><td>Subcontractor Employee</td><td>External</td><td>Find latest approved documents</td><td>"Which version is current?"</td><td>Own company + shared only</td></tr>
        <tr><td>Subcontractor Admin</td><td>External</td><td>Manage their firm's roster (delegated)</td><td>Manual onboard/offboard</td><td>Delegated, own company only</td></tr>
        <tr><td>COR / Gov Program Office</td><td>Customer</td><td>Retrieve approved customer-facing docs</td><td>Email attachments</td><td>Customer-shared zone only</td></tr>
      </tbody>
    </table></div>
    <div class="callout"><span class="k">Challenge to the request</span><p style="margin:6px 0 0">"Potential teaming partners" (pre-award) is a distinct, higher-risk persona than an awarded subcontractor. Recommend <b>excluding pre-award teaming partners from MVP</b> — proposal-phase NDAs and information rules differ materially. Handle in a separate, tightly scoped "Capture/Teaming" instance later.</p></div>
  </section>

  <!-- 5 -->
  <section class="block" id="s5">
    <h2><span class="n">05</span> Current-State vs Future-State</h2>
    <h3 class="title">From n² communication to hub-and-spoke</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Dimension</th><th>Current state</th><th>Future state (REDOUBT)</th></tr></thead>
      <tbody>
        <tr><td>Announcements</td><td>Individually emailed / repeated</td><td>Published once, role-targeted, scheduled/expiring, read-tracked</td></tr>
        <tr><td>Documents</td><td>Ad-hoc shares; version ambiguity</td><td>Permission-aware library over SharePoint; single authoritative version</td></tr>
        <tr><td>Task orders</td><td>PM-mediated email</td><td>Self-service, scoped to company, linked to authoritative docs</td></tr>
        <tr><td>Onboarding</td><td>Manual, multi-day, per person</td><td>Sponsored request → approval → provision workflow (Phase 2)</td></tr>
        <tr><td>Access control</td><td>Implicit, inconsistent</td><td>Explicit RBAC, least privilege, audited</td></tr>
        <tr><td>New program stand-up</td><td>Custom effort per contract</td><td>Configuration-driven instance in days</td></tr>
        <tr><td>Customer perception</td><td>Email-driven</td><td>Professional, transparent program-execution capability</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 6 -->
  <section class="block" id="s6">
    <h2><span class="n">06</span> Proposed Portal Capabilities</h2>
    <h3 class="title">Modules, phased honestly — not everything is MVP</h3>
    <p>Legend: <span class="badge b-mvp">MVP</span> <span class="badge b-p2">Phase 2</span> <span class="badge b-p3">Phase 3</span> <span class="badge b-fut">Future</span></p>
    <div class="tablewrap"><table>
      <thead><tr><th>Module</th><th>Phase</th><th>What it does</th><th>System of record</th></tr></thead>
      <tbody>
        <tr><td>Home (role-aware)</td><td><span class="badge b-mvp">MVP</span></td><td>Personalized landing: my actions, announcements, new docs, milestones</td><td>Portal (aggregation)</td></tr>
        <tr><td>Program Overview</td><td><span class="badge b-mvp">MVP</span></td><td>Static-ish program facts, contract summary, POCs</td><td>Portal</td></tr>
        <tr><td>Announcements</td><td><span class="badge b-mvp">MVP</span></td><td>Role-targeted, scheduled, expiring, priority banner</td><td>Portal</td></tr>
        <tr><td>Document Library</td><td><span class="badge b-mvp">MVP</span></td><td>Permission-aware zones (Project / Customer / Sub-shared)</td><td>SharePoint (Graph)</td></tr>
        <tr><td>Customer / COR Library</td><td><span class="badge b-mvp">MVP</span></td><td>Approved customer-facing docs, separate audience</td><td>SharePoint (Graph)</td></tr>
        <tr><td>Task Orders</td><td><span class="badge b-mvp">MVP</span></td><td>TO metadata, status, company scoping, linked docs</td><td>Portal + SharePoint/Contracts</td></tr>
        <tr><td>Job Requisitions</td><td><span class="badge b-mvp">MVP</span></td><td>Post/manage reqs; link to ATS apply</td><td>ATS (link) / Portal (summary)</td></tr>
        <tr><td>Program Directory</td><td><span class="badge b-mvp">MVP</span></td><td>Contacts by org/role; company-scoped visibility</td><td>Portal / Entra</td></tr>
        <tr><td>Quick Links</td><td><span class="badge b-mvp">MVP</span></td><td>Curated links to enterprise systems</td><td>Portal</td></tr>
        <tr><td>Global Search</td><td><span class="badge b-mvp">MVP</span></td><td>Permission-trimmed across portal + Graph</td><td>Portal index + Graph Search</td></tr>
        <tr><td>Content Administration</td><td><span class="badge b-mvp">MVP</span></td><td>Self-service authoring w/o developer/JIRA</td><td>Portal</td></tr>
        <tr><td>Calendar / Milestones</td><td><span class="badge b-p2">Phase 2</span></td><td>Key dates, CDRL/deliverable milestones</td><td>Portal / M365 Calendar</td></tr>
        <tr><td>Forms / Requests</td><td><span class="badge b-p2">Phase 2</span></td><td>Access requests, general intake</td><td>Portal / Power Automate</td></tr>
        <tr><td>Onboarding</td><td><span class="badge b-p2">Phase 2</span></td><td>Guided sub/customer onboarding + provisioning</td><td>Portal + Entra</td></tr>
        <tr><td>Notifications</td><td><span class="badge b-p2">Phase 2</span></td><td>In-portal + email/Teams digests</td><td>Portal</td></tr>
        <tr><td>Financial views</td><td><span class="badge b-p2">Phase 2</span></td><td>Curated, role-gated financial exposure</td><td>Finance system (link/embed)</td></tr>
        <tr><td>Reporting / Dashboards</td><td><span class="badge b-p3">Phase 3</span></td><td>Program health, adoption, deliverable tracking</td><td>Portal / BI</td></tr>
        <tr><td>FAQ / Knowledge Base</td><td><span class="badge b-p3">Phase 3</span></td><td>Curated answers; feeds search</td><td>Portal</td></tr>
        <tr><td>AI program assistant</td><td><span class="badge b-fut">Future</span></td><td>Permission-aware Q&amp;A / summarization</td><td>Portal + LLM (self-host in enclave)</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 7 -->
  <section class="block" id="s7">
    <h2><span class="n">07</span> Information Architecture</h2>
    <h3 class="title">Shallow, role-aware navigation</h3>
    <pre class="diagram-src" style="display:block">REDOUBT (Program Instance)
├─ Home ...................... role-aware dashboard (default landing)
├─ Program
│   ├─ Overview
│   ├─ Announcements
│   └─ Calendar & Milestones            [P2]
├─ Documents
│   ├─ Project Documents                (SharePoint zone)
│   ├─ Subcontractor Shared             (SharePoint zone)
│   └─ My Company                       (company-scoped zone)
├─ Customer / COR ............ customer-shared library (audience-gated)
├─ Task Orders .............. company-scoped list + linked docs
├─ Jobs ..................... requisitions (+ ATS apply links)
├─ Directory ................ contacts, company-scoped visibility
├─ Resources
│   ├─ Quick Links
│   ├─ Onboarding                       [P2]
│   ├─ Forms & Requests                 [P2]
│   └─ FAQ / Knowledge Base             [P3]
├─ Search ................... global, permission-trimmed
└─ Admin (role-gated)
    ├─ Content Administration           (Content Manager)
    ├─ Program Configuration            (Program Admin)
    ├─ Access & Roles                   (Security Admin)
    └─ Audit & Logs                     (Security/Cyber, read)</pre>
    <p class="muted">Principle: any authorized user reaches any authorized item in ≤3 clicks; unauthorized items are never rendered, never returned by search, and never enumerable.</p>
  </section>

  <!-- 8 -->
  <section class="block" id="s8">
    <h2><span class="n">08</span> Homepage Concept</h2>
    <h3 class="title">Contextual "My Program," not a link dump</h3>
    <p>The home page answers three questions in the first screen: <b>What needs my attention? What changed? Is the program healthy?</b> It is composed of role-aware zones; a subcontractor employee and a PM see the same layout with different, permission-scoped content.</p>
    <div class="grid2">
      <div class="tile"><h5>1 · Priority banner</h5><p>At most one active program-status/critical announcement. Dismissible; re-surfaces if updated. Why: urgent info must not compete with chrome.</p></div>
      <div class="tile"><h5>2 · My Actions</h5><p>Required actions, expiring documents, pending acknowledgements. Why: converts a passive portal into a task surface.</p></div>
      <div class="tile"><h5>3 · What's New</h5><p>New/updated documents and announcements scoped to the user. Why: kills "did you see the latest?" emails.</p></div>
      <div class="tile"><h5>4 · Upcoming Milestones</h5><p>Next key dates / deliverables. Why: shared situational awareness across prime + subs.</p></div>
      <div class="tile"><h5>5 · Quick Access</h5><p>Role-aware cards to the modules the user actually uses. Why: speed for occasional users.</p></div>
      <div class="tile"><h5>6 · Program status &amp; directory</h5><p>Health indicators (leadership) + fast POC lookup. Why: reduces "who do I ask?" churn.</p></div>
    </div>
    <p style="margin-top:12px">See the full <a href="#wf">home page wireframe (Annex A)</a>.</p>
  </section>

  <!-- 9 -->
  <section class="block" id="s9">
    <h2><span class="n">09</span> Role-Based Experience</h2>
    <h3 class="title">Same frame, different content — enforced server-side</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>User</th><th>Sees on Home</th><th>Cannot see</th></tr></thead>
      <tbody>
        <tr><td>Program Manager</td><td>Program health, all-program announcements, publish shortcuts, action roll-up</td><td>Nothing hidden within program (except other companies' private HR/PII)</td></tr>
        <tr><td>Subcontractor Employee (Co. A)</td><td>Co. A + shared announcements/docs, their task orders, their actions</td><td>Finance zone, Contracts zone, Co. B content, admin surfaces</td></tr>
        <tr><td>Subcontractor Admin (Co. A)</td><td>Above + delegated roster management for Co. A</td><td>Other companies' rosters, program config</td></tr>
        <tr><td>COR / Customer</td><td>Customer-shared library, approved status items, customer announcements</td><td>Internal, subcontractor, finance, contracts, recruiting content</td></tr>
        <tr><td>Finance</td><td>Financial zone + program basics</td><td>Recruiting PII; other-program data</td></tr>
        <tr><td>Content Manager</td><td>Normal home + "edit" affordances for content they own</td><td>Roles/security config, program config, code</td></tr>
      </tbody>
    </table></div>
    <div class="callout warn"><span class="k">Non-negotiable</span><p style="margin:6px 0 0">Role trimming is enforced on the <b>server and at the data query</b>, never by hiding UI. UI hiding is cosmetic; the API must independently authorize every request and every search result.</p></div>
  </section>

  <!-- 10 -->
  <section class="block" id="s10">
    <h2><span class="n">10</span> User Journeys</h2>
    <h3 class="title">End-to-end workflows with audit + failure modes</h3>

    <h4 class="sub">A · PM publishes a critical announcement</h4>
    <p><b>Trigger:</b> program event. <b>Actor:</b> PM/Content Mgr. <b>Steps:</b> Compose → select audience (roles/companies) → set priority + expiry → preview → publish. <b>Systems:</b> Portal DB, notification service (P2). <b>Permissions:</b> <code>announcement.create/publish</code>. <b>Automation:</b> scheduled publish, auto-expire, digest. <b>Audit:</b> create/publish, audience snapshot, actor, timestamp. <b>Failure modes:</b> wrong audience (mitigate: audience preview + confirm), never-expiring banners (mitigate: mandatory expiry).</p>

    <h4 class="sub">B · Subcontractor employee finds the latest approved document</h4>
    <p><b>Trigger:</b> needs current spec. <b>Actor:</b> Sub employee. <b>Steps:</b> Search or Documents → permission-trimmed results → open current version from SharePoint. <b>Systems:</b> Portal, Graph/SharePoint. <b>Permissions:</b> zone + company scope. <b>Audit:</b> access log (who/what/when). <b>Failure modes:</b> stale copy elsewhere (mitigate: portal shows authoritative version + "last updated"); over-broad results (mitigate: trim at query, verify at fetch).</p>

    <h4 class="sub">C · Recruiter posts a job requisition</h4>
    <p><b>Trigger:</b> new opening. <b>Steps:</b> Create req → audience (internal/sub/public-to-program) → link ATS apply URL → publish. <b>Permissions:</b> <code>req.create/publish</code>. <b>Automation:</b> auto-expire on fill/date. <b>Audit:</b> publish + edits. <b>Failure modes:</b> PII in free text (mitigate: field validation + guidance).</p>

    <h4 class="sub">D · COR retrieves an approved customer document</h4>
    <p><b>Trigger:</b> customer need. <b>Steps:</b> COR signs in (B2B + MFA + Conditional Access) → Customer/COR Library → download. <b>Permissions:</b> customer-shared zone only. <b>Audit:</b> external-access log (heightened retention). <b>Failure modes:</b> accidental exposure of non-approved doc (mitigate: explicit "customer-approved" gate; nothing reaches this zone without approval step).</p>

    <h4 class="sub">E · New subcontractor onboarding <span class="badge b-p2">Phase 2</span></h4>
    <p><b>Steps:</b> Prime sponsor requests access → approval (PM + Security) → Entra B2B invite + role/company/program assignment → time-boxed to contract → welcome. <b>Audit:</b> full lifecycle. <b>Failure modes:</b> over-provisioning (mitigate: templated least-privilege role packs).</p>

    <h4 class="sub">F · Departing subcontractor loses access immediately</h4>
    <p><b>Trigger:</b> offboarding/expiry. <b>Steps:</b> Sub Admin or PM deactivates → Entra access removed → sessions revoked → SharePoint sharing recalculated. <b>Automation:</b> contract-end auto-expiry; access reviews. <b>Audit:</b> revocation event. <b>Failure modes:</b> orphaned guest accounts (mitigate: expiry + periodic access review — a top CMMC finding area).</p>
  </section>

  <!-- 11 -->
  <section class="block" id="s11">
    <h2><span class="n">11</span> Data Classification &amp; Segmentation</h2>
    <h3 class="title">Prevent cross-company and cross-program disclosure by design</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Class</th><th>Example</th><th>Default audience</th><th>Where it lives</th></tr></thead>
      <tbody>
        <tr><td>Public / External-general</td><td>Program overview blurb</td><td>All authenticated</td><td>Portal</td></tr>
        <tr><td>Internal-company</td><td>GMRE-only notices</td><td>Internal roles</td><td>Portal / SharePoint</td></tr>
        <tr><td>Program-internal</td><td>Exec team working docs</td><td>Internal program members</td><td>SharePoint (program zone)</td></tr>
        <tr><td>Subcontractor-shared</td><td>Approved specs for all subs</td><td>All subs + internal</td><td>SharePoint (shared zone)</td></tr>
        <tr><td>Individual-subcontractor</td><td>Co. A's private docs</td><td>Co. A + internal owners</td><td>SharePoint (per-company library)</td></tr>
        <tr><td>Contracts</td><td>Task order mods</td><td>Contracts + PM</td><td>SharePoint (contracts zone)</td></tr>
        <tr><td>Financial</td><td>Burn, invoices</td><td>Finance + PM (curated)</td><td>Finance SoR (link/embed)</td></tr>
        <tr><td>Customer-shared / Gov</td><td>COR deliverables</td><td>Customer + approvers</td><td>SharePoint (customer zone)</td></tr>
        <tr><td>Proprietary / FCI</td><td>Non-public contract info</td><td>Need-to-know</td><td>Controlled zones + labels</td></tr>
        <tr><td>CUI</td><td>Controlled technical data</td><td>Need-to-know, marked</td><td><b>GCC High</b> enclave + Purview CUI labels</td></tr>
        <tr><td>Export / ITAR / EAR</td><td>Technical data</td><td>US-person, license-gated</td><td><b>GCC High</b> enclave + <b>US-person access gate</b> + labels</td></tr>
        <tr><td>PII</td><td>Recruiting data</td><td>HR need-to-know</td><td>Minimized; HR SoR</td></tr>
      </tbody>
    </table></div>
    <div class="callout warn"><span class="k">Isolation model</span><p style="margin:6px 0 0">Isolate with <b>per-company document libraries + Entra security groups</b>, <b>not</b> per-item SharePoint permissions. Item-level permission sprawl is a known SharePoint anti-pattern that breaks at scale and causes accidental disclosure. Program isolation = separate SharePoint <b>site collection per program</b>; company isolation = separate <b>library + group per company</b>.</p></div>
  </section>

  <!-- 12 -->
  <section class="block" id="s12">
    <h2><span class="n">12</span> Security Architecture</h2>
    <h3 class="title">Portal is a gate, not a vault</h3>
    <p>REDOUBT is a <b>presentation/access layer</b>. Authoritative data stays in approved repositories (SharePoint, Finance, ATS, Contracts). The portal stores only: config, content it owns (announcements, links, FAQ), metadata, references, and audit logs.</p>
    <div class="grid2">
      <div class="tile"><h5>Data handling taxonomy</h5><p><b>Portal content</b> (owned) · <b>Authoritative SoR</b> (never duplicated) · <b>Linked</b> (deep link, auth at source) · <b>Embedded</b> (Graph render, live perms) · <b>Replicated</b> (avoid; if unavoidable, cache metadata only, never CUI bytes).</p></div>
      <div class="tile"><h5>Control themes (800-171 / CMMC)</h5><p>Access control, identification &amp; auth (MFA), audit &amp; accountability, config mgmt, media/data protection, system &amp; comms protection, incident response hooks, personnel/offboarding.</p></div>
    </div>
    <ul>
      <li><b>AuthN:</b> Entra ID OIDC SSO; MFA enforced; Conditional Access (device/geo/risk) for external identities.</li>
      <li><b>AuthZ:</b> centralized policy engine; every request authorized server-side against (program × company × role × zone).</li>
      <li><b>Isolation:</b> program instance boundary + company boundary enforced in every query.</li>
      <li><b>Export control (ITAR/EAR):</b> US-person attribute verified at provisioning and enforced as an access gate on export-controlled zones; license/agreement scoping where applicable; nationality never inferred client-side.</li>
      <li><b>Compliance boundary:</b> the app runs in GMRE's <b>on-prem CUI enclave</b> (customer-owned 800-171/CMMC boundary) using <b>FIPS 140-validated</b> crypto modules (OpenSSL FIPS provider / OS-level); identity &amp; documents in M365 GCC High. The app adds no non-approved cryptography.</li>
      <li><b>Audit:</b> immutable, append-only audit log of access, admin, publish, provision, revoke; heightened retention for external and export-controlled access.</li>
      <li><b>Secrets:</b> from environment / cloud secrets manager (Key Vault / Secrets Manager) — never in source. Mirrors repo rule "never commit .env".</li>
      <li><b>Transport/session:</b> TLS only, HSTS, strict CSP + nonce, CSRF tokens on all writes, short sessions, revoke-on-offboard.</li>
    </ul>
  </section>

  <!-- 13 -->
  <section class="block" id="s13">
    <h2><span class="n">13</span> Identity &amp; Access Architecture</h2>
    <h3 class="title">Sponsored lifecycle for external identities</h3>
    <pre class="diagram-src" style="display:block">REQUEST → APPROVAL → PROVISION → ACCESS → PERIODIC REVIEW → MODIFY → REMOVE
  │           │           │          │            │              │        │
 sponsor    PM +        Entra     scoped:      access review   role /   revoke +
 (prime)    Security    B2B/OIDC  program ×    (quarterly)     scope    session kill
                        + role     company ×                   change   + sharing
                        pack       zone                                 recompute</pre>
    <ul>
      <li><b>Internal:</b> existing Entra employees via SSO; roles from Entra groups mapped to portal roles.</li>
      <li><b>External subs:</b> Entra <b>B2B guests</b>, MFA + Conditional Access, contract-bounded expiry, delegated company admin.</li>
      <li><b>Customer/Gov:</b> B2B guests scoped to customer-shared zone only; heightened logging; per-contract approval.</li>
    </ul>
    <p>Role assignment is <b>program-scoped and company-scoped</b>: a user is (identity) + (program membership) + (company membership) + (role). The same person can hold different roles in different programs. See the <a href="#rbac">authorization matrix (Annex C)</a>.</p>
  </section>

  <!-- 14 -->
  <section class="block" id="s14">
    <h2><span class="n">14</span> Application Architecture</h2>
    <h3 class="title">On-prem PHP app in a CUI boundary, M365 GCC High as system of record</h3>
    <div class="mermaid">
flowchart TB
  subgraph Client["Browser (Prime · Sub · Customer)"]
    UI["REDOUBT UI<br/>role-aware, responsive"]
  end
  subgraph OnPrem["On-prem enclave — customer CUI boundary (NIST 800-171 / CMMC)"]
    LB["TLS reverse proxy<br/>HSTS · CSP · WAF"]
    subgraph App["PHP Application (PHP 8.2, PSR-4, Docker/K8s)"]
      FC["Front controller + Router"]
      AUTHZ["AuthZ policy engine<br/>program × company × role × zone · US-person gate"]
      MOD["Modules: announcements, docs,<br/>task orders, jobs, directory, admin"]
      SVC["Graph client · Search · Notifications"]
      AUD["Audit logger (append-only)"]
    end
    PG[("PostgreSQL<br/>config · content · metadata · audit")]
    CACHE[("Cache<br/>sessions · Graph tokens")]
  end
  subgraph M365["Microsoft 365 GCC High (cloud SoR)"]
    ENTRA["Entra ID GCC High<br/>login.microsoftonline.us · MFA · B2B · CA"]
    SP["SharePoint Online GCC High<br/>*.sharepoint.us (per-program site)"]
    GRAPH["Microsoft Graph<br/>graph.microsoft.us"]
  end
  subgraph Ext["Enterprise systems"]
    ATS["ATS / Jobs"]
    FIN["Finance"]
    CON["Contracts / Task Orders"]
  end
  UI --> LB --> FC --> AUTHZ --> MOD --> SVC
  MOD --> PG
  AUTHZ -->|OIDC| ENTRA
  SVC -->|HTTPS: ExpressRoute/Gov internet| GRAPH --> SP
  SVC -. link/embed .-> ATS
  SVC -. link/embed .-> FIN
  SVC -. link/metadata .-> CON
  MOD --> AUD --> PG
  SVC --> CACHE
    </div>
    <h4 class="sub">Why this stack</h4>
    <div class="tablewrap"><table>
      <thead><tr><th>Choice</th><th>Why / problem solved</th><th>Complexity cost</th><th>Alternatives</th></tr></thead>
      <tbody>
        <tr><td>PHP 8.2 app (mandated)</td><td>Team standard (AEGIS/CITADEL family); fast to build; strong web ecosystem</td><td>Must self-manage AuthZ discipline &amp; typing</td><td>.NET, Node — rejected: not the mandated stack</td></tr>
        <tr><td>PostgreSQL</td><td>Reliable relational store for config/metadata/audit; JSONB for flexible module config</td><td>Ops/backup</td><td>MySQL (fine); SQLite (dev only)</td></tr>
        <tr><td>Docker, self-hosted on-prem (K8s or hardened Linux)</td><td>Runs inside GMRE's CUI enclave; portable, reproducible; identical image across dev→prod</td><td>Customer owns runtime/patching &amp; boundary</td><td>Azure Gov App Service (rejected: not on-prem); Render (non-CUI only)</td></tr>
        <tr><td>M365/SharePoint via Graph</td><td>Keeps authoritative docs in a compliant, audited SoR; avoids shadow copies</td><td>Graph auth, throttling, perms mapping</td><td>Store docs in portal — rejected: reinvents DMS + compliance</td></tr>
        <tr><td>Entra ID (OIDC/B2B)</td><td>Enterprise SSO, MFA, external identity, Conditional Access built-in</td><td>Guest lifecycle governance</td><td>Local accounts — rejected: weak, non-compliant</td></tr>
      </tbody>
    </table></div>
    <div class="callout"><span class="k">Deployment boundary (selected)</span><p style="margin:6px 0 0"><b>Hybrid: the REDOUBT app is self-hosted on-prem</b> inside GMRE's own CUI boundary (Docker/Kubernetes on customer infrastructure), while <b>identity and documents live in Microsoft 365 / Azure GCC High</b> (Entra ID GCC High + SharePoint Online GCC High), reached over GCC High Graph endpoints. Consequence: GMRE <b>owns the app-tier authorization boundary</b> (physical, network, FIPS, 800-171/CMMC) and inherits Microsoft's authorization only for the M365 tier. Commercial Render is used only for the non-CUI discovery microsite, never for controlled data.</p></div>
  </section>

  <!-- 15 -->
  <section class="block" id="s15">
    <h2><span class="n">15</span> SharePoint / Microsoft 365 Integration</h2>
    <h3 class="title">Portal frontend → Graph → SharePoint (recommended)</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Option</th><th>Pros</th><th>Cons</th><th>Verdict</th></tr></thead>
      <tbody>
        <tr><td>Native SharePoint portal</td><td>Fast, built-in perms/versioning/search/audit, self-service</td><td>Legacy look; hard to reach the "modern product" bar; limited external UX control</td><td>Fallback, not the vision</td></tr>
        <tr><td><b>PHP portal → Graph → SharePoint</b></td><td>Modern UX + SharePoint as compliant DMS; live permissions; no doc duplication</td><td>Graph auth/throttling; must map perms carefully</td><td><b>Recommended</b></td></tr>
        <tr><td>Custom app + custom doc store</td><td>Total control</td><td>Rebuilds DMS, versioning, audit, e-discovery, compliance</td><td>Rejected</td></tr>
      </tbody>
    </table></div>
    <p><b>Permissions:</b> mirror portal (program×company×zone) onto SharePoint site/library scopes + Entra groups. <b>Versioning/approval:</b> use SharePoint native; portal shows current + history. <b>External sharing:</b> governed by M365 external sharing policy + B2B, never anonymous links. <b>Search:</b> Graph Search, permission-trimmed by the platform. <b>Auditability:</b> M365 unified audit log + portal audit — two-sided record.</p>
  </section>

  <!-- 16 -->
  <section class="block" id="s16">
    <h2><span class="n">16</span> Enterprise Integration Model</h2>
    <h3 class="title">Aggregate and orchestrate — don't duplicate the truth</h3>
    <div class="mermaid">
flowchart LR
  P["REDOUBT<br/>(orchestration layer)"]
  SP["SharePoint / M365<br/>SoR: documents"]
  EN["Entra ID<br/>SoR: identity"]
  AT["ATS<br/>SoR: jobs/candidates"]
  FI["Finance<br/>SoR: financials"]
  CO["Contracts repo<br/>SoR: task orders"]
  TE["Teams<br/>notifications"]
  P -->|Graph read/write docs| SP
  P -->|OIDC / group claims| EN
  P -->|link + summary| AT
  P -->|link / embed curated| FI
  P -->|link + metadata| CO
  P -->|webhook / connector| TE
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th>Capability</th><th>System of record</th><th>Portal function</th><th>Integration</th><th>Data owner</th><th>Security note</th></tr></thead>
      <tbody>
        <tr><td>Documents</td><td>SharePoint</td><td>Browse/search/open</td><td>Graph</td><td>Program/Contracts</td><td>Live perms, no copy</td></tr>
        <tr><td>Identity</td><td>Entra ID</td><td>SSO, roles</td><td>OIDC</td><td>Enterprise Systems</td><td>MFA, Conditional Access</td></tr>
        <tr><td>Jobs</td><td>ATS</td><td>Post summary + apply link</td><td>Link / API</td><td>Recruiting</td><td>PII stays in ATS</td></tr>
        <tr><td>Financials</td><td>Finance system</td><td>Curated view/link</td><td>Link / embed</td><td>Finance</td><td>Role-gated, minimized</td></tr>
        <tr><td>Task orders</td><td>Contracts repo</td><td>Metadata + linked docs</td><td>Link / metadata</td><td>Contracts</td><td>Company-scoped</td></tr>
        <tr><td>Notifications</td><td>Portal</td><td>In-portal + digests</td><td>Teams/email</td><td>Program</td><td>No sensitive payload in email</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 17 -->
  <section class="block" id="s17">
    <h2><span class="n">17</span> Data Model (conceptual)</h2>
    <h3 class="title">Program-scoped, company-scoped, audited</h3>
    <pre class="diagram-src" style="display:block">Program (id, name, customer, contract#, theme, logo, enabled_modules[], status)
  └─< ProgramMembership (program_id, user_id, role_id, company_id, expires_at)
Company (id, name, type[prime|sub|customer])
  └─< CompanyMembership (company_id, user_id, is_company_admin)
User (id, entra_oid, display_name, email, kind[internal|external|customer], status)
Role (id, key, name, is_external_allowed) ─< RolePermission (role_id, permission_key)
TaskOrder (id, program_id, number, title, status, company_scope[], sp_link)
Document* (metadata mirror: id, program_id, zone, company_scope[], sp_item_id, updated_at)
Announcement (id, program_id, title, body, audience[], priority, publish_at, expire_at)
JobRequisition (id, program_id, title, audience[], ats_url, status, expire_at)
ProgramContact (id, program_id, user_id|freeform, role_label, company_id, visibility[])
Milestone (id, program_id, title, due_date, type[CDRL|event|deliverable])
QuickLink (id, program_id, label, url, audience[])
Faq (id, program_id, question, answer, audience[])
Notification (id, user_id, type, ref, read_at)
AuditEvent (id, program_id, actor_id, action, target, ip, at)  -- append-only
ProgramConfig (program_id, key, json_value)  -- module + integration config
* Documents themselves live in SharePoint; portal holds metadata/reference only.</pre>
    <p class="muted">Key relationships: every content row carries <code>program_id</code> and an <code>audience[]</code>/<code>company_scope[]</code>; every query filters by the caller's (program, company, role) before returning rows.</p>
  </section>

  <!-- 18 -->
  <section class="block" id="s18">
    <h2><span class="n">18</span> Administration Model</h2>
    <h3 class="title">Five separated planes — content admins are not app admins</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Plane</th><th>Who</th><th>Can do</th><th>Cannot do</th></tr></thead>
      <tbody>
        <tr><td>1 · Content Administration</td><td>Program Content Manager (execution team)</td><td>Announcements, docs, links, reqs, contacts, FAQ, milestones, banner</td><td>Change roles, config, integrations, code</td></tr>
        <tr><td>2 · Application Administration</td><td>Program Admin</td><td>Enable modules, program config, branding, integration settings</td><td>Change security policy or code; grant themselves security admin</td></tr>
        <tr><td>3 · Security Administration</td><td>Cyber / IAM</td><td>Roles, access reviews, external approvals, audit</td><td>Author content; deploy code</td></tr>
        <tr><td>4 · System Configuration</td><td>Enterprise Systems</td><td>Provision programs, platform config, secrets, integrations</td><td>Own program content decisions</td></tr>
        <tr><td>5 · Software Development</td><td>Dev/DevSecOps</td><td>Code, CI/CD, releases</td><td>Runtime content or per-program access grants</td></tr>
      </tbody>
    </table></div>
    <p><b>This directly satisfies the critical business requirement:</b> a Content Manager changes announcements, posts jobs, uploads docs, and updates contacts through the admin UI — <b>no developer, no JIRA, no global admin rights.</b></p>
  </section>

  <!-- 19 -->
  <section class="block" id="s19">
    <h2><span class="n">19</span> Program Replication Strategy</h2>
    <h3 class="title">"Create New Program" as configuration</h3>
    <p>Enterprise Systems runs a guided <b>Create Program</b> flow that writes a <code>Program</code> row + <code>ProgramConfig</code>, provisions the SharePoint site collection from a <b>site template</b>, creates Entra security groups, seeds default roles, and applies branding (name, logo, theme color). No code fork.</p>
    <pre class="diagram-src" style="display:block">CREATE NEW PROGRAM  (Enterprise Systems)
  Program Name · Customer · Contract# · PM
  Logo · Theme color · Enabled modules[]
  Security groups (auto) · SharePoint site (from template)
  Customer library (on/off) · Finance integration (on/off)
  Task order integration · Recruiting integration
  External access policy · Customer access policy
        → provision → validate → publish instance</pre>
    <p>How close can we get? <b>Very close</b> for portal config, branding, modules, roles, and content zones (fully config-driven). SharePoint provisioning is templated (PnP/site design). The parts that stay human-approved by design: external identity approvals and integration credentials (security control, not a limitation).</p>
  </section>

  <!-- 20 -->
  <section class="block" id="s20">
    <h2><span class="n">20</span> MVP</h2>
    <h3 class="title">Smallest product that removes real PM load — one program</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Capability</th><th>Business value</th><th>Primary user</th><th>Complexity</th><th>Key dependency</th></tr></thead>
      <tbody>
        <tr><td>SSO + MFA + RBAC</td><td>Secure, role-aware access</td><td>All</td><td>Med</td><td>Entra config</td></tr>
        <tr><td>Role-aware Home</td><td>Attention + what's new</td><td>All</td><td>Med</td><td>Modules below</td></tr>
        <tr><td>Announcements</td><td>Kills repeated emails</td><td>PM/Content Mgr</td><td>Low</td><td>—</td></tr>
        <tr><td>Document Library (Graph)</td><td>Authoritative, current docs</td><td>Subs/Internal</td><td>High</td><td>SharePoint + Graph</td></tr>
        <tr><td>Customer/COR Library</td><td>Professional customer channel</td><td>COR</td><td>Med</td><td>Approval gate</td></tr>
        <tr><td>Task Orders</td><td>Self-service TO info</td><td>Sub PM</td><td>Med</td><td>Contracts source</td></tr>
        <tr><td>Job Requisitions</td><td>Centralized reqs</td><td>Recruiting</td><td>Low</td><td>ATS link</td></tr>
        <tr><td>Directory + Quick Links</td><td>Fewer "who/where" asks</td><td>All</td><td>Low</td><td>—</td></tr>
        <tr><td>Permission-aware Search</td><td>Find without exposure</td><td>All</td><td>High</td><td>Graph Search + trim</td></tr>
        <tr><td>Content Administration</td><td>No-developer updates</td><td>Content Mgr</td><td>Med</td><td>RBAC</td></tr>
      </tbody>
    </table></div>
    <div class="callout"><span class="k">Challenge to the assumed MVP</span><p style="margin:6px 0 0"><b>Financials should NOT be MVP</b> — highest sensitivity, lowest urgency for the stated pain; defer to Phase 2 with a curated, role-gated view. <b>Onboarding automation is Phase 2</b> — MVP can use manual (but audited) provisioning. This keeps MVP shippable without weakening security.</p></div>
  </section>

  <!-- 21 -->
  <section class="block" id="s21">
    <h2><span class="n">21</span> Acceptance Criteria (MVP "done")</h2>
    <ul>
      <li>A user authenticates via Entra SSO with MFA; unauthenticated access is impossible.</li>
      <li>A subcontractor from Company A <b>cannot</b> view Company B content or Finance/Contracts zones via UI, direct URL, API, or search.</li>
      <li>A Content Manager creates, schedules, and expires an announcement and uploads a document <b>without developer involvement</b>.</li>
      <li>Documents open the <b>current authoritative version</b> from SharePoint; no duplicate store exists.</li>
      <li>Every access, publish, and admin action produces an immutable audit record.</li>
      <li>A COR sees only the customer-shared library.</li>
      <li>Search returns only items the caller is authorized to see (verified with a negative test).</li>
      <li>Deploys via Docker to the <b>on-prem CUI enclave</b> (K8s or hardened Linux); connects to M365 GCC High over Graph (<code>graph.microsoft.us</code>); passes health check; secrets sourced from a secret manager/environment, none in source. (Commercial Render is used only for the non-CUI discovery microsite.)</li>
      <li>Offboarding a user revokes access and kills active sessions promptly.</li>
    </ul>
  </section>

  <!-- 22 -->
  <section class="block" id="s22">
    <h2><span class="n">22</span> Product Roadmap</h2>
    <h3 class="title">Platform first, capability incrementally</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Phase</th><th>Theme</th><th>Highlights</th></tr></thead>
      <tbody>
        <tr><td>Phase 0</td><td>Discovery / Architecture</td><td>This package; blocking decisions; pilot program selected</td></tr>
        <tr><td>Phase 1</td><td>MVP (single program)</td><td>Auth, home, announcements, docs, customer library, task orders, jobs, directory, search, content admin</td></tr>
        <tr><td>Phase 2</td><td>Workflow automation</td><td>Onboarding/offboarding, notifications, forms/requests, calendar/milestones, curated financials</td></tr>
        <tr><td>Phase 3</td><td>Replication platform</td><td>Create-Program flow, site templating, program theming, dashboards, FAQ/KB</td></tr>
        <tr><td>Phase 4</td><td>Advanced integrations</td><td>Deeper Contracts/Finance/CDRL tracking, deliverable management</td></tr>
        <tr><td>Phase 5</td><td>Analytics / AI</td><td>Program health analytics; permission-aware AI assistant (enclave-hosted LLM)</td></tr>
      </tbody>
    </table></div>
    <div class="callout warn"><span class="k">On AI</span><p style="margin:6px 0 0">AI adds genuine value only for <b>permission-aware search/Q&amp;A and document summarization</b>, and only if the model runs inside the compliance boundary (self-hosted, e.g. Ollama in-enclave) with per-query authorization. AI for novelty (chatbots on public content) adds risk without value here — deferred/declined.</p></div>
  </section>

  <!-- 23 -->
  <section class="block" id="s23">
    <h2><span class="n">23</span> Security / Compliance Considerations</h2>
    <ul>
      <li><b>CMMC / NIST SP 800-171 / DFARS 252.204-7012</b> shape the whole design: access control, MFA, audit, config mgmt, incident response, media protection, personnel offboarding.</li>
      <li><b>CUI/ITAR are supported in-portal</b> across the on-prem app enclave + M365 GCC High back end: Purview CUI labeling, US-person access gating for export-controlled zones, FIPS-validated crypto, and DFARS 252.204-7012 incident-reporting hooks. Export-controlled data is held under access control, not excluded.</li>
      <li><b>Least privilege + need-to-know</b> is the default posture; access is additive and approved, never assumed.</li>
      <li><b>Records/retention</b> follows contract + corporate schedules; audit logs retained longer for external access.</li>
      <li><b>External identity</b> is the largest ongoing risk surface — governed by sponsored lifecycle + access reviews.</li>
    </ul>
    <p>See non-functional requirements in <a href="#nfr">Annex D</a>.</p>
  </section>

  <!-- 24 -->
  <section class="block" id="s24">
    <h2><span class="n">24</span> Risk Register</h2>
    <div class="tablewrap"><table>
      <thead><tr><th>Risk</th><th>Likelihood</th><th>Impact</th><th>Mitigation</th><th>Owner</th><th>Residual</th></tr></thead>
      <tbody>
        <tr><td>On-prem enclave under-hardened (app tier not 800-171 compliant)</td><td>Med</td><td>Critical</td><td>Customer-owned boundary must meet 800-171/CMMC (physical, network, FIPS, boundary protection); assess before go-live; CI/CD blocks controlled data on non-authorized targets</td><td>Cyber/Ent. Systems</td><td>Med</td></tr>
        <tr><td>US-person / export-control gate bypass</td><td>Low</td><td>Critical</td><td>US-person attribute verified at provisioning + enforced server-side on export zones; access reviews; audit</td><td>Cyber/Export</td><td>Low</td></tr>
        <tr><td>Cross-subcontractor disclosure</td><td>Med</td><td>High</td><td>Per-company libraries + groups; server-side trim; negative tests</td><td>Cyber</td><td>Low</td></tr>
        <tr><td>SharePoint item-permission sprawl</td><td>High</td><td>High</td><td>Zone/library-level perms only; no per-item ACLs</td><td>Ent. Systems</td><td>Med</td></tr>
        <tr><td>Orphaned external accounts</td><td>High</td><td>High</td><td>Contract-bound expiry + quarterly access reviews</td><td>IAM</td><td>Low</td></tr>
        <tr><td>Stale content erodes trust</td><td>Med</td><td>Med</td><td>Ownership, review dates, freshness indicators</td><td>Content Mgr</td><td>Low</td></tr>
        <tr><td>Shadow duplication of SoR data</td><td>Med</td><td>Med</td><td>Metadata/reference only; policy + review</td><td>Architecture</td><td>Low</td></tr>
        <tr><td>Scope creep / over-customization</td><td>High</td><td>Med</td><td>Config-over-code; phase gates; template discipline</td><td>Product/PM</td><td>Med</td></tr>
        <tr><td>Poor adoption</td><td>Med</td><td>High</td><td>Solve real pain in MVP; role-aware home; comms plan</td><td>PM</td><td>Med</td></tr>
        <tr><td>Vendor lock-in (M365)</td><td>Med</td><td>Med</td><td>Portal layer abstracts Graph; SoR replaceable behind interface</td><td>Architecture</td><td>Med</td></tr>
        <tr><td>Commercial Render used for CUI</td><td>Low</td><td>Critical</td><td>Enforce enclave for CUI; Render = non-CUI pilot only</td><td>Cyber</td><td>Low</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 25 -->
  <section class="block" id="s25">
    <h2><span class="n">25</span> Success Metrics</h2>
    <div class="tablewrap"><table>
      <thead><tr><th>KPI</th><th>Baseline</th><th>Target</th></tr></thead>
      <tbody>
        <tr><td>PM administrative/coordination email volume</td><td>Measure 2-wk baseline</td><td>−40% by 90 days post-launch</td></tr>
        <tr><td>Repeated information requests to PM</td><td>Baseline via survey/log</td><td>−50%</td></tr>
        <tr><td>Subcontractor onboarding time</td><td>Baseline (days)</td><td>&lt; 1 business day (Phase 2)</td></tr>
        <tr><td>Portal adoption (WAU / eligible)</td><td>0</td><td>&gt; 70% of active members weekly</td></tr>
        <tr><td>Document retrieval time</td><td>Baseline</td><td>&lt; 30s to authoritative doc</td></tr>
        <tr><td>Search success rate</td><td>—</td><td>&gt; 85% find-on-first-search</td></tr>
        <tr><td>Announcement readership</td><td>Unknown</td><td>&gt; 80% within 48h</td></tr>
        <tr><td>Time to stand up a new program</td><td>Weeks (custom)</td><td>&lt; 5 business days (Phase 3)</td></tr>
        <tr><td>Systems a user must visit for program info</td><td>Many</td><td>1 entry point</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 26 -->
  <section class="block" id="s26">
    <h2><span class="n">26</span> Discovery Questions</h2>
    <h3 class="title">Answer the blockers before development</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Priority</th><th>Domain</th><th>Question</th></tr></thead>
      <tbody>
        <tr><td><span class="badge b-block">Blocking</span></td><td>Compliance</td><td>On-prem app-tier hardening &amp; ATO: how does the customer-owned CUI boundary (physical, network, FIPS modules, boundary protection) meet 800-171/CMMC? (Hosting decided: on-prem + M365 GCC High.)</td></tr>
        <tr><td><span class="badge b-block">Blocking</span></td><td>Export</td><td>Which zones are ITAR/EAR, and what is the authoritative source for a user's US-person status + any license/agreement scoping?</td></tr>
        <tr><td><span class="badge b-block">Blocking</span></td><td>Customer</td><td>Is Government/COR access contractually permitted, and under what terms?</td></tr>
        <tr><td><span class="badge b-block">Blocking</span></td><td>Identity</td><td>Do we have Entra + B2B external collaboration approved and licensed?</td></tr>
        <tr><td><span class="badge b-high">High value</span></td><td>Program Mgmt</td><td>Which single pilot program launches first?</td></tr>
        <tr><td><span class="badge b-high">High value</span></td><td>Data</td><td>Where do task orders / contract docs authoritatively live today?</td></tr>
        <tr><td><span class="badge b-high">High value</span></td><td>Recruiting</td><td>Which ATS, and does it expose an apply URL/API?</td></tr>
        <tr><td><span class="badge b-high">High value</span></td><td>Finance</td><td>What financial info is appropriate to expose, to whom?</td></tr>
        <tr><td><span class="badge b-high">High value</span></td><td>Integration</td><td>Confirm SharePoint Online availability + tenant for document SoR.</td></tr>
        <tr><td><span class="badge b-low">Later</span></td><td>UX</td><td>Program branding standards (logo/color) per program?</td></tr>
        <tr><td><span class="badge b-low">Later</span></td><td>Ops</td><td>Notification channels (email vs Teams) preference?</td></tr>
        <tr><td><span class="badge b-low">Later</span></td><td>Operations</td><td>Support/ownership model post-launch (who runs it)?</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- 27 -->
  <section class="block" id="s27">
    <h2><span class="n">27</span> Recommended Next Actions</h2>
    <ol>
      <li><b>Harden the on-prem enclave &amp; assemble ATO evidence</b> for the app tier (physical, network, FIPS, boundary), and provision the M365 GCC High tenant + Entra app registration (Cyber + Enterprise Systems).</li>
      <li><b>Define the export-control gate</b>: authoritative US-person source, ITAR/EAR zones, and license scoping (Cyber + Export/Empowered Official).</li>
      <li><b>Select the pilot program</b> and name its PM + Content Manager.</li>
      <li><b>Confirm M365/SharePoint tenant</b> and external collaboration (B2B) licensing/policy.</li>
      <li><b>Inventory systems of record</b> for docs, task orders, jobs, finance.</li>
      <li><b>Baseline the KPIs</b> (email volume, repeated requests, onboarding time) now, pre-launch.</li>
      <li><b>Ratify the RBAC model &amp; data classification</b> with Cyber + Contracts (Annex C, §11).</li>
      <li><b>Approve MVP scope</b> (this package's §20) and phase gates.</li>
      <li><b>Stand up the PHP scaffold in a dev boundary</b> (this repo) + Entra dev app registration.</li>
      <li><b>Schedule the Automation Core Team discovery session</b> using Annex F decisions.</li>
    </ol>
  </section>

  <!-- Annex A: Wireframe -->
  <section class="block" id="wf">
    <h2><span class="n">A</span> Annex · Homepage Wireframe</h2>
    <h3 class="title">Role-aware home — materially beyond a link page</h3>
    <div class="wf" role="img" aria-label="REDOUBT home page wireframe">
      <div class="wf-top">
        <div class="wf-brand"><span class="wf-logo"></span> PROGRAM NAME</div>
        <div style="font-size:12.5px;opacity:.9">Customer · Contract # · PM &nbsp;|&nbsp; 🔍 Search &nbsp; 🔔 &nbsp; ▢ User ▾</div>
      </div>
      <div class="wf-nav">Home · Program ▾ · Documents ▾ · Task Orders · Jobs · Customer · Resources ▾</div>
      <div class="wf-body">
        <div class="wf-banner">⚠ <b>Program status / critical announcement</b> — single priority banner, dismissible, re-surfaces if updated.</div>
        <div style="font-weight:800;margin-bottom:10px">Welcome back, [User] — here's what needs you today.</div>
        <div class="wf-kpis">
          <div class="wf-kpi"><div class="big">3</div><div class="lbl">My Actions</div></div>
          <div class="wf-kpi"><div class="big">5</div><div class="lbl">New Documents</div></div>
          <div class="wf-kpi"><div class="big">2</div><div class="lbl">Upcoming Milestones</div></div>
          <div class="wf-kpi"><div class="big">On&nbsp;Track</div><div class="lbl">Program Status</div></div>
        </div>
        <div class="wf-quick">
          <div class="wf-q">📁 Project Documents</div>
          <div class="wf-q">🏛 Customer Library</div>
          <div class="wf-q">📄 Task Orders</div>
          <div class="wf-q">💼 Job Openings</div>
        </div>
        <div class="wf-cols">
          <div class="wf-card">
            <h6>Recent Announcements</h6>
            <div class="wf-row"><span>Kickoff schedule posted</span><span class="muted">2h</span></div>
            <div class="wf-row"><span>Updated security training due</span><span class="muted">1d</span></div>
            <div class="wf-row"><span>New TO 0004 awarded</span><span class="muted">3d</span></div>
          </div>
          <div class="wf-card">
            <h6>Upcoming Milestones</h6>
            <div class="wf-row"><span>Monthly status review</span><span class="muted">Sep 24</span></div>
            <div class="wf-row"><span>CDRL A001 due</span><span class="muted">Sep 30</span></div>
          </div>
        </div>
        <div class="wf-card" style="margin-top:14px">
          <h6>My Program Resources <span class="muted" style="font-weight:400">· scoped to your role &amp; company</span></h6>
          <div class="wf-row"><span>Onboarding checklist · Timekeeping · Security POC · Help desk</span><span class="muted">quick links</span></div>
        </div>
      </div>
    </div>
    <p class="muted">Improvements over the reference: single-priority banner (not a wall), action-first layout, permission-scoped everything, status at a glance for leadership, responsive/accessible, and consistent card system reused across modules.</p>
  </section>

  <!-- Annex B: tenancy -->
  <section class="block" id="tenancy">
    <h2><span class="n">B</span> Annex · Multi-Tenancy / Program Isolation</h2>
    <h3 class="title">Recommended: one application, isolated program instances (Option B, hybrid toward D)</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Approach</th><th>Isolation</th><th>Cost/maint</th><th>Scalability</th><th>Verdict</th></tr></thead>
      <tbody>
        <tr><td>A · Multi-tenant, shared data</td><td>Logical only</td><td>Low</td><td>High</td><td>Risk of cross-program leakage — no</td></tr>
        <tr><td><b>B · One app, isolated program configs + per-program SharePoint site + groups</b></td><td>Strong (data + identity boundary)</td><td>Medium</td><td>High</td><td><b>Recommended</b></td></tr>
        <tr><td>C · Separate instance per program from template</td><td>Strongest</td><td>High (N deployments)</td><td>Med</td><td>Reserve for programs needing hard physical separation</td></tr>
        <tr><td>D · Hybrid</td><td>Tunable</td><td>Med</td><td>High</td><td>Default B; escalate specific programs to C when compliance demands</td></tr>
      </tbody>
    </table></div>
    <p>Rationale: Option B gives one codebase to maintain and patch, one design system, and one operations model, while enforcing isolation through <b>separate SharePoint site collections + Entra groups per program</b> and mandatory <code>program_id</code> scoping in every query. Programs with exceptional separation requirements (e.g., certain classified-adjacent or ITAR cases) escalate to Option C on the same template — that's the hybrid.</p>
  </section>

  <!-- Annex C: RBAC -->
  <section class="block" id="rbac">
    <h2><span class="n">C</span> Annex · Authorization Matrix</h2>
    <h3 class="title">Roles × actions (V=view · C=create · E=edit · P=publish · A=approve · ✦=administer)</h3>
    <div class="tablewrap"><table>
      <thead><tr><th>Role</th><th>Announcements</th><th>Docs</th><th>Task Orders</th><th>Jobs</th><th>Roles/Access</th><th>Program Config</th><th>Audit</th></tr></thead>
      <tbody>
        <tr><td>Enterprise Admin</td><td>V</td><td>V</td><td>V</td><td>V</td><td>✦</td><td>✦</td><td>V</td></tr>
        <tr><td>Program Admin</td><td>V</td><td>V</td><td>V</td><td>V</td><td>—</td><td>✦</td><td>V</td></tr>
        <tr><td>Program Manager</td><td>VCEP</td><td>VCE</td><td>V</td><td>V</td><td>—</td><td>—</td><td>V</td></tr>
        <tr><td>Content Manager</td><td>VCEP</td><td>VCE</td><td>V</td><td>VCEP</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Contracts</td><td>V</td><td>VCE</td><td>VCEPA</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Finance</td><td>V</td><td>V(fin)</td><td>V</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Recruiting</td><td>V</td><td>—</td><td>—</td><td>VCEP</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Internal Program Member</td><td>V</td><td>V</td><td>V</td><td>V</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Subcontractor Admin</td><td>V</td><td>V(scope)</td><td>V(scope)</td><td>V</td><td>V(own co.)</td><td>—</td><td>—</td></tr>
        <tr><td>Subcontractor Member</td><td>V</td><td>V(scope)</td><td>V(scope)</td><td>V</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>COR / Customer</td><td>V(cust)</td><td>V(cust)</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>Security Admin (Cyber)</td><td>V</td><td>V</td><td>V</td><td>V</td><td>✦</td><td>—</td><td>✦</td></tr>
      </tbody>
    </table></div>
    <p class="muted">"(scope)" = company/zone-scoped; "(cust)" = customer-shared only; "(fin)" = financial zone only. Granular permission keys (e.g. <code>announcement.publish</code>, <code>taskorder.approve</code>, <code>access.review</code>) back each cell — coarse role strings map to granular keys via aliases.</p>
  </section>

  <!-- Annex D: NFR -->
  <section class="block" id="nfr">
    <h2><span class="n">D</span> Annex · Non-Functional Requirements</h2>
    <div class="grid2">
      <div class="tile"><h5>Availability</h5><p>Target 99.5% (business hours critical); health checks; graceful SoR-outage degradation (portal usable, docs show "source unavailable").</p></div>
      <div class="tile"><h5>Performance</h5><p>Home &lt; 2s p95; search &lt; 1.5s p95; Graph calls cached where safe.</p></div>
      <div class="tile"><h5>Scalability</h5><p>Stateless PHP app, horizontally scalable behind LB; Postgres vertical + read replica path.</p></div>
      <div class="tile"><h5>Accessibility</h5><p>WCAG 2.1 AA; keyboard-navigable; contrast-safe in light/dark.</p></div>
      <div class="tile"><h5>Responsiveness / browsers</h5><p>Mobile-first; evergreen Chrome/Edge/Firefox/Safari.</p></div>
      <div class="tile"><h5>Backup / recovery</h5><p>Postgres PITR; documented restore runbook; RPO ≤ 24h, RTO ≤ 4h (tunable).</p></div>
      <div class="tile"><h5>Observability</h5><p>Structured logs, request tracing, audit log, alerting on auth failures/anomalies.</p></div>
      <div class="tile"><h5>Maintainability</h5><p>PSR-4, typed, tested; config-over-code; standard doc set kept current.</p></div>
    </div>
  </section>

  <!-- Annex E: governance -->
  <section class="block" id="gov">
    <h2><span class="n">E</span> Annex · Governance / RACI</h2>
    <div class="tablewrap"><table>
      <thead><tr><th>Responsibility</th><th>Owner</th></tr></thead>
      <tbody>
        <tr><td>Application ownership (product)</td><td>Enterprise Systems (platform) + Program Mgmt (per-instance)</td></tr>
        <tr><td>Data ownership</td><td>Respective SoR owners (Contracts, Finance, Recruiting, PM)</td></tr>
        <tr><td>Approve external (sub) access</td><td>Prime PM + Cybersecurity</td></tr>
        <tr><td>Approve customer/COR access</td><td>PM + Contracts (contractual authority)</td></tr>
        <tr><td>Content management</td><td>Program Content Manager</td></tr>
        <tr><td>Role / access management</td><td>Security Admin (Cyber/IAM)</td></tr>
        <tr><td>Access reviews</td><td>Cybersecurity (quarterly)</td></tr>
        <tr><td>Incident response</td><td>Cybersecurity + Enterprise Systems</td></tr>
        <tr><td>Integrations / secrets</td><td>Enterprise Systems / DevSecOps</td></tr>
      </tbody>
    </table></div>
  </section>

  <!-- Annex F: decisions -->
  <section class="block" id="decisions">
    <h2><span class="n">F</span> Annex · Executive Decision Support</h2>

    <h4 class="sub">Decisions leadership needs to make</h4>
    <ul>
      <li>Fund REDOUBT as a <b>reusable platform</b> (vs a one-off program site)?</li>
      <li>Is this intended to become a <b>proposal differentiator</b> (raises the bar for polish/compliance)?</li>
      <li>Risk appetite for <b>customer/COR access</b> in the portal.</li>
      <li>Which <b>pilot program</b> and what success looks like at 90 days.</li>
    </ul>

    <h4 class="sub">Decisions Enterprise Systems can make</h4>
    <ul>
      <li>SharePoint site topology (hub + per-program sites), provisioning template approach.</li>
      <li>On-prem enclave runtime (Kubernetes vs hardened Linux), sizing/HA, and boundary hardening; GCC High tenant configuration + Graph/auth endpoints.</li>
      <li>Entra app registration, group naming, and B2B configuration.</li>
      <li>CI/CD, secrets management, environment topology (dev/test/prod).</li>
    </ul>

    <h4 class="sub">Questions for the Program Manager</h4>
    <ul>
      <li>Top 3 recurring information requests you want eliminated first?</li>
      <li>Who is your Content Manager, and who approves customer-facing docs?</li>
      <li>Which subcontractors are in the pilot, and how many external users?</li>
    </ul>

    <h4 class="sub">Questions for Cybersecurity</h4>
    <ul>
      <li>On-prem app-tier ATO evidence (800-171/CMMC for the CUI enclave) + confirm the GCC High tenant/app registration.</li>
      <li>Authoritative source for US-person status and the export-control (ITAR/EAR) access gate.</li>
      <li>Conditional Access baseline for external identities.</li>
      <li>Audit retention requirements, especially for external access.</li>
      <li>Approved offboarding SLA (how fast must access die?).</li>
    </ul>

    <h4 class="sub">Questions for Contracts</h4>
    <ul>
      <li>Is Government/COR portal access contractually allowed? Under what flow-downs?</li>
      <li>Where do task orders/mods authoritatively live, and who may see them?</li>
      <li>Any DFARS/flow-down clauses affecting where data may be stored/hosted?</li>
    </ul>
  </section>

  <!-- Assumptions block required by root prompt -->
  <section class="block" id="assumptions">
    <h2><span class="n">§</span> Assumptions, Unknowns &amp; Required Decisions</h2>
    <h4 class="sub">Confirmed facts</h4>
    <ul>
      <li>Stack is mandated <b>PHP</b>; must be <b>Docker/web-service deployable</b>.</li>
      <li><b>CUI and ITAR/export-controlled data are in scope</b> and must be supported in-portal.</li>
      <li><b>Selected hosting is hybrid:</b> the app is <b>self-hosted on-prem</b> in GMRE's CUI boundary; identity &amp; documents are in <b>Microsoft 365 / Azure GCC High</b>.</li>
      <li>Project lives in the <code>jessicarojas1.github.io</code> repo as folder <code>redoubt/</code>.</li>
      <li>Business need, personas, and desired modules are per the Automation Core Team request.</li>
    </ul>
    <h4 class="sub">Assumptions made</h4>
    <ul>
      <li><b>M365/SharePoint is available</b> as document SoR — necessary to avoid rebuilding a DMS; if false, document strategy changes materially.</li>
      <li><b>Entra ID + B2B</b> is the enterprise identity provider — necessary for compliant SSO/MFA/external identity; if false, identity design changes.</li>
      <li><b>Discovery-first</b> is the intent of this turn (no portal features built yet) — per the master prompt.</li>
      <li>GMRE is the prime/host organization.</li>
    </ul>
    <h4 class="sub">Unknowns / missing information</h4>
    <ul>
      <li>On-prem enclave <b>hardening &amp; ATO evidence</b> for the app tier (physical, network, FIPS modules, boundary protection) — decided <em>where</em> (on-prem + M365 GCC High); <em>how</em> it meets 800-171/CMMC is <b>blocking</b> before go-live.</li>
      <li>Authoritative source for US-person status and which zones are ITAR/EAR (drives the export-control gate) — <b>blocking</b>.</li>
      <li>Whether COR/customer access is contractually permitted.</li>
      <li>Authoritative locations/APIs for task orders, jobs (ATS), and finance.</li>
    </ul>
    <h4 class="sub">Validation status</h4>
    <ul>
      <li><b>Reviewed:</b> repo conventions, PHP/Docker/Render constraint, master-prompt requirements.</li>
      <li><b>Not yet tested:</b> container build &amp; health endpoint in a live environment (packaged; run locally per <code>deployments/LOCAL_DEVELOPMENT.md</code>).</li>
      <li><b>Not built:</b> any portal feature/module — intentionally, this is discovery.</li>
    </ul>
    <h4 class="sub">Final confidence</h4>
    <p><b>High</b> on architecture direction and MVP scope given the stated constraints; <b>Medium</b> on hosting/compliance specifics pending the blocking CUI/boundary decision.</p>
  </section>

  </main>
</div>

<footer>
  REDOUBT — GMRE Program Portal Framework · Product &amp; Architecture Discovery Package · <?= $today ?> · Pre-decisional / notional.
  Health: <a href="/health">/health</a>.
</footer>

<pre class="diagram-src" id="src-note">Architecture diagrams render with Mermaid. If the CDN is blocked (e.g. air-gapped), the raw diagram text remains available in the page source above each diagram section.</pre>

<script nonce="<?= $NONCE ?>">
  // Mobile TOC toggle (no inline handlers — CSP compliant)
  (function(){
    var btn = document.getElementById('navtoggle');
    var toc = document.getElementById('toc');
    if(btn && toc){
      if(window.matchMedia('(max-width:900px)').matches){ toc.setAttribute('data-collapsed','true'); }
      btn.addEventListener('click', function(){
        var collapsed = toc.getAttribute('data-collapsed') === 'true';
        toc.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
      });
      // Collapse after choosing a section on mobile
      toc.addEventListener('click', function(e){
        if(e.target.tagName === 'A' && window.matchMedia('(max-width:900px)').matches){
          toc.setAttribute('data-collapsed','true');
          btn.setAttribute('aria-expanded','false');
        }
      });
    }
  })();
</script>
<script nonce="<?= $NONCE ?>" src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
<script nonce="<?= $NONCE ?>">
  (function(){
    if(window.mermaid){
      var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches;
      window.mermaid.initialize({ startOnLoad:true, theme: dark ? 'dark' : 'default', securityLevel:'strict' });
    }
  })();
</script>
</body>
</html>
