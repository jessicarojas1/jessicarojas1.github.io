<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * database/schema.sql must produce exactly the schema the migrations produce, and must be
 * idempotent. It is applied twice into a scratch schema and compared with public.
 */
final class SchemaParityTest extends KernelTestCase
{
    public function testSchemaSqlMatchesMigrationsAndIsIdempotent(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $sql = file_get_contents(\dirname(__DIR__, 3).'/database/schema.sql');
        self::assertIsString($sql);

        $db->executeStatement('DROP SCHEMA IF EXISTS parity CASCADE');
        $db->executeStatement('CREATE SCHEMA parity');
        try {
            $db->executeStatement('SET search_path TO parity');
            $db->executeStatement($sql);
            $db->executeStatement($sql);
            $db->executeStatement('SET search_path TO public');

            self::assertSame($this->describe($db, 'public'), $this->describe($db, 'parity'));
            self::assertSame('"America/Denver"', $db->fetchOne("SELECT value::text FROM parity.org_setting WHERE key = 'default_timezone'"));
        } finally {
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA parity CASCADE');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function describe(Connection $db, string $schema): array
    {
        $columns = $db->fetchFirstColumn(<<<'SQL'
            SELECT table_name || '.' || column_name || ' ' || data_type || COALESCE('(' || character_maximum_length || ')', '')
                   || ' null=' || is_nullable || ' default=' || COALESCE(regexp_replace(column_default, '[a-z_]+\.', '', 'g'), '-')
                   || ' identity=' || COALESCE(identity_generation, '-')
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name <> 'doctrine_migration_versions'
            ORDER BY 1
            SQL, [$schema]);
        $indexes = $db->fetchFirstColumn(
            "SELECT tablename || ' ' || replace(indexdef, ' ' || schemaname || '.', ' ') FROM pg_indexes WHERE schemaname = ? AND tablename <> 'doctrine_migration_versions' ORDER BY 1",
            [$schema],
        );
        $constraints = $db->fetchFirstColumn(<<<'SQL'
            SELECT rel.relname || ' ' || con.conname || ' ' || regexp_replace(pg_get_constraintdef(con.oid), '[a-z_]+\.', '', 'g')
            FROM pg_constraint con JOIN pg_class rel ON rel.oid = con.conrelid JOIN pg_namespace n ON n.oid = rel.relnamespace
            WHERE n.nspname = ? AND rel.relname <> 'doctrine_migration_versions'
            ORDER BY 1
            SQL, [$schema]);
        $triggers = $db->fetchFirstColumn(
            "SELECT event_object_table || ' ' || trigger_name || ' ' || event_manipulation || ' ' || action_timing FROM information_schema.triggers WHERE trigger_schema = ? ORDER BY 1",
            [$schema],
        );

        return ['columns' => array_map('strval', $columns), 'indexes' => array_map('strval', $indexes), 'constraints' => array_map('strval', $constraints), 'triggers' => array_map('strval', $triggers)];
    }
}
