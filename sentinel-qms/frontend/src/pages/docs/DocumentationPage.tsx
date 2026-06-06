import type { ComponentType } from 'react';
import {
  BadgeCheck,
  BookOpen,
  ClipboardCheck,
  Fingerprint,
  FileText,
  FlaskConical,
  GaugeCircle,
  GitPullRequestArrow,
  GraduationCap,
  Hash,
  History,
  Layers,
  LayoutDashboard,
  MessageSquareWarning,
  ScrollText,
  ShieldAlert,
  ShieldCheck,
  Truck,
  Workflow,
  Wrench,
} from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';

interface ModuleDoc {
  name: string;
  icon: ComponentType<{ size?: number }>;
  std: string;
  purpose: string;
  fields: string;
  lifecycle: string[];
  links?: string;
}

const MODULES: ModuleDoc[] = [
  {
    name: 'Nonconformances (NCR / MRB)',
    icon: ShieldAlert,
    std: 'AS9100D 8.7',
    purpose:
      'Capture, segregate, and control nonconforming product or process output, then route it through the Material Review Board (MRB) for a formal disposition.',
    fields:
      'NCR number, title, description, severity (minor/major/critical), source, quantity, supplier, assigned engineer.',
    lifecycle: ['open', 'under_review', 'dispositioned', 'closed'],
    links:
      'Dispositions: use-as-is, rework, repair, scrap, return-to-supplier. A signed disposition is required to close. Systemic issues escalate to a CAPA.',
  },
  {
    name: 'CAPA (8D)',
    icon: ClipboardCheck,
    std: 'AS9100D 10.2',
    purpose:
      'Drive corrective and preventive action through the 8-Discipline (8D) methodology — containment, root cause, corrective action, and effectiveness verification.',
    fields:
      'CAPA number, type (corrective/preventive), D1–D8 disciplines, root cause, owner, due dates, effectiveness check.',
    lifecycle: ['open', 'containment', 'root_cause', 'action_plan', 'verification', 'closed'],
    links: 'Closure requires a completed D8 and an electronic signature. Sourced from NCRs, audit findings, or complaints.',
  },
  {
    name: 'Complaints / RMA',
    icon: MessageSquareWarning,
    std: 'AS9100D 8.2 / 9.1.2',
    purpose:
      'Intake customer complaints and product returns (RMA), assess severity, investigate, and link to corrective action.',
    fields: 'Complaint number, customer, severity, description, received date, linked NCR/CAPA.',
    lifecycle: ['open', 'under_investigation', 'awaiting_customer', 'closed'],
  },
  {
    name: 'Risk Register',
    icon: ShieldAlert,
    std: 'AS9100D 6.1',
    purpose: 'Identify, score, and treat risks across the quality system using a severity × likelihood × detectability model.',
    fields: 'Risk number, category, description, severity, likelihood, detectability, RPN, treatment strategy, owner.',
    lifecycle: ['open', 'treatment_planned', 'monitoring', 'closed'],
  },
  {
    name: 'Document & Records Control',
    icon: FileText,
    std: 'AS9100D 7.5',
    purpose:
      'Author and control documented information (procedures, work instructions, forms, specifications) with revision history and signed release.',
    fields: 'Document number, type, title, owner, current revision, effectivity date.',
    lifecycle: ['draft', 'in_review', 'effective', 'obsolete'],
    links: 'Each revision is approved per-revision with a 21 CFR Part 11 electronic signature before it becomes effective.',
  },
  {
    name: 'Change Control (ECN / ECO)',
    icon: GitPullRequestArrow,
    std: 'AS9100D 8.5.6',
    purpose: 'Manage engineering/process change requests with impact analysis and controlled approval before implementation.',
    fields: 'Change number, type, requestor, impact analysis, affected items, target date.',
    lifecycle: ['draft', 'under_review', 'approved', 'implemented'],
    links: 'Approval is a signed decision (approved/rejected) by an authorized approver.',
  },
  {
    name: 'Audit Management',
    icon: ScrollText,
    std: 'AS9100D 9.2 / AS9101',
    purpose: 'Plan and conduct internal, external, supplier, and process audits; record findings against specific clauses.',
    fields: 'Audit number, type, scope, lead auditor, auditee area, planned/actual date, checklist, findings.',
    lifecycle: ['planned', 'in_progress', 'closed'],
    links: 'Findings (major/minor nonconformity, observation, OFI) carry a clause reference and can spawn a CAPA.',
  },
  {
    name: 'Inspection & First Article (FAI)',
    icon: FlaskConical,
    std: 'AS9102',
    purpose:
      'Record receiving, in-process, final, source, and First Article inspections. FAI reports balloon and verify every characteristic per AS9102.',
    fields: 'Inspection number, type, part, result, inspector, date; FAI report with ballooned characteristics & measurements.',
    lifecycle: ['pass', 'conditional', 'fail'],
  },
  {
    name: 'Supplier Quality',
    icon: Truck,
    std: 'AS9100D 8.4',
    purpose: 'Maintain the Approved Supplier List (ASL), rate supplier performance, and issue Supplier Corrective Action Requests (SCAR).',
    fields: 'Supplier code, name, CAGE code, DUNS, certification, status, ratings, ASL entries, SCARs.',
    lifecycle: ['prospective', 'approved', 'conditional', 'disqualified'],
  },
  {
    name: 'Calibration & Equipment',
    icon: Wrench,
    std: 'AS9100D 7.1.5',
    purpose: 'Track measurement equipment and gages, calibration intervals, and certificates; flag due/overdue assets.',
    fields: 'Asset tag, type, manufacturer, model, serial, interval, last/next-due date, custodian, calibration records.',
    lifecycle: ['active', 'out_of_service', 'lost', 'retired'],
    links: 'Recording a calibration auto-recalculates the next-due date from the interval.',
  },
  {
    name: 'Training & Competency',
    icon: GraduationCap,
    std: 'AS9100D 7.2',
    purpose: 'Manage personnel, training courses and records, and a competency matrix mapping people to required skills.',
    fields: 'Personnel, courses, training records, competency matrix (person × skill → level).',
    lifecycle: ['assigned', 'completed', 'expired'],
  },
  {
    name: 'Management Review',
    icon: GaugeCircle,
    std: 'AS9100D 9.3',
    purpose: 'Run periodic management reviews — capture standardized inputs, decisions, and tracked action items.',
    fields: 'Review number, meeting date, chairperson, inputs (category/content/metric), action items (owner, due).',
    lifecycle: ['planned', 'completed', 'closed'],
  },
  {
    name: 'Dashboard & KPIs',
    icon: LayoutDashboard,
    std: 'AS9100D 9.1',
    purpose: 'At-a-glance quality health: open NCRs, CAPA aging, calibration due, supplier performance, and audit findings.',
    fields: 'Live aggregates and charts across every module.',
    lifecycle: [],
  },
];

