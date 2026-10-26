<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Local (username/password) accounts alongside Entra ID, and explicit per-user global
 * roles (in addition to roles mapped from directory groups).
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261026000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local accounts with password credentials; explicit user roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER COLUMN idp_tenant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN idp_object_id DROP NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN IF NOT EXISTS username text NULL');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT ck_app_user_source');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT ck_app_user_source CHECK (source IN ('LOGIN', 'DIRECTORY_SYNC', 'LOCAL'))");
        // Exactly one identity: an Entra identity (tenant + object ID) or a local username.
        $this->addSql(<<<'SQL'
            ALTER TABLE app_user ADD CONSTRAINT ck_app_user_identity CHECK (
                (idp_tenant_id IS NOT NULL AND idp_object_id IS NOT NULL AND username IS NULL)
                OR (idp_tenant_id IS NULL AND idp_object_id IS NULL AND username IS NOT NULL))
            SQL);
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT ck_app_user_username CHECK (username IS NULL OR username ~ '^[a-z0-9][a-z0-9._-]{2,63}$')");
        $this->addSql('CREATE UNIQUE INDEX uq_app_user_username ON app_user (username) WHERE username IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE local_credential (
                user_id             uuid PRIMARY KEY REFERENCES app_user (id),
                password_hash       text NOT NULL,
                must_change         boolean NOT NULL DEFAULT true,
                failed_attempts     integer NOT NULL DEFAULT 0,
                locked_until        timestamptz NULL,
                password_changed_at timestamptz NOT NULL DEFAULT now(),
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_local_credential_attempts CHECK (failed_attempts >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_role (
                user_id    uuid NOT NULL REFERENCES app_user (id),
                role       text NOT NULL,
                granted_by uuid NULL REFERENCES app_user (id),
                granted_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (user_id, role),
                CONSTRAINT ck_user_role_role CHECK (role IN ('SYSTEM_ADMINISTRATOR', 'PAL_ADMINISTRATOR', 'PUBLISHER', 'AUTHOR'))
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Development only: never run down-migrations against real data.
        $this->addSql('DROP TABLE user_role');
        $this->addSql('DROP TABLE local_credential');
        $this->addSql('DROP INDEX uq_app_user_username');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT ck_app_user_username');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT ck_app_user_identity');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT ck_app_user_source');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT ck_app_user_source CHECK (source IN ('LOGIN', 'DIRECTORY_SYNC'))");
        $this->addSql('ALTER TABLE app_user DROP COLUMN username');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN idp_tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN idp_object_id SET NOT NULL');
    }
}
