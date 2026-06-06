-- =====================================================================
-- AeroMarkup — Seed / demo data (optional)
-- Run after schema.sql:  psql "$DATABASE_URL" -f db/seed.sql
-- =====================================================================
BEGIN;

-- Operate inside the app's dedicated schema (shared-database safe).
SET search_path TO aeromarkup, public;

INSERT INTO users (username, display_name, email, role)
VALUES ('admin', 'Administrator', 'admin@example.gov', 'admin')
ON CONFLICT (username) DO NOTHING;

WITH u AS (SELECT id FROM users WHERE username = 'admin' LIMIT 1),
proj AS (
  INSERT INTO projects (name, description, category, tail_number, owner_id)
  SELECT 'Demo Aircraft – Tail N123AM',
         'Sample inspection markup project for a fixed-wing airframe.',
         'aerospace', 'N123AM', u.id
  FROM u
  RETURNING id
)
INSERT INTO drawings (project_id, title, background_kind, width, height, client_uid)
SELECT proj.id, 'Left Wing – Upper Skin Inspection', 'blank', 1600, 1200, 'seed-drawing-1'
FROM proj;

COMMIT;
