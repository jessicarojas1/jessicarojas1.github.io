-- =====================================================================
-- AeroMarkup — Full PostgreSQL Schema
-- Aircraft / aerospace / manufacturing drawing & annotation tool
-- ---------------------------------------------------------------------
-- Idempotent: safe to run on a fresh database or to re-apply.
-- Targets PostgreSQL 13+ (Render Postgres, AWS RDS for PostgreSQL in
-- GovCloud, Azure Database for PostgreSQL in Azure Government).
--
-- Run:
--   psql "$DATABASE_URL" -f db/schema.sql
-- =====================================================================

BEGIN;

-- ---- Extensions -----------------------------------------------------
-- pgcrypto provides gen_random_uuid(); available on Render, RDS, Azure.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---- Reusable updated_at trigger function ---------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS trigger AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- users — optional local auth / attribution
-- =====================================================================
CREATE TABLE IF NOT EXISTS users (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  username      text NOT NULL UNIQUE,
  display_name  text,
  email         text UNIQUE,
  password_hash text,                       -- SHA-256/bcrypt; null = SSO/anon
  role          text NOT NULL DEFAULT 'editor'
                  CHECK (role IN ('admin','editor','viewer')),
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

-- =====================================================================
-- projects — top-level container (an aircraft program, a part, a line)
-- =====================================================================
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
CREATE INDEX IF NOT EXISTS idx_projects_status   ON projects(status);
CREATE INDEX IF NOT EXISTS idx_projects_owner    ON projects(owner_id);

-- =====================================================================
-- drawings — a single canvas (a view/page of an aircraft or part)
--   background_data holds the loaded reference image (data URL or blob ref)
-- =====================================================================
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
CREATE INDEX IF NOT EXISTS idx_drawings_project ON drawings(project_id);

-- =====================================================================
-- layers — optional grouping of strokes/annotations (markup vs. redline)
-- =====================================================================
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

-- =====================================================================
-- strokes — freehand sketch / scribble / highlighter paths.
--   points = JSONB array of {x,y,p} where p = pen pressure (0..1).
--   Stored as vectors so they scale on tablets and re-render crisply.
-- =====================================================================
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

-- =====================================================================
-- annotations — notes, pointers/arrows, callouts, shapes, measurements
-- =====================================================================
CREATE TABLE IF NOT EXISTS annotations (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  drawing_id  uuid NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
  layer_id    uuid REFERENCES layers(id) ON DELETE SET NULL,
  kind        text NOT NULL DEFAULT 'note'
                CHECK (kind IN ('note','arrow','callout','rect','ellipse','line','text','measure','pin')),
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
  meta        jsonb NOT NULL DEFAULT '{}'::jsonb,  -- e.g. measurement units
  client_uid  text UNIQUE,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_annotations_drawing ON annotations(drawing_id);

-- =====================================================================
-- attachments — extra reference photos/files pinned to a drawing
-- =====================================================================
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

-- =====================================================================
-- revisions — immutable snapshots of a drawing (redline history / audit)
-- =====================================================================
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
-- sync_log — change journal driving offline<->online reconciliation.
--   Devices replay rows newer than their last-seen seq to converge.
-- =====================================================================
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
DROP TRIGGER IF EXISTS trg_projects_updated     ON projects;
DROP TRIGGER IF EXISTS trg_drawings_updated     ON drawings;
DROP TRIGGER IF EXISTS trg_annotations_updated  ON annotations;

CREATE TRIGGER trg_users_updated      BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_projects_updated    BEFORE UPDATE ON projects
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_drawings_updated    BEFORE UPDATE ON drawings
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_annotations_updated BEFORE UPDATE ON annotations
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;

-- =====================================================================
-- End of schema
-- =====================================================================
