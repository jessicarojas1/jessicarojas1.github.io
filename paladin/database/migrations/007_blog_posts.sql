-- Blog posts (Confluence-style "Blog" content type): date-stamped, per-space.
CREATE TABLE IF NOT EXISTS blog_posts (
    id           SERIAL PRIMARY KEY,
    space_id     INTEGER REFERENCES spaces(id) ON DELETE CASCADE,
    title        VARCHAR(255) NOT NULL,
    slug         VARCHAR(255),
    body         TEXT,
    status       VARCHAR(20) NOT NULL DEFAULT 'draft',   -- draft | published
    author_id    INTEGER REFERENCES users(id),
    published_at TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_blog_space  ON blog_posts(space_id);
CREATE INDEX IF NOT EXISTS idx_blog_status ON blog_posts(status, published_at DESC);
