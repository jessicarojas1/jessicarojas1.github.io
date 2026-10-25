<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 foundation: identity, directory groups, global role mappings,
 * organisation settings, append-only audit trail, sessions and the Messenger queue.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261026000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 foundation: users, directory groups, role mappings, settings, audit, sessions, queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                idp_tenant_id       uuid NOT NULL,
                idp_object_id       uuid NOT NULL,
                display_name        text NOT NULL,
                email               text NULL,
                user_principal_name text NULL,
                timezone            text NULL,
                is_active           boolean NOT NULL DEFAULT true,
                source              text NOT NULL,
                first_login_at      timestamptz NULL,
                last_login_at       timestamptz NULL,
                directory_synced_at timestamptz NULL,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_app_user_idp UNIQUE (idp_tenant_id, idp_object_id),
                CONSTRAINT ck_app_user_display_name CHECK (char_length(display_name) BETWEEN 1 AND 256),
                CONSTRAINT ck_app_user_source CHECK (source IN ('LOGIN', 'DIRECTORY_SYNC'))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE directory_group (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                idp_tenant_id uuid NOT NULL,
                idp_object_id uuid NOT NULL,
                display_name  text NOT NULL,
                tracked       boolean NOT NULL DEFAULT true,
                synced_at     timestamptz NULL,
                sync_error    text NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_directory_group_idp UNIQUE (idp_tenant_id, idp_object_id)
            )
            SQL);

        // Membership history is kept (valid_to) so "who could read this on date X?" stays answerable.
        $this->addSql(<<<'SQL'
            CREATE TABLE group_membership (
                id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                group_id   uuid NOT NULL REFERENCES directory_group (id),
                user_id    uuid NOT NULL REFERENCES app_user (id),
                valid_from timestamptz NOT NULL DEFAULT now(),
                valid_to   timestamptz NULL,
                CONSTRAINT ck_group_membership_period CHECK (valid_to IS NULL OR valid_to > valid_from)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_group_membership_open ON group_membership (group_id, user_id) WHERE valid_to IS NULL');
        $this->addSql('CREATE INDEX ix_group_membership_user_open ON group_membership (user_id) WHERE valid_to IS NULL');

        // Reader is implicit for every active user, so it is not mappable.
        $this->addSql(<<<'SQL'
            CREATE TABLE global_role_mapping (
                id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                role       text NOT NULL,
                group_id   uuid NOT NULL REFERENCES directory_group (id),
                created_by uuid NULL REFERENCES app_user (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_global_role_mapping UNIQUE (role, group_id),
                CONSTRAINT ck_global_role_mapping_role CHECK (role IN ('SYSTEM_ADMINISTRATOR', 'PAL_ADMINISTRATOR', 'PUBLISHER', 'AUTHOR'))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE org_setting (
                key        text PRIMARY KEY,
                value      jsonb NOT NULL,
                updated_by uuid NULL REFERENCES app_user (id),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_org_setting_key CHECK (key ~ '^[a-z][a-z0-9_.]{1,63}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO org_setting (key, value) VALUES ('default_timezone', '"America/Denver"')
            ON CONFLICT (key) DO NOTHING
            SQL);

        // Append-only, hash-chained audit trail. The triggers block UPDATE/DELETE/TRUNCATE even
        // for the table owner; production additionally grants the app role INSERT/SELECT only.
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_event (
                id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_id       uuid NOT NULL,
                occurred_at    timestamptz NOT NULL,
                event_type     text NOT NULL,
                actor_user_id  uuid NULL REFERENCES app_user (id),
                actor_snapshot jsonb NULL,
                correlation_id text NULL,
                subject_type   text NULL,
                subject_id     text NULL,
                from_state     text NULL,
                to_state       text NULL,
                changes        jsonb NULL,
                reason         text NULL,
                prev_hash      char(64) NOT NULL,
                hash           char(64) NOT NULL,
                CONSTRAINT uq_audit_event_event_id UNIQUE (event_id),
                CONSTRAINT uq_audit_event_hash UNIQUE (hash),
                CONSTRAINT ck_audit_event_type CHECK (event_type ~ '^[A-Z][A-Z0-9_]{2,63}$')
            )
            SQL);
        $this->addSql('CREATE INDEX ix_audit_event_occurred_at ON audit_event (occurred_at)');
        $this->addSql('CREATE INDEX ix_audit_event_actor ON audit_event (actor_user_id, occurred_at)');
        $this->addSql('CREATE INDEX ix_audit_event_type ON audit_event (event_type, occurred_at)');
        $this->addSql('CREATE INDEX ix_audit_event_subject ON audit_event (subject_type, subject_id)');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_audit_event_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_event is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_audit_event_no_update_delete BEFORE UPDATE OR DELETE ON audit_event FOR EACH ROW EXECUTE FUNCTION pal_audit_event_immutable()');
        $this->addSql('CREATE TRIGGER trg_audit_event_no_truncate BEFORE TRUNCATE ON audit_event FOR EACH STATEMENT EXECUTE FUNCTION pal_audit_event_immutable()');

        // Symfony PdoSessionHandler schema for PostgreSQL.
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id       varchar(128) NOT NULL PRIMARY KEY,
                sess_data     bytea NOT NULL,
                sess_lifetime integer NOT NULL,
                sess_time     integer NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX ix_sessions_lifetime ON sessions (sess_lifetime)');

        // Symfony Messenger Doctrine transport schema (generated by messenger:setup-transports).
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                body         text NOT NULL,
                headers      text NOT NULL,
                queue_name   varchar(190) NOT NULL,
                created_at   timestamp(0) without time zone NOT NULL,
                available_at timestamp(0) without time zone NOT NULL,
                delivered_at timestamp(0) without time zone DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX ix_messenger_messages_queue ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE audit_event');
        $this->addSql('DROP FUNCTION pal_audit_event_immutable()');
        $this->addSql('DROP TABLE org_setting');
        $this->addSql('DROP TABLE global_role_mapping');
        $this->addSql('DROP TABLE group_membership');
        $this->addSql('DROP TABLE directory_group');
        $this->addSql('DROP TABLE app_user');
    }
}
