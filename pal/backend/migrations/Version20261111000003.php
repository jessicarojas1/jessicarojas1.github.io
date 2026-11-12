<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Images in editor content (decision #29). Content refers to images by SHA-256; these tables
 * record which stored files each revision and each template version shows, so that
 *  - storage clean-up (never-attached uploads) never reclaims an image in use (also enforced
 *    by the foreign keys to stored_file),
 *  - the image endpoint can check that an image belongs to something the reader may see,
 *  - release can re-verify the image bytes an approval covered.
 * revision_image changes only while the revision is a DRAFT (same trigger as revision_file);
 * template_version_image is write-once like the versions themselves.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261111000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Images in editor content: revision and template-version image references';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS revision_image (
                revision_id uuid NOT NULL REFERENCES revision (id),
                sha256      char(64) NOT NULL REFERENCES stored_file (sha256),
                PRIMARY KEY (revision_id, sha256)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_revision_image_sha ON revision_image (sha256)');
        $this->addSql('CREATE TRIGGER trg_revision_image_draft_only BEFORE INSERT OR UPDATE OR DELETE ON revision_image FOR EACH ROW EXECUTE FUNCTION pal_revision_file_draft_only()');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS template_version_image (
                template_id uuid NOT NULL,
                version     integer NOT NULL,
                sha256      char(64) NOT NULL REFERENCES stored_file (sha256),
                PRIMARY KEY (template_id, version, sha256),
                FOREIGN KEY (template_id, version) REFERENCES document_template_version (template_id, version)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_template_version_image_sha ON template_version_image (sha256)');
        $this->addSql('CREATE TRIGGER trg_template_version_image_immutable BEFORE UPDATE OR DELETE ON template_version_image FOR EACH ROW EXECUTE FUNCTION pal_template_version_immutable()');
        $this->addSql('CREATE TRIGGER trg_template_version_image_no_truncate BEFORE TRUNCATE ON template_version_image FOR EACH STATEMENT EXECUTE FUNCTION pal_template_version_immutable()');
    }

    public function down(Schema $schema): void
    {
        // Development only: never run down-migrations against real data.
        $this->addSql('DROP TABLE IF EXISTS template_version_image');
        $this->addSql('DROP TABLE IF EXISTS revision_image');
    }
}
