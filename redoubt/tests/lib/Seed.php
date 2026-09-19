<?php

declare(strict_types=1);

use Redoubt\Support\Db;

/**
 * Test fixture helper. Resets the schema and seeds a deterministic dataset.
 *
 * SAFETY: reset() refuses to run unless REDOUBT_TEST_DB=1 is set, so it can never
 * wipe a real database by accident. Point DATABASE_URL at a throwaway DB only.
 */
final class Seed
{
    /** Drop + recreate the public schema and load schema.sql. */
    public static function reset(): void
    {
        if (getenv('REDOUBT_TEST_DB') !== '1') {
            throw new RuntimeException('Refusing to reset: set REDOUBT_TEST_DB=1 and point DATABASE_URL at a throwaway DB.');
        }
        $pdo = Db::connection();
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema.sql');
        }
        $pdo->exec($sql);
    }

    /**
     * Seed a standard program with a PM (US), a US sub, and a non-US sub, plus
     * a spread of content used across the module tests.
     * @return array<string,int> id map
     */
    public static function fixture(): array
    {
        $gmre = Db::insert('company', ['name' => 'GMRE', 'kind' => 'prime']);
        $acme = Db::insert('company', ['name' => 'Acme Sub', 'kind' => 'sub']);
        $beta = Db::insert('company', ['name' => 'Beta Sub', 'kind' => 'sub']);

        $pm   = Db::insert('app_user', ['entra_oid' => 'oid-pm', 'display_name' => 'Pat Manager', 'email' => 'pm@gmre.us', 'kind' => 'internal', 'is_us_person' => true, 'status' => 'active']);
        $sam  = Db::insert('app_user', ['entra_oid' => 'oid-sam', 'display_name' => 'Sam Sub', 'email' => 'sam@acme.us', 'kind' => 'external', 'is_us_person' => true, 'status' => 'active']);
        $nina = Db::insert('app_user', ['entra_oid' => 'oid-nina', 'display_name' => 'Nina NonUS', 'email' => 'nina@acme.us', 'kind' => 'external', 'is_us_person' => false, 'status' => 'active']);

        $prog = Db::insert('program', ['name' => 'Falcon', 'customer' => 'USAF', 'contract_number' => 'FA', 'status' => 'active']);

        // Composite-PK tables have no id column, so use raw inserts (not Db::insert).
        $roleId = static fn (string $k): int => (int) Db::fetchOne('SELECT id FROM role WHERE key = :k', ['k' => $k])['id'];
        $addMember = static fn (int $u, string $role, ?int $co) => Db::query(
            'INSERT INTO program_membership (program_id,user_id,role_id,company_id) VALUES (:p,:u,:r,:c)',
            ['p' => $prog, 'u' => $u, 'r' => $roleId($role), 'c' => $co]
        );
        $addMember($pm, 'program_manager', $gmre);
        $addMember($sam, 'sub_member', $acme);
        $addMember($nina, 'sub_member', $acme);
        Db::query('INSERT INTO company_membership (company_id,user_id) VALUES (:c,:u)', ['c' => $acme, 'u' => $sam]);
        Db::query('INSERT INTO company_membership (company_id,user_id) VALUES (:c,:u)', ['c' => $acme, 'u' => $nina]);

        // Content
        Db::insert('document_ref', ['program_id' => $prog, 'zone' => 'project', 'company_scope' => [], 'export_controlled' => false, 'web_url' => 'https://x/o', 'title' => 'Falcon overview', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $prog, 'zone' => 'project', 'company_scope' => [], 'export_controlled' => true, 'cui_marked' => true, 'web_url' => 'https://x/c', 'title' => 'Falcon classified', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $prog, 'zone' => 'company', 'company_scope' => [$beta], 'export_controlled' => false, 'web_url' => 'https://x/b', 'title' => 'Beta only doc', 'updated_at' => date('c')]);
        Db::insert('task_order', ['program_id' => $prog, 'number' => 'TO-A', 'title' => 'Acme TO', 'status' => 'active', 'company_scope' => [$acme]]);
        Db::insert('task_order', ['program_id' => $prog, 'number' => 'TO-B', 'title' => 'Beta TO', 'status' => 'active', 'company_scope' => [$beta]]);
        Db::insert('program_contact', ['program_id' => $prog, 'freeform' => 'Falcon lead', 'role_label' => 'PM', 'visibility' => ['all']]);
        Db::insert('program_contact', ['program_id' => $prog, 'freeform' => 'Internal only', 'role_label' => 'Cyber', 'visibility' => ['internal']]);
        Db::insert('webhook_subscription', ['program_id' => $prog, 'url' => 'http://127.0.0.1:59999/h', 'secret' => 's', 'events' => ['announcement.published', 'taskorder.awarded', 'job.posted'], 'active' => true]);

        return compact('gmre', 'acme', 'beta', 'pm', 'sam', 'nina', 'prog');
    }

    /** Build an in-session $user array like Auth produces. */
    public static function user(int $id, string $kind, ?bool $usPerson, int $programId, array $roles, ?int $companyId, array $companies = [], array $grants = []): array
    {
        return [
            'id' => $id, 'entra_oid' => 'oid-' . $id, 'name' => 'User ' . $id, 'email' => 'u@x',
            'kind' => $kind, 'is_us_person' => $usPerson,
            'memberships' => [$programId => ['roles' => $roles, 'company_id' => $companyId]],
            'grants' => $grants ? [$programId => $grants] : [],
            'companies' => $companies,
        ];
    }
}
