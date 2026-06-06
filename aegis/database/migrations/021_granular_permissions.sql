-- Migrate old-style grants to granular equivalents
INSERT INTO user_permissions (user_id, module, permission)
SELECT user_id, module,
  CASE permission
    WHEN 'read'  THEN 'view'
    WHEN 'write' THEN 'create'
    WHEN 'edit'  THEN 'edit'
  END
FROM user_permissions
WHERE permission IN ('read','write','edit')
ON CONFLICT DO NOTHING;

DELETE FROM user_permissions WHERE permission IN ('read','write','edit');
