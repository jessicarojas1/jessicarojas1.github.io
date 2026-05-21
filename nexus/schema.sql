-- ════════════════════════════════════════════════════════════════════════
--  NEXUS  ·  PostgreSQL schema + seed data
--  Apply with:  psql $DATABASE_URL -f schema.sql
-- ════════════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS notifications   CASCADE;
DROP TABLE IF EXISTS history         CASCADE;
DROP TABLE IF EXISTS comments        CASCADE;
DROP TABLE IF EXISTS sprints         CASCADE;
DROP TABLE IF EXISTS labels          CASCADE;
DROP TABLE IF EXISTS tickets         CASCADE;
DROP TABLE IF EXISTS project_members CASCADE;
DROP TABLE IF EXISTS projects        CASCADE;
DROP TABLE IF EXISTS users           CASCADE;

-- ─── users ─────────────────────────────────────────────────────────────
CREATE TABLE users (
  id           VARCHAR(10)  PRIMARY KEY,
  username     VARCHAR(100) UNIQUE NOT NULL,
  display_name VARCHAR(200),
  first_name   VARCHAR(100),
  last_name    VARCHAR(100),
  role         VARCHAR(20)  NOT NULL DEFAULT 'member',  -- admin, member, viewer
  clearance    VARCHAR(50),
  org          VARCHAR(50),
  pin_hash     VARCHAR(255) NOT NULL,
  created_at   TIMESTAMPTZ  DEFAULT NOW()
);

-- ─── projects ──────────────────────────────────────────────────────────
CREATE TABLE projects (
  id          VARCHAR(20)  PRIMARY KEY,
  key         VARCHAR(10)  UNIQUE NOT NULL,
  name        VARCHAR(200) NOT NULL,
  description TEXT,
  color       VARCHAR(20)  DEFAULT '#6366f1',
  icon        VARCHAR(10)  DEFAULT '🚀',
  lead_id     VARCHAR(10)  REFERENCES users(id),
  statuses    JSONB        DEFAULT '["Backlog","To Do","In Progress","In Review","Blocked","Done"]'::jsonb,
  created_at  TIMESTAMPTZ  DEFAULT NOW()
);

-- ─── project_members ───────────────────────────────────────────────────
CREATE TABLE project_members (
  project_id VARCHAR(20) REFERENCES projects(id) ON DELETE CASCADE,
  user_id    VARCHAR(10) REFERENCES users(id)    ON DELETE CASCADE,
  role       VARCHAR(20) DEFAULT 'member',
  PRIMARY KEY (project_id, user_id)
);

-- ─── tickets ───────────────────────────────────────────────────────────
CREATE TABLE tickets (
  id             VARCHAR(30)  PRIMARY KEY,
  project_id     VARCHAR(20)  REFERENCES projects(id) ON DELETE CASCADE,
  title          VARCHAR(500) NOT NULL,
  description    TEXT,
  type           VARCHAR(20)  DEFAULT 'task',
  status         VARCHAR(50)  DEFAULT 'Backlog',
  priority       VARCHAR(20)  DEFAULT 'medium',
  effort         VARCHAR(20)  DEFAULT 'moderate',
  assignee_id    VARCHAR(10)  REFERENCES users(id),
  reporter_id    VARCHAR(10)  REFERENCES users(id),
  due_date       DATE,
  epic_id        VARCHAR(30)  REFERENCES tickets(id),
  sprint_id      VARCHAR(30),
  story_points   INT,
  watchers       JSONB        DEFAULT '[]'::jsonb,
  labels         JSONB        DEFAULT '[]'::jsonb,
  backlog_order  INT          DEFAULT 0,
  created_at     TIMESTAMPTZ  DEFAULT NOW(),
  updated_at     TIMESTAMPTZ  DEFAULT NOW()
);

CREATE INDEX idx_tickets_project  ON tickets(project_id);
CREATE INDEX idx_tickets_status   ON tickets(status);
CREATE INDEX idx_tickets_assignee ON tickets(assignee_id);
CREATE INDEX idx_tickets_sprint   ON tickets(sprint_id);

-- ─── labels ────────────────────────────────────────────────────────────
CREATE TABLE labels (
  id         VARCHAR(30)  PRIMARY KEY,
  project_id VARCHAR(20)  REFERENCES projects(id) ON DELETE CASCADE,
  name       VARCHAR(100) NOT NULL,
  color      VARCHAR(20)  DEFAULT '#6b7280'
);

