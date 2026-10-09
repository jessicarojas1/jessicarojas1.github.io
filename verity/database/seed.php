<?php

declare(strict_types=1);

/**
 * VERITY — synthetic development data generator.
 *
 * Generates ~150 fictional people, 15 fictional applications, system
 * accounts (including privileged, disabled, orphaned/unmatched, contractor,
 * guest, and terminated-but-still-enabled scenarios), entitlements and
 * assignments (direct/inherited/privileged/temporary/external — some
 * expired), a dynamic field example, saved views, and platform users for
 * each role (section 28 of the build directive).
 *
 * ALL DATA IS FICTIONAL. Refuses to run against a database whose APP_ENV is
 * "production" (section 26 — synthetic data is for development only).
 *
 * Usage: php database/seed.php [--force]
 * --force wipes existing rows in the tables this script owns before reseeding.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Verity\Support\Config;
use Verity\Support\Db;

if (!Db::isConfigured()) {
    fwrite(STDERR, "DATABASE_URL is not configured.\n");
    exit(1);
}
if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run synthetic seed data against APP_ENV=production.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);
$pdo = Db::connection();

$tables = [
    'audit_event', 'user_permission_grant', 'app_user_role', 'saved_view',
    'dynamic_field_value', 'dynamic_field_definition', 'identity_account_link',
    'entitlement_assignment', 'entitlement', 'system_account', 'connector_sync_job',
    'connector', 'application', 'app_user', 'person_relationship', 'person', 'app_config',
];

if ($force) {
    echo "Wiping existing data in " . count($tables) . " tables...\n";
    $pdo->exec('TRUNCATE ' . implode(', ', array_map([Db::class, 'ident'], $tables)) . ' RESTART IDENTITY CASCADE');
} else {
    $existing = (int) Db::fetchValue('SELECT COUNT(*) FROM person');
    if ($existing > 0) {
        fwrite(STDERR, "person table is not empty ($existing rows). Re-run with --force to wipe and reseed.\n");
        exit(1);
    }
}

$pdo->beginTransaction();

try {
    // -------------------------------------------------------------------
    // People
    // -------------------------------------------------------------------
    $departments = [
        'Engineering', 'Information Security', 'IT Operations', 'Finance',
        'Human Resources', 'Legal & Compliance', 'Sales', 'Marketing',
        'Program Management', 'Facilities',
    ];
    $firstNames = ['James','Mary','Robert','Patricia','John','Jennifer','Michael','Linda','David','Elizabeth',
        'William','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Charles','Karen',
        'Christopher','Nancy','Daniel','Lisa','Matthew','Margaret','Anthony','Betty','Mark','Sandra',
        'Donald','Ashley','Steven','Dorothy','Paul','Kimberly','Andrew','Emily','Joshua','Donna',
        'Kenneth','Michelle','Kevin','Carol','Brian','Amanda','George','Melissa','Edward','Deborah'];
    $lastNames = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
        'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
        'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
        'Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores'];

    $personIds = [];     // index -> id

    $totalPeople = 150;
    $stmtPerson = $pdo->prepare(
        'INSERT INTO person (employee_id, first_name, last_name, display_name, email, department,
            business_unit, position_title, manager_person_id, location, identity_type, employment_status,
            start_date, termination_date, identity_authority)
         VALUES (:employee_id, :first_name, :last_name, :display_name, :email, :department,
            :business_unit, :position_title, :manager_person_id, :location, :identity_type, :employment_status,
            :start_date, :termination_date, :identity_authority) RETURNING id'
    );

    $titlesByDept = [
        'Engineering' => ['Software Engineer', 'Senior Software Engineer', 'Engineering Manager', 'Principal Engineer', 'QA Engineer'],
        'Information Security' => ['Security Analyst', 'Security Engineer', 'SOC Analyst', 'CISO Office Analyst', 'Security Manager'],
        'IT Operations' => ['IT Support Specialist', 'Systems Administrator', 'Network Engineer', 'IT Operations Manager'],
        'Finance' => ['Accountant', 'Financial Analyst', 'AP Clerk', 'Controller', 'Finance Manager'],
        'Human Resources' => ['HR Generalist', 'Recruiter', 'HR Business Partner', 'HR Director'],
        'Legal & Compliance' => ['Compliance Analyst', 'Paralegal', 'Compliance Manager', 'General Counsel'],
        'Sales' => ['Account Executive', 'Sales Manager', 'Sales Operations Analyst'],
        'Marketing' => ['Marketing Specialist', 'Content Manager', 'Marketing Manager'],
        'Program Management' => ['Program Manager', 'Project Coordinator', 'PMO Director'],
        'Facilities' => ['Facilities Coordinator', 'Facilities Manager'],
    ];

    // Executives (no manager) — one per department, these become managers.
    $deptHeadIndex = [];
    $idx = 0;
    foreach ($departments as $dept) {
        $fn = $firstNames[$idx % count($firstNames)];
        $ln = $lastNames[$idx % count($lastNames)];
        $stmtPerson->execute([
            'employee_id' => sprintf('E%04d', $idx + 1),
            'first_name' => $fn, 'last_name' => $ln,
            'display_name' => "$fn $ln",
            'email' => strtolower("$fn.$ln") . '@example-corp.test',
            'department' => $dept, 'business_unit' => $dept,
            'position_title' => $dept . ' Director', 'manager_person_id' => null,
            'location' => 'HQ', 'identity_type' => 'employee', 'employment_status' => 'active',
            'start_date' => '2018-01-15', 'termination_date' => null, 'identity_authority' => 'hr_system',
        ]);
        $id = (int) $stmtPerson->fetchColumn();
        $personIds[$idx] = $id;
        $deptHeadIndex[$dept] = $idx;
        $idx++;
    }

    // Managers (1-2 per department reporting to the department head).
    $managerIndexByDept = [];
    foreach ($departments as $dept) {
        $count = random_int(1, 2);
        for ($m = 0; $m < $count; $m++) {
            $fn = $firstNames[($idx * 3) % count($firstNames)];
            $ln = $lastNames[($idx * 5) % count($lastNames)];
            $titles = $titlesByDept[$dept];
            $pdo->prepare(
                'INSERT INTO person (employee_id, first_name, last_name, display_name, email, department,
                    business_unit, position_title, manager_person_id, location, identity_type, employment_status,
                    start_date, identity_authority)
                 VALUES (:eid,:fn,:ln,:dn,:em,:dept,:bu,:title,:mgr,:loc,:itype,:estatus,:start,:auth)'
            )->execute([
                'eid' => sprintf('E%04d', $idx + 1), 'fn' => $fn, 'ln' => $ln, 'dn' => "$fn $ln",
                'em' => strtolower("$fn.$ln") . '@example-corp.test', 'dept' => $dept, 'bu' => $dept,
                'title' => end($titles), 'mgr' => $personIds[$deptHeadIndex[$dept]], 'loc' => 'HQ',
                'itype' => 'employee', 'estatus' => 'active', 'start' => '2019-06-01', 'auth' => 'hr_system',
            ]);
            $id = (int) Db::fetchValue('SELECT lastval()');
            $personIds[$idx] = $id;
            $managerIndexByDept[$dept][] = $idx;
            $idx++;
        }
    }

    // Individual contributors, contractors, guests — fill out to $totalPeople.
    $identityTypePool = array_merge(array_fill(0, 85, 'employee'), array_fill(0, 10, 'contractor'), array_fill(0, 3, 'guest'), array_fill(0, 2, 'service_identity'));
    while ($idx < $totalPeople) {
        $dept = $departments[$idx % count($departments)];
        $fn = $firstNames[$idx % count($firstNames)];
        $ln = $lastNames[($idx + 7) % count($lastNames)];
        $managers = $managerIndexByDept[$dept] ?? [$deptHeadIndex[$dept]];
        $managerIdx = $managers[$idx % count($managers)];
        $titles = $titlesByDept[$dept];
        $title = $titles[$idx % count($titles)];
        $itype = $identityTypePool[$idx % count($identityTypePool)];

        // A handful of terminated people (to demonstrate the "terminated with
        // enabled accounts" risk finding when their accounts aren't disabled).
        $terminated = ($idx % 23 === 0);

        $pdo->prepare(
            'INSERT INTO person (employee_id, first_name, last_name, display_name, email, department,
                business_unit, position_title, manager_person_id, location, identity_type, employment_status,
                start_date, termination_date, identity_authority)
             VALUES (:eid,:fn,:ln,:dn,:em,:dept,:bu,:title,:mgr,:loc,:itype,:estatus,:start,:term,:auth)'
        )->execute([
            'eid' => sprintf('E%04d', $idx + 1), 'fn' => $fn, 'ln' => $ln, 'dn' => "$fn $ln",
            'em' => strtolower("$fn.$ln.$idx") . '@example-corp.test', 'dept' => $dept, 'bu' => $dept,
            'title' => $title, 'mgr' => $personIds[$managerIdx], 'loc' => $idx % 4 === 0 ? 'Remote' : 'HQ',
            'itype' => $itype, 'estatus' => $terminated ? 'terminated' : 'active',
            'start' => '2021-0' . (($idx % 9) + 1) . '-01',
            'term' => $terminated ? '2026-08-15' : null,
            'auth' => 'hr_system',
        ]);
        $id = (int) Db::fetchValue('SELECT lastval()');
        $personIds[$idx] = $id;
        $idx++;
    }
    echo "Seeded $idx people.\n";

    // -------------------------------------------------------------------
    // Applications + connectors
    // -------------------------------------------------------------------
    $apps = [
        ['Microsoft Entra ID (GCC High)', 'entra_gcc_high_mock', 'confidential'],
        ['COMS', 'manual', 'confidential'],
        ['Jira', 'rest_api_manual', 'internal'],
        ['Confluence', 'rest_api_manual', 'internal'],
        ['FileCloud', 'rest_api_manual', 'confidential'],
        ['GitLab', 'rest_api_manual', 'internal'],
        ['CrowdStrike', 'manual', 'restricted'],
        ['NinjaOne', 'manual', 'restricted'],
        ['Sentinel', 'manual', 'restricted'],
        ['Eramba', 'manual', 'confidential'],
        ['FutureFeed', 'manual', 'internal'],
        ['VPN Gateway', 'manual', 'restricted'],
        ['Corporate Firewall', 'manual', 'restricted'],
        ['Finance ERP', 'csv_import', 'confidential'],
        ['HR Information System', 'csv_import', 'confidential'],
    ];
    $applicationIds = [];
    $securityAdminHead = $personIds[$deptHeadIndex['Information Security']];
    $itOpsHead = $personIds[$deptHeadIndex['IT Operations']];
    $financeHead = $personIds[$deptHeadIndex['Finance']];
    foreach ($apps as [$name, $connectorType, $classification]) {
        $owner = str_contains($name, 'Finance') || str_contains($name, 'COMS') ? $financeHead
            : (in_array($name, ['CrowdStrike', 'Sentinel', 'VPN Gateway', 'Corporate Firewall', 'Eramba'], true) ? $securityAdminHead : $itOpsHead);
        $appId = Db::insert('application', [
            'name' => $name, 'description' => "$name — synthetic development catalog entry.",
            'classification' => $classification, 'system_owner_person_id' => $owner,
            'technical_owner_person_id' => $itOpsHead, 'business_owner_person_id' => $owner, 'status' => 'active',
        ]);
        $applicationIds[$name] = $appId;
        $manifest = \Verity\Support\Connectors::defaultManifest($connectorType);
        Db::insert('connector', [
            'application_id' => $appId, 'connector_type' => $connectorType,
            'auth_method' => $connectorType === 'entra_gcc_high_mock' ? 'certificate (mock)' : ($connectorType === 'manual' ? null : 'api_key (mock)'),
            'credential_reference' => $connectorType === 'manual' ? null : 'secrets-manager:verity/' . strtolower(str_replace(' ', '-', $name)),
            'sync_frequency' => $connectorType === 'manual' ? 'manual' : 'daily',
            'default_reviewer_person_id' => $owner, 'default_review_frequency' => 'quarterly',
            'remediation_mode' => 'manual', 'capability_manifest' => $manifest,
            'connection_health' => $connectorType === 'manual' ? 'unknown' : 'healthy',
        ]);
    }
    echo 'Seeded ' . count($applicationIds) . " applications with connectors.\n";

    // -------------------------------------------------------------------
    // Entitlements per application
    // -------------------------------------------------------------------
    $entitlementSpecs = [
        'Microsoft Entra ID (GCC High)' => [
            ['Standard User', 'role', false, 'low'], ['Security Group: All-Employees', 'group', false, 'low'],
            ['Security Reader', 'role', false, 'medium'], ['Guest Access', 'role', false, 'medium'],
            ['Global Administrator', 'role', true, 'critical'],
        ],
        'COMS' => [
            ['General User', 'role', false, 'low'], ['GERP Role: Contracts Analyst', 'role', false, 'medium'],
            ['Budget Administrator', 'role', true, 'high'],
        ],
        'Jira' => [
            ['Project Member', 'role', false, 'low'], ['Project Administrator', 'role', true, 'medium'],
            ['Jira Administrators', 'group', true, 'critical'],
        ],
        'Confluence' => [
            ['Space Viewer', 'role', false, 'low'], ['Space Administrator', 'role', true, 'medium'],
        ],
        'FileCloud' => [
            ['Engineering Share', 'group', false, 'low'], ['Finance Share', 'group', false, 'medium'],
            ['External Sharing Enabled', 'permission', true, 'medium'],
        ],
        'GitLab' => [
            ['Developer', 'role', false, 'low'], ['Maintainer', 'role', false, 'medium'], ['Owner', 'role', true, 'critical'],
        ],
        'CrowdStrike' => [
            ['Console Viewer', 'role', false, 'low'], ['Console Administrator', 'role', true, 'critical'],
        ],
        'NinjaOne' => [
            ['Technician', 'role', false, 'medium'], ['Administrator', 'role', true, 'high'],
        ],
        'Sentinel' => [
            ['Analyst', 'role', false, 'medium'], ['SOC Administrator', 'role', true, 'critical'],
        ],
        'Eramba' => [
            ['Risk Contributor', 'role', false, 'low'], ['GRC Administrator', 'role', true, 'high'],
        ],
        'FutureFeed' => [
            ['Standard User', 'role', false, 'low'],
        ],
        'VPN Gateway' => [
            ['Standard VPN', 'role', false, 'low'], ['Site-to-Site Admin', 'role', true, 'high'],
        ],
        'Corporate Firewall' => [
            ['Read-Only', 'role', false, 'low'], ['Firewall Administrator', 'role', true, 'critical'],
        ],
        'Finance ERP' => [
            ['AP Clerk', 'role', false, 'low'], ['Approval Limit $50K', 'permission', false, 'medium'],
            ['Finance Administrator', 'role', true, 'critical'],
        ],
        'HR Information System' => [
            ['Employee Self-Service', 'role', false, 'low'], ['HR Administrator', 'role', true, 'high'],
        ],
    ];
    $entitlementIds = []; // appName => [ [id, isPrivileged], ... ]
    foreach ($entitlementSpecs as $appName => $specs) {
        foreach ($specs as [$name, $type, $priv, $risk]) {
            $eid = Db::insert('entitlement', [
                'application_id' => $applicationIds[$appName], 'name' => $name, 'entitlement_type' => $type,
                'description' => null, 'is_privileged' => $priv, 'risk_level' => $risk, 'source' => 'connector',
            ]);
            $entitlementIds[$appName][] = ['id' => $eid, 'privileged' => $priv];
        }
    }
    echo "Seeded entitlements for every application.\n";

    // -------------------------------------------------------------------
    // Accounts + entitlement assignments
    // -------------------------------------------------------------------
    // Near-universal apps everyone gets an account in; narrower apps only for relevant departments.
    $universalApps = ['Microsoft Entra ID (GCC High)', 'VPN Gateway'];
    $deptApps = [
        'Engineering' => ['GitLab', 'Jira', 'Confluence', 'FileCloud'],
        'Information Security' => ['CrowdStrike', 'Sentinel', 'Eramba', 'Corporate Firewall', 'NinjaOne'],
        'IT Operations' => ['NinjaOne', 'CrowdStrike', 'Corporate Firewall', 'GitLab'],
        'Finance' => ['Finance ERP', 'COMS', 'FileCloud'],
        'Human Resources' => ['HR Information System', 'FileCloud'],
        'Legal & Compliance' => ['Eramba', 'FileCloud', 'Confluence'],
        'Sales' => ['FutureFeed', 'FileCloud'],
        'Marketing' => ['FutureFeed', 'Confluence', 'FileCloud'],
        'Program Management' => ['Jira', 'Confluence', 'COMS'],
        'Facilities' => ['FutureFeed'],
    ];

    $accountCount = 0;
    $assignmentCount = 0;
    $unmatchedBudget = 6; // number of accounts deliberately left unlinked

    foreach ($personIds as $i => $pid) {
        $person = Db::fetchOne('SELECT department, employment_status FROM person WHERE id = :id', ['id' => $pid]);
        $dept = $person['department'];
        $appsForPerson = array_unique(array_merge($universalApps, $deptApps[$dept] ?? []));

        foreach ($appsForPerson as $appName) {
            if (!isset($entitlementIds[$appName])) {
                continue;
            }
            // Decide account type: a small privileged slice in security/it-ops/finance.
            $isPrivUser = in_array($dept, ['Information Security', 'IT Operations'], true) && $i % 9 === 0;
            $accountType = $isPrivUser ? 'privileged' : 'standard';

            // Disabled accounts: terminated people whose account wasn't cleaned up (intentional risk demo for ~half of them).
            $status = ($person['employment_status'] === 'terminated' && $i % 2 === 0) ? 'enabled' : (($person['employment_status'] === 'terminated') ? 'disabled' : 'enabled');

            $linkPerson = true;
            if ($unmatchedBudget > 0 && $accountCount % 27 === 0) {
                $linkPerson = false;
                $unmatchedBudget--;
            }

            $externalId = strtolower(str_replace([' ', '(', ')'], ['.', '', ''], $appName)) . '\\' . sprintf('user%04d', $i);
            $accountId = Db::insert('system_account', [
                'application_id' => $applicationIds[$appName],
                'person_id' => $linkPerson ? $pid : null,
                'external_account_id' => $externalId,
                'username' => $externalId,
                'account_type' => $accountType,
                'status' => $status,
                'source' => 'connector',
                'connector_id' => null,
                'last_login_at' => $status === 'enabled' ? '2026-09-' . sprintf('%02d', ($i % 28) + 1) : null,
                'last_synced_at' => '2026-10-01 06:00:00',
            ]);
            $accountCount++;

            if ($linkPerson) {
                Db::insert('identity_account_link', [
                    'system_account_id' => $accountId, 'person_id' => $pid,
                    'link_method' => 'deterministic', 'linked_by_user_id' => null,
                    'note' => 'Seeded deterministic match on employee identifier.',
                ]);
            }

            $pool = $entitlementIds[$appName];
            $numAssign = 1 + ($i % 2);
            for ($a = 0; $a < $numAssign && $a < count($pool); $a++) {
                $ent = $pool[($i + $a) % count($pool)];
                if ($ent['privileged'] && !$isPrivUser && $i % 15 !== 0) {
                    continue; // keep privileged entitlements rare and mostly on privileged accounts
                }
                $assignType = 'direct';
                $expiresAt = null;
                if ($ent['privileged']) {
                    $assignType = 'privileged';
                } elseif ($i % 11 === 0) {
                    $assignType = 'temporary';
                    $expiresAt = $i % 22 === 0 ? '2026-08-01 00:00:00' : '2026-12-31 23:59:59'; // some already expired
                } elseif ($i % 13 === 0) {
                    $assignType = 'inherited';
                }
                try {
                    Db::insert('entitlement_assignment', [
                        'system_account_id' => $accountId, 'entitlement_id' => $ent['id'],
                        'assignment_type' => $assignType, 'granted_at' => '2024-01-15 00:00:00',
                        'expires_at' => $expiresAt, 'source' => 'connector',
                        'last_certified_at' => $i % 5 === 0 ? '2026-04-01 00:00:00' : null,
                    ]);
                    $assignmentCount++;
                } catch (\Throwable $e) {
                    // unique constraint (account, entitlement, type) collision — skip, not fatal to seeding
                }
            }
        }
    }
    echo "Seeded $accountCount accounts and $assignmentCount entitlement assignments.\n";

    // -------------------------------------------------------------------
    // Dynamic field example (COMS GERP Role) + a few values
    // -------------------------------------------------------------------
    $gerpFieldId = Db::insert('dynamic_field_definition', [
        'field_key' => 'coms_gerp_role', 'label' => 'COMS GERP Role', 'field_type' => 'single_select',
        'entity_type' => 'system_account', 'application_id' => $applicationIds['COMS'],
        'options' => ['Contracts Analyst', 'Budget Reviewer', 'Program Lead'], 'required' => false, 'active' => true,
    ]);
    $comsAccounts = Db::fetchAll('SELECT id FROM system_account WHERE application_id = :id LIMIT 10', ['id' => $applicationIds['COMS']]);
    foreach ($comsAccounts as $i => $row) {
        $options = ['Contracts Analyst', 'Budget Reviewer', 'Program Lead'];
        Db::query(
            'INSERT INTO dynamic_field_value (field_definition_id, entity_type, entity_id, value)
             VALUES (:fid, :etype, :eid, :val::jsonb)',
            ['fid' => $gerpFieldId, 'etype' => 'system_account', 'eid' => $row['id'], 'val' => json_encode($options[$i % 3])]
        );
    }
    echo "Seeded 1 dynamic field definition with sample values.\n";

    // -------------------------------------------------------------------
    // Platform users (one per role) + explicit grant example
    // -------------------------------------------------------------------
    $supervisorPersonId = $personIds[$deptHeadIndex['Engineering']];
    $systemOwnerPersonId = $itOpsHead;
    $auditorPersonId = $personIds[$deptHeadIndex['Legal & Compliance']];

    $defaultHash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
    $platformUsers = [
        ['admin@verity.local', 'Verity Administrator', ['enterprise_admin'], null],
        ['security.admin@verity.local', 'Security Admin', ['security_admin'], $personIds[$deptHeadIndex['Information Security']]],
        ['supervisor@verity.local', 'Engineering Supervisor', ['supervisor'], $supervisorPersonId],
        ['system.owner@verity.local', 'IT Operations System Owner', ['system_owner'], $systemOwnerPersonId],
        ['auditor@verity.local', 'Compliance Auditor', ['auditor'], $auditorPersonId],
    ];
    foreach ($platformUsers as [$email, $name, $roles, $personId]) {
        $uid = Db::insert('app_user', [
            'email' => $email, 'display_name' => $name, 'password_hash' => $defaultHash,
            'status' => 'active', 'person_id' => $personId,
        ]);
        foreach ($roles as $r) {
            Db::query('INSERT INTO app_user_role (user_id, role_key) VALUES (:u, :r)', ['u' => $uid, 'r' => $r]);
        }
    }
    echo "Seeded 5 platform users (one per role) — password for all: ChangeMe123!\n";

    $pdo->commit();
    echo "\nSeed complete.\n";
    echo "Sign in at /auth/login with any of:\n";
    foreach ($platformUsers as [$email, , $roles]) {
        echo '  ' . $email . ' (' . implode(',', $roles) . ")\n";
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
