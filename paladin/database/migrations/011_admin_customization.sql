-- Admin customization: shortcut links + custom CSS / sidebar footer settings.
CREATE TABLE IF NOT EXISTS shortcut_links (
    id         SERIAL PRIMARY KEY,
    label      VARCHAR(80) NOT NULL,
    url        VARCHAR(500) NOT NULL,
    icon       VARCHAR(40) DEFAULT 'bi-link-45deg',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
INSERT INTO settings (key, value, type, description) VALUES
    ('custom_css',     '', 'string', 'Admin-defined custom CSS injected site-wide'),
    ('sidebar_footer', '', 'string', 'Short text shown in the sidebar footer')
ON CONFLICT (key) DO NOTHING;