-- ─── sprints ───────────────────────────────────────────────────────────
CREATE TABLE sprints (
  id         VARCHAR(30)  PRIMARY KEY,
  project_id VARCHAR(20)  REFERENCES projects(id) ON DELETE CASCADE,
  name       VARCHAR(200),
  goal       TEXT,
  start_date DATE,
  end_date   DATE,
  status     VARCHAR(20)  DEFAULT 'planning'   -- planning, active, completed
);

-- ─── comments ──────────────────────────────────────────────────────────
CREATE TABLE comments (
  id         VARCHAR(30)  PRIMARY KEY,
  ticket_id  VARCHAR(30)  REFERENCES tickets(id) ON DELETE CASCADE,
  user_id    VARCHAR(10)  REFERENCES users(id),
  body       TEXT NOT NULL,
  created_at TIMESTAMPTZ  DEFAULT NOW(),
  updated_at TIMESTAMPTZ  DEFAULT NOW()
);

CREATE INDEX idx_comments_ticket ON comments(ticket_id);

-- ─── history (audit log) ───────────────────────────────────────────────
CREATE TABLE history (
  id         VARCHAR(30)  PRIMARY KEY,
  ticket_id  VARCHAR(30),
  user_id    VARCHAR(10),
  event      VARCHAR(50),
  field      VARCHAR(50),
  from_val   TEXT,
  to_val     TEXT,
  timestamp  TIMESTAMPTZ  DEFAULT NOW()
);

CREATE INDEX idx_history_ticket ON history(ticket_id);

-- ─── notifications ─────────────────────────────────────────────────────
CREATE TABLE notifications (
  id         VARCHAR(30)  PRIMARY KEY,
  user_id    VARCHAR(10)  REFERENCES users(id) ON DELETE CASCADE,
  ticket_id  VARCHAR(30),
  message    TEXT,
  read       BOOLEAN      DEFAULT FALSE,
  created_at TIMESTAMPTZ  DEFAULT NOW()
);

CREATE INDEX idx_notifications_user ON notifications(user_id, read);

-- ════════════════════════════════════════════════════════════════════════
--  SEED DATA
-- ════════════════════════════════════════════════════════════════════════

-- ─── Users ───
-- pin_hash values are valid bcrypt hashes for the PINs below, produced with
--   php -r "echo password_hash('654321', PASSWORD_BCRYPT);"
--   rojas / 654321  · admin
--   smith / 112233  · member
--   brown / 999999  · viewer
INSERT INTO users (id, username, display_name, first_name, last_name, role, clearance, org, pin_hash) VALUES
  ('rojas', 'rojas', 'Jessica Rojas', 'Jessica', 'Rojas', 'admin',  'SECRET',       'DIA',  '$2y$12$dHw..V78PS.pRGT3Dqc4r..8et.mxmWGtb0A2K137HQ/FVZK0g.s2'),
  ('smith', 'smith', 'John Smith',    'John',    'Smith', 'member', 'TS/SCI',       'NSA',  '$2y$12$6c7mDFKaoCNAWHMuAh4WB.Ibt88/h28iTW1SF3/P0h1EPDN8QwwUW'),
  ('brown', 'brown', 'Sarah Brown',   'Sarah',   'Brown', 'viewer', 'UNCLASSIFIED', 'DISA', '$2y$12$G69IfpcRABdTiLLEKa/uc.kaW2vd2nWCM/G/TjPIAFyG8VN.egCfK');

-- The auth handler ALSO accepts the default PIN fallback when
-- the env var NEXUS_ALLOW_DEFAULT_PINS=1. This is convenient for first-run
-- Render deploys. After first login, run `php scripts/hash-pins.php` to
-- regenerate fresh bcrypt hashes if desired.

-- ─── Project ───
INSERT INTO projects (id, key, name, description, color, icon, lead_id) VALUES
  ('proj_sec', 'SEC', 'Security Platform',
   'Core security platform initiatives, hardening, and compliance work for the DoD program.',
   '#6366f1', '🛡️', 'rojas');

-- ─── Project members ───
INSERT INTO project_members (project_id, user_id, role) VALUES
  ('proj_sec', 'rojas', 'admin'),
  ('proj_sec', 'smith', 'member'),
  ('proj_sec', 'brown', 'viewer');

-- ─── Labels ───
INSERT INTO labels (id, project_id, name, color) VALUES
  ('lbl_bug',    'proj_sec', 'bug',           '#ef4444'),
  ('lbl_feat',   'proj_sec', 'feature',       '#3b82f6'),
  ('lbl_enh',    'proj_sec', 'enhancement',   '#22c55e'),
  ('lbl_doc',    'proj_sec', 'documentation', '#9ca3af'),
  ('lbl_blk',    'proj_sec', 'blocked',       '#f97316'),
  ('lbl_sec',    'proj_sec', 'security',      '#a855f7');

