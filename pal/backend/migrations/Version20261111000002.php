<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Template history is evidence like the audit trail: block TRUNCATE as well as row-level
 * UPDATE/DELETE (deploy/sql/roles.sql also revokes those privileges from the app role).
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261111000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refuse TRUNCATE on template version history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TRIGGER trg_template_version_no_truncate BEFORE TRUNCATE ON document_template_version FOR EACH STATEMENT EXECUTE FUNCTION pal_template_version_immutable()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_template_version_no_truncate ON document_template_version');
    }
}
