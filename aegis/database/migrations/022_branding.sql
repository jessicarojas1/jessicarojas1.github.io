-- Migration 022 — Settings → Branding
-- Adds the brand accent colour setting and ensures the logo/display-name
-- settings rows exist. Idempotent: safe to run multiple times.

INSERT INTO settings (key, value, type, description) VALUES
    ('org_name',          'My Organization', 'string', 'Organization / product display name (Branding)'),
    ('company_logo_data', '',                'string', 'Logo source — http(s):// URL or data:image/... URI (Branding)'),
    ('company_logo_name', '',                'string', 'Original logo filename / label (Branding)'),
    ('brand_accent',      '',                'string', 'Primary brand accent colour as #RRGGBB hex (Branding)')
ON CONFLICT (key) DO NOTHING;