interface Concept {
  name: string;
  icon: ComponentType<{ size?: number }>;
  text: string;
}

const CONCEPTS: Concept[] = [
  {
    name: 'Record numbering',
    icon: Hash,
    text:
      'Every record gets a human-readable, sequential identifier in the form PREFIX-YYYY-NNNN (e.g. NCR-2026-0001, CAPA-2026-0042), assigned by the server.',
  },
  {
    name: 'Workflow engine',
    icon: Workflow,
    text:
      'Each module has a defined status lifecycle. Transitions are validated server-side, so a record can only move through legal states (e.g. an NCR cannot close without a disposition).',
  },
  {
    name: 'Electronic signatures',
    icon: Fingerprint,
    text:
      'Critical approvals (NCR disposition, CAPA closure, document release, change approval) require a 21 CFR Part 11 signature: signer identity, meaning, timestamp, password re-authentication, and a tamper-evident hash.',
  },
  {
    name: 'Immutable audit trail',
    icon: History,
    text:
      'Every create, update, and delete is written to an append-only audit log capturing who, what, when, and the before/after values. Controlled records are soft-deleted, never destroyed.',
  },
  {
    name: 'Role-based access control',
    icon: ShieldCheck,
    text:
      'Seven roles gate what each user can see and do. The API is the authoritative enforcement point; the UI mirrors those permissions.',
  },
  {
    name: 'Attachments',
    icon: Layers,
    text:
      'Evidence files are stored with a SHA-256 checksum and randomized object keys, validated against a MIME allowlist, on S3 (GovCloud), Azure Blob, or local disk.',
  },
];

