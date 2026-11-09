<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the OWNER (superuser / god mode) global role as an explicit per-user grant, and an
 * index supporting the ordered user-administration listing.
 *
 * OWNER is only ever an explicit user_role grant: it is intentionally NOT added to
 * ck_global_role_mapping_role, so a directory group can never confer god mode through sync.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261109000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OWNER superuser role (explicit user_role grants only) and a user-list index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_role DROP CONSTRAINT ck_user_role_role');
        $this->addSql("ALTER TABLE user_role ADD CONSTRAINT ck_user_role_role CHECK (role IN ('OWNER', 'SYSTEM_ADMINISTRATOR', 'PAL_ADMINISTRATOR', 'PUBLISHER', 'AUTHOR'))");

        // The users admin list orders by display_name under a LIMIT; a btree makes that an
        // index scan instead of a full sort as the directory grows.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_app_user_display_name ON app_user (display_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_app_user_display_name');
        // Any existing OWNER grants must go before the stricter constraint can be restored.
        $this->addSql("DELETE FROM user_role WHERE role = 'OWNER'");
        $this->addSql('ALTER TABLE user_role DROP CONSTRAINT ck_user_role_role');
        $this->addSql("ALTER TABLE user_role ADD CONSTRAINT ck_user_role_role CHECK (role IN ('SYSTEM_ADMINISTRATOR', 'PAL_ADMINISTRATOR', 'PUBLISHER', 'AUTHOR'))");
    }
}