-- ─── Tickets ───
INSERT INTO tickets (id, project_id, title, description, type, status, priority, effort, assignee_id, reporter_id, due_date, story_points, labels) VALUES
  ('SEC-001', 'proj_sec',
   'Implement zero-trust microsegmentation for east-west traffic',
   'Deploy SDN-based microsegmentation between application tiers. Define identity-based ACLs, enable mTLS for all internal services, and integrate with the identity provider for dynamic policy enforcement.',
   'epic',  'In Progress', 'critical', 'intensive',   'rojas', 'rojas', CURRENT_DATE + 14, 21,
   '["lbl_sec","lbl_feat"]'::jsonb),

  ('SEC-002', 'proj_sec',
   'Patch CVE-2024-XXXX in libssl on production gateways',
   'Critical OpenSSL CVE affecting all internet-facing gateways. Coordinated patch rollout required.',
   'bug',   'In Review',   'critical', 'moderate',    'rojas', 'smith', CURRENT_DATE + 2,  5,
   '["lbl_bug","lbl_sec"]'::jsonb),

  ('SEC-003', 'proj_sec',
   'Add automated SBOM generation to CI pipeline',
   'Generate CycloneDX SBOM for every release artifact and store in the artifact registry alongside SHA-256 hashes for supply-chain transparency.',
   'story', 'To Do',       'high',     'moderate',    'smith', 'rojas', CURRENT_DATE + 10, 8,
   '["lbl_feat","lbl_enh"]'::jsonb),

  ('SEC-004', 'proj_sec',
   'Update runbook for incident escalation procedures',
   'Document new on-call rotation, paging tiers, and command-line bridge join procedure.',
   'task',  'Done',        'medium',   'minimal',     'brown', 'rojas', CURRENT_DATE - 3,  2,
   '["lbl_doc"]'::jsonb),

  ('SEC-005', 'proj_sec',
   'Enable FIDO2/WebAuthn for admin portal',
   'Replace TOTP with phishing-resistant FIDO2 keys for all administrative roles. Update enrollment flow and document fallback path.',
   'story', 'Backlog',     'high',     'substantial', NULL,    'rojas', CURRENT_DATE + 21, 13,
   '["lbl_feat","lbl_sec"]'::jsonb),

  ('SEC-006', 'proj_sec',
   'Investigate intermittent OCSP responder timeouts',
   'Users reporting sporadic certificate validation delays. Need packet captures + responder log correlation.',
   'bug',   'Blocked',     'high',     'substantial', 'smith', 'brown', CURRENT_DATE + 5,  8,
   '["lbl_bug","lbl_blk"]'::jsonb),

  ('SEC-007', 'proj_sec',
   'Refactor legacy log shipper to use OpenTelemetry',
   'Replace the custom syslog forwarder with OTLP exporters. Maintain backward compatibility for the duration of a 30-day soak.',
   'task',  'To Do',       'low',      'intensive',   NULL,    'rojas', NULL,              13,
   '["lbl_enh"]'::jsonb),

  ('SEC-008', 'proj_sec',
   'Add color-blind-friendly palette to dashboards',
   'Quick accessibility improvement: swap the existing red/green status palette for a colorblind-safe set.',
   'task',  'Backlog',     'low',      'minimal',     'brown', 'smith', CURRENT_DATE + 30, 1,
   '["lbl_enh","lbl_doc"]'::jsonb);

-- ─── Seed history ───
INSERT INTO history (id, ticket_id, user_id, event, field, from_val, to_val, timestamp) VALUES
  ('hist_001', 'SEC-001', 'rojas', 'created',       NULL,     NULL,          NULL,        NOW() - INTERVAL '48 hours'),
  ('hist_002', 'SEC-004', 'brown', 'status_change', 'status', 'In Review',   'Done',      NOW() - INTERVAL '24 hours'),
  ('hist_003', 'SEC-002', 'rojas', 'status_change', 'status', 'In Progress', 'In Review', NOW() - INTERVAL '1 hour');

-- ─── Seed comments ───
INSERT INTO comments (id, ticket_id, user_id, body, created_at) VALUES
  ('cmt_001', 'SEC-001', 'rojas',
   'First phase rollout starts next sprint. Coordinating with the platform team.',
   NOW() - INTERVAL '6 hours'),
  ('cmt_002', 'SEC-002', 'smith',
   'Reviewed the patch. LGTM — happy to merge after the staging soak.',
   NOW() - INTERVAL '30 minutes');