interface FlowDoc {
  title: string;
  steps: string[];
  note: string;
}

const WORKFLOWS: FlowDoc[] = [
  {
    title: 'Nonconformance → CAPA',
    steps: ['Detect', 'Raise NCR', 'MRB disposition (signed)', 'Escalate to CAPA', '8D investigation', 'Verify effectiveness', 'Close'],
    note: 'A nonconformance is dispositioned by the MRB; if it is systemic or recurring, a CAPA is opened to eliminate the root cause.',
  },
  {
    title: 'Audit → Finding → CAPA',
    steps: ['Plan audit', 'Conduct & checklist', 'Log finding (clause-tagged)', 'Open CAPA', 'Implement action', 'Close finding'],
    note: 'Major/minor nonconformities raised during an audit are linked to a CAPA and closed once the action is verified effective.',
  },
  {
    title: 'Calibration cycle',
    steps: ['Register equipment', 'Set interval', 'Next-due tracked', 'Due/overdue alert', 'Record calibration', 'Next-due recalculated'],
    note: 'The dashboard surfaces equipment approaching or past its calibration due date so gages never drift out of control.',
  },
  {
    title: 'First Article Inspection (AS9102)',
    steps: ['New/changed part', 'Create FAI report', 'Balloon characteristics', 'Measure & record', 'Pass', 'Release to production'],
    note: 'A First Article Inspection verifies every design characteristic before a part is approved for production.',
  },
];

const ROLES: { role: string; summary: string }[] = [
  { role: 'Administrator', summary: 'Full access plus user and role management.' },
  { role: 'Quality Manager', summary: 'Owns the QMS; approves documents/changes and closes CAPAs.' },
  { role: 'Quality Engineer', summary: 'Investigates NCRs/CAPAs and drives corrective action.' },
  { role: 'Auditor', summary: 'Plans and conducts audits and records findings.' },
  { role: 'Supplier Quality', summary: 'Manages suppliers, ratings, SCARs, and the ASL.' },
  { role: 'Operator', summary: 'Reports nonconformances and records inspections.' },
  { role: 'Read-Only', summary: 'Read access across modules; no edits.' },
];

const STANDARDS: { name: string; what: string }[] = [
  { name: 'AS9100D / ISO 9001:2015', what: 'Aerospace quality management system — the core framework every module maps to.' },
  { name: 'AS9102', what: 'First Article Inspection requirements (the FAI module).' },
  { name: 'CMMC 2.0 Level 2', what: 'DoD cybersecurity maturity for handling CUI.' },
  { name: 'NIST SP 800-171', what: 'Protecting Controlled Unclassified Information in nonfederal systems.' },
  { name: 'NIST SP 800-53 / FedRAMP', what: 'Control baseline for the GovCloud / Azure Government deployments.' },
  { name: 'DFARS 252.204-7012', what: 'Safeguarding covered defense information and cyber incident reporting.' },
  { name: 'ITAR / EAR', what: 'Export-control handling: data residency and access segregation in a U.S. gov cloud.' },
  { name: '21 CFR Part 11', what: 'Electronic records and signatures conformance.' },
];

const TOC: { id: string; label: string }[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'getting-started', label: 'Getting started' },
  { id: 'concepts', label: 'Core concepts' },
  { id: 'modules', label: 'Modules' },
  { id: 'workflows', label: 'End-to-end workflows' },
  { id: 'roles', label: 'Roles & access' },
  { id: 'standards', label: 'Standards & compliance' },
  { id: 'architecture', label: 'Architecture' },
  { id: 'deployment', label: 'Deployment' },
];

