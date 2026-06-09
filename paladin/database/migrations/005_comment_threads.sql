-- Comment threading uses the existing comments.parent_id; add resolve tracking.
ALTER TABLE comments ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP;
ALTER TABLE comments ADD COLUMN IF NOT EXISTS resolved_by INTEGER REFERENCES users(id);
