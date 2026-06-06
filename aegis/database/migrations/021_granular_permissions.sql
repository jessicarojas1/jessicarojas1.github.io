-- Migration 021: Granular page-level permissions
-- Converts old read/write/edit grants to new granular action grants
-- and expands the allowed modules list

-- Convert old risk grants
INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'risk', 'view', granted_by, granted_at
  FROM user_permissions WHERE module = 'risk' AND permission = 'read'
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'risk', 'create', granted_by, granted_at
  FROM user_permissions WHERE module = 'risk' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'risk', 'edit', granted_by, granted_at
  FROM user_permissions WHERE module = 'risk' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

-- Convert old compliance grants
INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'compliance', 'view', granted_by, granted_at
  FROM user_permissions WHERE module = 'compliance' AND permission = 'read'
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'compliance', 'assess', granted_by, granted_at
  FROM user_permissions WHERE module = 'compliance' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'compliance', 'create', granted_by, granted_at
  FROM user_permissions WHERE module = 'compliance' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

-- Convert old audit grants
INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'audit', 'view', granted_by, granted_at
  FROM user_permissions WHERE module = 'audit' AND permission = 'read'
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'audit', 'create', granted_by, granted_at
  FROM user_permissions WHERE module = 'audit' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'audit', 'edit', granted_by, granted_at
  FROM user_permissions WHERE module = 'audit' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'audit', 'findings', granted_by, granted_at
  FROM user_permissions WHERE module = 'audit' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

-- Convert old policy grants
INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'policy', 'view', granted_by, granted_at
  FROM user_permissions WHERE module = 'policy' AND permission = 'read'
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'policy', 'create', granted_by, granted_at
  FROM user_permissions WHERE module = 'policy' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

INSERT INTO user_permissions (user_id, module, permission, granted_by, granted_at)
SELECT user_id, 'policy', 'edit', granted_by, granted_at
  FROM user_permissions WHERE module = 'policy' AND permission IN ('write','edit')
ON CONFLICT (user_id, module, permission) DO NOTHING;

-- Delete old-style grants (read/write/edit)
DELETE FROM user_permissions WHERE permission IN ('read','write','edit');