export default function DocumentationPage() {
  return (
    <>
      <PageHeader
        icon={<BookOpen size={22} />}
        title="Documentation"
        subtitle="How Sentinel QMS works — modules, workflows, roles, and compliance"
        breadcrumbs={[{ label: 'Documentation' }]}
      />

      <div className="doc-layout">
        <nav className="doc-toc" aria-label="Documentation contents">
          <div className="doc-toc__title">On this page</div>
          {TOC.map((t) => (
            <a key={t.id} href={`#${t.id}`}>
              {t.label}
            </a>
          ))}
        </nav>

        <div className="doc-content">
          <section id="overview" className="doc-section">
            <h2>Overview</h2>
            <p className="doc-lead">
              Sentinel QMS is an enterprise Quality Management System for aerospace, manufacturing, and U.S. Department of
              Defense work. It digitizes the full quality lifecycle — from controlled documents and nonconformances through
              corrective action, audits, supplier quality, calibration, and management review — on an architecture built to
              deploy into AWS GovCloud or Azure Government.
            </p>
            <p>
              It is a real three-tier application: a typed REST API, a relational data model with an immutable audit trail
              and 21 CFR Part 11 electronic signatures, and this single-page interface. Every record is access-controlled,
              numbered, versioned, and traceable — the kind of system of record that survives an AS9100 certification audit
              and a CMMC Level&nbsp;2 assessment.
            </p>
            <div className="doc-callout">
              <strong>CUI notice.</strong> Sentinel QMS is engineered to handle Controlled Unclassified Information inside an
              authorized government cloud boundary. Do not load real ITAR/EAR or CUI data into the public demo.
            </div>
          </section>

          <section id="getting-started" className="doc-section">
            <h2>Getting started</h2>
            <ol>
              <li>Sign in with the account provisioned by your administrator (the demo uses a seeded administrator).</li>
              <li>The left navigation is grouped into <em>Quality Events</em>, <em>Control</em>, <em>Operations</em>, and{' '}
                <em>Administration</em> — you only see what your role permits.</li>
              <li>Use the <strong>Dashboard</strong> for a live health snapshot, then drill into any module.</li>
              <li>Create a record, move it through its workflow, attach evidence, and sign approvals where required.</li>
            </ol>
          </section>

          <section id="concepts" className="doc-section">
            <h2>Core concepts</h2>
            <p>These cross-cutting mechanisms apply to every module:</p>
            <div className="doc-grid">
              {CONCEPTS.map((c) => {
                const Icon = c.icon;
                return (
                  <div key={c.name} className="doc-module">
                    <h4>
                      <Icon size={16} /> {c.name}
                    </h4>
                    <p style={{ fontSize: '0.84rem', margin: 0 }}>{c.text}</p>
                  </div>
                );
              })}
            </div>
          </section>

          <section id="modules" className="doc-section">
            <h2>Modules</h2>
            <p>
              Thirteen modules cover the AS9100D / ISO 9001 quality system. Each card shows the standard it supports, what it
              does, its key fields, and its status lifecycle.
            </p>
            <div className="doc-grid">
              {MODULES.map((m) => {
                const Icon = m.icon;
                return (
                  <div key={m.name} className="doc-module">
                    <h4>
                      <Icon size={16} /> {m.name}
                    </h4>
                    <div className="doc-std">{m.std}</div>
                    <p style={{ fontSize: '0.84rem', marginTop: '0.4rem' }}>{m.purpose}</p>
                    <p style={{ fontSize: '0.8rem' }}>
                      <strong>Key fields:</strong> {m.fields}
                    </p>
                    {m.lifecycle.length > 0 && (
                      <div className="doc-chips">
                        {m.lifecycle.map((s) => (
                          <span key={s} className="doc-chip">
                            {s}
                          </span>
                        ))}
                      </div>
                    )}
                    {m.links && (
                      <p style={{ fontSize: '0.8rem', marginTop: '0.5rem', marginBottom: 0 }}>{m.links}</p>
                    )}
                  </div>
                );
              })}
            </div>
          </section>

          <section id="workflows" className="doc-section">
            <h2>End-to-end workflows</h2>
            <p>The modules connect into closed-loop quality processes:</p>
            {WORKFLOWS.map((f) => (
              <div key={f.title} className="card" style={{ padding: '1rem', marginBottom: '0.75rem' }}>
                <h3 style={{ marginTop: 0 }}>{f.title}</h3>
                <div className="doc-flow">
                  {f.steps.map((s, i) => (
                    <span key={s} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}>
                      <span className="doc-flow__step">{s}</span>
                      {i < f.steps.length - 1 && <span className="doc-flow__arrow">→</span>}
                    </span>
                  ))}
                </div>
                <p style={{ fontSize: '0.84rem', marginBottom: 0 }}>{f.note}</p>
              </div>
            ))}
          </section>

          <section id="roles" className="doc-section">
            <h2>Roles &amp; access</h2>
            <p>
              Access is role-based and enforced on every state-changing API call. The full permission matrix is in{' '}
              <strong>Administration → Roles</strong>.
            </p>
            <table className="doc-table">
              <thead>
                <tr>
                  <th>Role</th>
                  <th>What they can do</th>
                </tr>
              </thead>
              <tbody>
                {ROLES.map((r) => (
                  <tr key={r.role}>
                    <td>
                      <BadgeCheck size={14} style={{ verticalAlign: '-2px', marginRight: 4 }} />
                      {r.role}
                    </td>
                    <td>{r.summary}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>

          <section id="standards" className="doc-section">
            <h2>Standards &amp; compliance</h2>
            <p>Sentinel QMS is built to support certification and assessment readiness against:</p>
            <table className="doc-table">
              <thead>
                <tr>
                  <th>Standard</th>
                  <th>Role in the system</th>
                </tr>
              </thead>
              <tbody>
                {STANDARDS.map((s) => (
                  <tr key={s.name}>
                    <td style={{ whiteSpace: 'nowrap' }}>{s.name}</td>
                    <td>{s.what}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p style={{ fontSize: '0.84rem', marginTop: '0.6rem' }}>
              Detailed clause-by-clause control mappings ship with the source in <code>docs/compliance/</code>.
            </p>
          </section>

          <section id="architecture" className="doc-section">
            <h2>Architecture</h2>
            <div className="doc-flow">
              <span className="doc-flow__step">React + TypeScript SPA</span>
              <span className="doc-flow__arrow">→ HTTPS /api/v1 →</span>
              <span className="doc-flow__step">FastAPI (Python)</span>
              <span className="doc-flow__arrow">→</span>
              <span className="doc-flow__step">PostgreSQL + object storage</span>
            </div>
            <ul>
              <li>
                <strong>Frontend:</strong> React 18, TypeScript, TanStack Query, served by nginx with a CUI banner and
                role-filtered navigation.
              </li>
              <li>
                <strong>Backend:</strong> FastAPI with SQLAlchemy 2.0, Pydantic v2, and Alembic migrations; JWT auth with
                pluggable OIDC / SAML / CAC-PIV; RBAC; immutable audit log; e-signatures.
              </li>
              <li>
                <strong>Data:</strong> PostgreSQL 16. On a shared database, all tables live in a dedicated{' '}
                <code>sentinel_qms</code> schema so nothing collides with other apps.
              </li>
              <li>
                <strong>Storage &amp; secrets:</strong> S3 (SSE-KMS) / Azure Blob with secrets from AWS Secrets Manager or
                Azure Key Vault.
              </li>
            </ul>
          </section>

          <section id="deployment" className="doc-section">
            <h2>Deployment</h2>
            <h3>Government cloud (production)</h3>
            <p>
              Terraform provisions private networking, a managed encrypted PostgreSQL, object storage, secrets, a
              WAF-fronted load balancer, and centralized logging for <strong>AWS GovCloud (us-gov-west-1, FIPS)</strong> and{' '}
              <strong>Azure Government</strong>. Database schema and reference data are applied by{' '}
              <code>scripts/db-bootstrap.sh</code> as a one-off ECS task or Container Apps job.
            </p>
            <h3>Demo (Render)</h3>
            <p>
              A single container where FastAPI serves both the API and this SPA, backed by a PostgreSQL database. Migrations
              and seed data run automatically on first boot.
            </p>
            <div className="doc-callout doc-callout--warn">
              <strong>Demo limits.</strong> The free demo runs in development mode with ephemeral file storage and a seeded
              login. It is not for CUI or production — use the government-cloud Terraform for real workloads.
            </div>
          </section>
        </div>
      </div>
    </>
  );
}
