-- =====================================================================
-- AeroMarkup — Seed / demo data (optional)
-- Run after schema.sql:  psql "$DATABASE_URL" -f db/seed.sql
-- ---------------------------------------------------------------------
-- Idempotent: re-running does not duplicate rows (ON CONFLICT DO NOTHING
-- against stable username / code / client_uid keys).
-- =====================================================================
BEGIN;

-- Operate inside the app's dedicated schema (shared-database safe).
SET search_path TO aeromarkup, public;

-- ---- Admin user -----------------------------------------------------
INSERT INTO users (username, display_name, email, role)
VALUES ('admin', 'Administrator', 'admin@example.gov', 'admin')
ON CONFLICT (username) DO NOTHING;

-- ---- Program --------------------------------------------------------
INSERT INTO programs (name, code, description, classification)
VALUES ('F-XX Wing Assembly', 'PRG-001',
        'Composite wing assembly program for the F-XX airframe.', 'CUI')
ON CONFLICT (code) DO NOTHING;

-- ---- Project (linked to program + owner) ----------------------------
WITH u AS (SELECT id FROM users WHERE username = 'admin' LIMIT 1),
     p AS (SELECT id FROM programs WHERE code = 'PRG-001' LIMIT 1)
INSERT INTO projects (name, description, category, tail_number, part_number,
                      serial_number, work_order, classification,
                      program_id, owner_id)
SELECT 'Demo Aircraft – Tail N123AM',
       'Sample inspection markup project for a fixed-wing airframe.',
       'aerospace', 'N123AM', 'PN-44182-LWS', 'SN-2026-0007', 'WO-778812',
       'CUI', p.id, u.id
FROM u, p
WHERE NOT EXISTS (
  SELECT 1 FROM projects WHERE tail_number = 'N123AM'
);

-- ---- Drawing (with drawing number / revision / status) --------------
WITH proj AS (SELECT id FROM projects WHERE tail_number = 'N123AM' LIMIT 1)
INSERT INTO drawings (project_id, title, drawing_number, revision, units,
                      status, classification, background_kind,
                      width, height, client_uid)
SELECT proj.id, 'Left Wing – Upper Skin Inspection',
       'DWG-LWS-001', 'A', 'in', 'in_review', 'CUI', 'blank',
       1600, 1200, 'seed-drawing-1'
FROM proj
ON CONFLICT (client_uid) DO NOTHING;

-- ---- Sample NCR (open, major) ---------------------------------------
WITH proj AS (SELECT id FROM projects WHERE tail_number = 'N123AM' LIMIT 1),
     dwg  AS (SELECT id FROM drawings WHERE client_uid = 'seed-drawing-1' LIMIT 1),
     u    AS (SELECT id FROM users WHERE username = 'admin' LIMIT 1)
INSERT INTO ncrs (project_id, drawing_id, ncr_number, title, description,
                  severity, defect_type, status, raised_by, classification,
                  client_uid)
SELECT proj.id, dwg.id, 'NCR-0001',
       'Surface delamination on upper skin panel',
       'Visual inspection found a 2in delamination near the inboard rib station.',
       'major', 'delamination', 'open', u.id, 'CUI', 'seed-ncr-1'
FROM proj, dwg, u
ON CONFLICT (client_uid) DO NOTHING;

-- ---- Sample inspection + items --------------------------------------
WITH proj AS (SELECT id FROM projects WHERE tail_number = 'N123AM' LIMIT 1),
     dwg  AS (SELECT id FROM drawings WHERE client_uid = 'seed-drawing-1' LIMIT 1),
     u    AS (SELECT id FROM users WHERE username = 'admin' LIMIT 1)
INSERT INTO inspections (project_id, drawing_id, type, result,
                         inspector_id, performed_at, notes, client_uid)
SELECT proj.id, dwg.id, 'Visual / Dimensional', 'pending',
       u.id, now(), 'First-article inspection of upper skin panel.', 'seed-insp-1'
FROM proj, dwg, u
ON CONFLICT (client_uid) DO NOTHING;

WITH insp AS (SELECT id FROM inspections WHERE client_uid = 'seed-insp-1' LIMIT 1)
INSERT INTO inspection_items (inspection_id, label, result, notes, sort)
SELECT insp.id, 'Skin thickness within tolerance', 'pass', NULL, 0 FROM insp
WHERE NOT EXISTS (
  SELECT 1 FROM inspection_items it
  JOIN inspections i ON i.id = it.inspection_id
  WHERE i.client_uid = 'seed-insp-1' AND it.label = 'Skin thickness within tolerance'
);

WITH insp AS (SELECT id FROM inspections WHERE client_uid = 'seed-insp-1' LIMIT 1)
INSERT INTO inspection_items (inspection_id, label, result, notes, sort)
SELECT insp.id, 'No surface delamination', 'fail',
       'See NCR-0001.', 1 FROM insp
WHERE NOT EXISTS (
  SELECT 1 FROM inspection_items it
  JOIN inspections i ON i.id = it.inspection_id
  WHERE i.client_uid = 'seed-insp-1' AND it.label = 'No surface delamination'
);

-- ---- Sample audit log row -------------------------------------------
INSERT INTO audit_log (actor, action, entity_type, entity_id, detail, source)
SELECT 'admin', 'seed', 'ncr', n.id,
       jsonb_build_object('note', 'Demo data seeded'), 'seed.sql'
FROM ncrs n
WHERE n.client_uid = 'seed-ncr-1'
  AND NOT EXISTS (
    SELECT 1 FROM audit_log
    WHERE action = 'seed' AND entity_type = 'ncr' AND source = 'seed.sql'
  );

COMMIT;
