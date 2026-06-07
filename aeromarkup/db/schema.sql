-- =====================================================================
-- AeroMarkup — Full PostgreSQL Schema  (Enterprise / DoD grade)
-- Aircraft / aerospace / manufacturing drawing, markup & lifecycle tool
-- ---------------------------------------------------------------------
-- Idempotent: safe to run on a fresh database OR to re-apply against a
-- populated / shared database. Uses CREATE TABLE IF NOT EXISTS,
-- ALTER TABLE ... ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS
-- and INSERT ... ON CONFLICT DO NOTHING throughout.
--
-- Targets PostgreSQL 13+ (Render Postgres, AWS RDS for PostgreSQL in
-- GovCloud, Azure Database for PostgreSQL in Azure Government).
--
-- This is the authoritative manual-setup reference. The app applies this
-- same file automatically at boot when AUTO_MIGRATE=1 (see server.py).
--
-- Run:
--   psql "$DATABASE_URL" -f db/schema.sql
-- =====================================================================

BEGIN;

-- ---- Extensions -----------------------------------------------------
-- pgcrypto provides gen_random_uuid(); available on Render, RDS, Azure.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---- Dedicated schema (SHARED-DATABASE SAFE) ------------------------
-- All AeroMarkup objects live in their own "aeromarkup" namespace so this
-- app can run inside a database shared with other apps (e.g. APEX) without
-- colliding on common table names like "users" or "projects".
-- The app connects with search_path = aeromarkup,public (see server.py),
-- so queries stay unqualified while objects are isolated here.
CREATE SCHEMA IF NOT EXISTS aeromarkup;
SET search_path TO aeromarkup, public;

-- ---- Reusable updated_at trigger function ---------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS trigger AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- MODULE: Identity & Programs
-- =====================================================================

-- ---------------------------------------------------------------------
-- users — local auth / attribution / e-signature identity
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  username      text NOT NULL UNIQUE,
  display_name  text,
  email         text UNIQUE,
  password_hash text,                       -- SHA-256/bcrypt; null = SSO/anon
  role          text NOT NULL DEFAULT 'engineer'
                  CHECK (role IN ('admin','engineer','inspector','approver','viewer')),
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);
-- On a pre-existing DB the old role CHECK allowed ('admin','editor','viewer').
-- Migrate the constraint + default to the enterprise role set, idempotently.
DO $$
BEGIN
  -- map any legacy 'editor' role onto the new 'engineer' role first
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'aeromarkup' AND table_name = 'users' AND column_name = 'role'
  ) THEN
    UPDATE users SET role = 'engineer' WHERE role = 'editor';
  END IF;
END $$;
ALTER TABLE users ALTER COLUMN role SET DEFAULT 'engineer';
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check
  CHECK (role IN ('admin','engineer','inspector','approver','viewer'));

-- ---------------------------------------------------------------------
-- programs — top-level program / contract grouping (above projects)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS programs (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name           text NOT NULL,
  code           text UNIQUE,
  description    text,
  classification text NOT NULL DEFAULT 'CUI',
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_programs_code ON programs(code);

-- =====================================================================
-- MODULE: Projects & Drawings
-- =====================================================================

-- ---------------------------------------------------------------------
-- projects — a container under a program (an airframe, a part, a line)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name          text NOT NULL,
  description   text,
  category      text NOT NULL DEFAULT 'aerospace'
                  CHECK (category IN ('aerospace','manufacturing','maintenance','inspection','other')),
  tail_number   text,                       -- e.g. aircraft tail / serial
  part_number   text,                       -- manufacturing part / WO number
  status        text NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active','archived','review')),
  owner_id      uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);
-- Enterprise enrichments (idempotent for pre-existing installs)
ALTER TABLE projects ADD COLUMN IF NOT EXISTS program_id     uuid;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS serial_number  text;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS work_order     text;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS classification text NOT NULL DEFAULT 'CUI';
ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_classification_check;
ALTER TABLE projects ADD CONSTRAINT projects_classification_check
  CHECK (classification IN ('UNCLASSIFIED','CUI','CUI//SP-PROPIN','UNCLASS//FOUO'));
-- FK to programs (added separately so the table can pre-date programs)
ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_program_fk;
ALTER TABLE projects ADD CONSTRAINT projects_program_fk
  FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_projects_status  ON projects(status);
CREATE INDEX IF NOT EXISTS idx_projects_owner   ON projects(owner_id);
CREATE INDEX IF NOT EXISTS idx_projects_program ON projects(program_id);

-- ---------------------------------------------------------------------
-- drawings — a single canvas (a view/page of an aircraft or part)
--   background_data holds the loaded reference image (data URL or blob ref)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drawings (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id      uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  title           text NOT NULL DEFAULT 'Untitled Drawing',
  -- background reference image (engineering drawing / photo of the aircraft)
  background_kind text NOT NULL DEFAULT 'none'
                    CHECK (background_kind IN ('none','image','pdf','blank')),
  background_data text,                      -- data URL or object-store key
  background_name text,
  width           integer NOT NULL DEFAULT 1600,
  height          integer NOT NULL DEFAULT 1200,
  -- monotonically increasing version used for offline<->online merge
  version         bigint NOT NULL DEFAULT 1,
  -- client-generated id so an offline device can create then sync
  client_uid      text UNIQUE,
  created_by      uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
-- Enterprise enrichments (idempotent for pre-existing installs)
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS drawing_number text;
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS revision       text NOT NULL DEFAULT 'A';
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS units          text NOT NULL DEFAULT 'in';
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS scale_ratio    real;  -- real units / canvas px
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS status         text NOT NULL DEFAULT 'draft';
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS classification text NOT NULL DEFAULT 'CUI';
-- 3D model support (STL / OBJ import + 3D pin annotations)
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS view_kind     text NOT NULL DEFAULT '2d';
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS model_format  text;
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS model_name    text;
ALTER TABLE drawings ADD COLUMN IF NOT EXISTS model_data    text;  -- data URL / object-store key
ALTER TABLE drawings DROP CONSTRAINT IF EXISTS drawings_view_kind_check;
ALTER TABLE drawings ADD CONSTRAINT drawings_view_kind_check CHECK (view_kind IN ('2d','3d'));
ALTER TABLE drawings DROP CONSTRAINT IF EXISTS drawings_units_check;
ALTER TABLE drawings ADD CONSTRAINT drawings_units_check
  CHECK (units IN ('in','mm','cm','ft'));
ALTER TABLE drawings DROP CONSTRAINT IF EXISTS drawings_status_check;
ALTER TABLE drawings ADD CONSTRAINT drawings_status_check
  CHECK (status IN ('draft','in_review','approved','released','obsolete'));
CREATE INDEX IF NOT EXISTS idx_drawings_project ON drawings(project_id);
CREATE INDEX IF NOT EXISTS idx_drawings_status  ON drawings(status);

-- ---------------------------------------------------------------------
-- layers — optional grouping of strokes/annotations (markup vs. redline)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS layers (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  name        text NOT NULL DEFAULT 'Markup',
  color       text NOT NULL DEFAULT '#ff5811',
  visible     boolean NOT NULL DEFAULT true,
  locked      boolean NOT NULL DEFAULT false,
  z_index     integer NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_layers_drawing ON layers(drawing_id);

-- ---------------------------------------------------------------------
-- strokes — freehand sketch / scribble / highlighter paths.
--   points = JSONB array of {x,y,p} where p = pen pressure (0..1).
--   Stored as vectors so they scale on tablets and re-render crisply.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS strokes (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  layer_id    uuid REFERENCES layers(id) ON DELETE SET NULL,
  tool        text NOT NULL DEFAULT 'pen'
                CHECK (tool IN ('pen','highlighter','marker','eraser')),
  color       text NOT NULL DEFAULT '#ff5811',
  width       real NOT NULL DEFAULT 3,
  opacity     real NOT NULL DEFAULT 1.0,
  points      jsonb NOT NULL DEFAULT '[]'::jsonb,
  client_uid  text UNIQUE,                   -- dedupe on offline sync
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_strokes_drawing ON strokes(drawing_id);

-- ---------------------------------------------------------------------
-- annotations — notes, pointers/arrows, callouts, shapes, measurements
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS annotations (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  layer_id    uuid REFERENCES layers(id) ON DELETE SET NULL,
  kind        text NOT NULL DEFAULT 'note'
                CHECK (kind IN ('note','arrow','callout','rect','ellipse','line','text','measure','pin',
                                'cloud','area','angle','stamp','balloon','pin3d')),
  -- geometry: anchor + optional target (for arrows/pointers), in canvas px
  x           real NOT NULL DEFAULT 0,
  y           real NOT NULL DEFAULT 0,
  x2          real,
  y2          real,
  width       real,
  height      real,
  text        text,
  color       text NOT NULL DEFAULT '#ffcc00',
  stroke_w    real NOT NULL DEFAULT 2,
  font_size   real NOT NULL DEFAULT 16,
  meta        jsonb NOT NULL DEFAULT '{}'::jsonb,  -- e.g. measurement value/unit
  client_uid  text UNIQUE,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_annotations_drawing ON annotations(drawing_id);
-- broaden annotation kinds for pre-existing installs (shapes/stamps/balloons/3D pins sync here)
ALTER TABLE annotations DROP CONSTRAINT IF EXISTS annotations_kind_check;
ALTER TABLE annotations ADD CONSTRAINT annotations_kind_check
  CHECK (kind IN ('note','arrow','callout','rect','ellipse','line','text','measure','pin',
                  'cloud','area','angle','stamp','balloon','pin3d'));

-- ---------------------------------------------------------------------
-- attachments — extra reference photos/files pinned to a drawing
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  name        text NOT NULL,
  mime_type   text,
  size_bytes  bigint,
  data        text,                          -- data URL or object-store key
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_attachments_drawing ON attachments(drawing_id);

-- ---------------------------------------------------------------------
-- revisions — immutable snapshots of a drawing (redline history / audit)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revisions (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  version     bigint NOT NULL,
  snapshot    jsonb NOT NULL,                -- full strokes+annotations blob
  note        text,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_revisions_drawing ON revisions(drawing_id);

-- =====================================================================
-- MODULE: Quality / Lifecycle — NCRs, Inspections, Approvals, Comments
-- =====================================================================

-- ---------------------------------------------------------------------
-- ncrs — Non-Conformance Reports (quality defects raised against a part)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ncrs (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id       uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  drawing_id       uuid REFERENCES drawings(id) ON DELETE SET NULL,
  annotation_id    uuid,                     -- optional link to a markup pin
  ncr_number       text,
  title            text NOT NULL,
  description      text,
  severity         text NOT NULL DEFAULT 'minor'
                     CHECK (severity IN ('minor','major','critical')),
  defect_type      text,
  status           text NOT NULL DEFAULT 'open'
                     CHECK (status IN ('open','in_review','dispositioned','closed')),
  disposition      text
                     CHECK (disposition IN ('use_as_is','rework','repair','scrap','return_to_vendor')),
  disposition_notes text,
  raised_by        uuid REFERENCES users(id) ON DELETE SET NULL,
  assigned_to      uuid REFERENCES users(id) ON DELETE SET NULL,
  due_date         date,
  classification   text NOT NULL DEFAULT 'CUI',
  client_uid       text UNIQUE,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_ncrs_project ON ncrs(project_id);
CREATE INDEX IF NOT EXISTS idx_ncrs_status  ON ncrs(status);

-- ---------------------------------------------------------------------
-- inspections — a quality/inspection event against a project/drawing
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspections (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  drawing_id    uuid REFERENCES drawings(id) ON DELETE SET NULL,
  type          text,
  result        text NOT NULL DEFAULT 'pending'
                  CHECK (result IN ('pass','fail','pending')),
  inspector_id  uuid REFERENCES users(id) ON DELETE SET NULL,
  performed_at  timestamptz,
  notes         text,
  client_uid    text UNIQUE,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_inspections_project ON inspections(project_id);

-- ---------------------------------------------------------------------
-- inspection_items — individual checklist line items for an inspection
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_items (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inspection_id  uuid NOT NULL REFERENCES inspections(id) ON DELETE CASCADE,
  label          text NOT NULL,
  result         text NOT NULL DEFAULT 'na'
                   CHECK (result IN ('pass','fail','na')),
  notes          text,
  sort           int NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_inspection_items_insp ON inspection_items(inspection_id);

-- ---------------------------------------------------------------------
-- approvals — e-signature / workflow trail across drawings, ncrs, etc.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approvals (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  entity_type    text CHECK (entity_type IN ('drawing','ncr','inspection')),
  entity_id      uuid NOT NULL,
  action         text CHECK (action IN ('submit','approve','reject','release')),
  actor_id       uuid REFERENCES users(id) ON DELETE SET NULL,
  actor_name     text,
  signature_hash text,                       -- hash of the signed payload
  comment        text,
  client_uid     text UNIQUE,
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_approvals_entity ON approvals(entity_type, entity_id);

-- ---------------------------------------------------------------------
-- comments — threaded discussion attached to any entity
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  entity_type  text,
  entity_id    uuid,
  author       text,
  body         text NOT NULL,
  client_uid   text UNIQUE,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_comments_entity ON comments(entity_type, entity_id);

-- =====================================================================
-- MODULE: Audit & Sync
-- =====================================================================

-- ---------------------------------------------------------------------
-- audit_log — immutable activity log (who did what, to which entity)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  seq          bigserial PRIMARY KEY,
  actor        text,
  action       text NOT NULL,
  entity_type  text,
  entity_id    uuid,
  detail       jsonb NOT NULL DEFAULT '{}'::jsonb,
  source       text,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity  ON audit_log(entity_type, entity_id);

-- ---------------------------------------------------------------------
-- sync_log — change journal driving offline<->online reconciliation.
--   Devices replay rows newer than their last-seen seq to converge.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_log (
  seq         bigserial PRIMARY KEY,
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  entity      text NOT NULL
                CHECK (entity IN ('drawing','stroke','annotation','attachment','layer')),
  entity_id   uuid NOT NULL,
  op          text NOT NULL CHECK (op IN ('create','update','delete')),
  payload     jsonb,
  device_id   text,                          -- which client pushed it
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_sync_drawing_seq ON sync_log(drawing_id, seq);

-- ---- Triggers -------------------------------------------------------
DROP TRIGGER IF EXISTS trg_users_updated       ON users;
DROP TRIGGER IF EXISTS trg_programs_updated     ON programs;
DROP TRIGGER IF EXISTS trg_projects_updated     ON projects;
DROP TRIGGER IF EXISTS trg_drawings_updated     ON drawings;
DROP TRIGGER IF EXISTS trg_annotations_updated  ON annotations;
DROP TRIGGER IF EXISTS trg_ncrs_updated         ON ncrs;

CREATE TRIGGER trg_users_updated      BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_programs_updated    BEFORE UPDATE ON programs
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_projects_updated    BEFORE UPDATE ON projects
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_drawings_updated    BEFORE UPDATE ON drawings
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_annotations_updated BEFORE UPDATE ON annotations
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_ncrs_updated        BEFORE UPDATE ON ncrs
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;

-- =====================================================================
-- End of schema
-- =====================================================================
