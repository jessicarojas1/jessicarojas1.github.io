<?php
declare(strict_types=1);

// Block web access to the installer — it must only run via CLI or during initial deploy
if (php_sapi_name() !== 'cli' && !defined('AEGIS_INSTALL_ALLOWED')) {
    http_response_code(403);
    die('Installer is not accessible via HTTP. Run from CLI: php install.php');
}

define('AEGIS_ROOT', __DIR__);

// Load env
foreach (['.env.local', '.env'] as $f) {
    if (file_exists(AEGIS_ROOT . '/' . $f)) {
        foreach (file(AEGIS_ROOT . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
// Merge real environment variables (containers/K8s/CI provide env, not a .env
// file) so DATABASE_URL / ADMIN_EMAIL / ADMIN_PASSWORD are honored.
foreach ((getenv() ?: []) as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';

$isCli = php_sapi_name() === 'cli';
function log_msg(string $msg): void {
    global $isCli;
    if ($isCli) echo $msg . PHP_EOL;
    else error_log($msg);
}

// Check if already installed
$needsFullInstall = !Database::tableExists('users');
if (!$needsFullInstall) {
    log_msg('[AEGIS] Database already installed, running migrations...');
}

log_msg('[AEGIS] Starting database installation...');

try {
    $pdo = Database::getInstance();

    // Ensure the aegis schema exists and pin the search path
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS aegis");
    $pdo->exec("SET search_path TO aegis");
    log_msg('[AEGIS] Schema namespace ready.');

if (!$needsFullInstall) {
    runMigrations($pdo);
    log_msg('[AEGIS] Done.');
    exit(0);
}

    $schema = file_get_contents(AEGIS_ROOT . '/database/schema.sql');
    $pdo->exec($schema);
    log_msg('[AEGIS] Schema created.');

    // Default admin — ADMIN_EMAIL and ADMIN_PASSWORD must be set as env vars
    $adminEmail    = $_ENV['ADMIN_EMAIL']    ?? null;
    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? null;

    if (!$adminEmail || !$adminPassword) {
        log_msg('[AEGIS] FATAL: ADMIN_EMAIL and ADMIN_PASSWORD environment variables must be set before install.');
        exit(1);
    }
    $adminHash = Security::hashPassword($adminPassword);

    Database::query(
        "INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
        ['Administrator', $adminEmail, $adminHash]
    );
    log_msg("[AEGIS] Admin user created: {$adminEmail}");

    // Default risk matrix
    Database::query("INSERT INTO risk_matrix_config (name) VALUES ('Default 5x5')");
    log_msg('[AEGIS] Default risk matrix created.');

    // Default risk categories
    $categories = [
        ['Cybersecurity', 'Information security and cyber threats', '#ef4444'],
        ['Operational', 'Operational and process risks', '#f97316'],
        ['Compliance', 'Regulatory and compliance risks', '#8b5cf6'],
        ['Strategic', 'Strategic and business risks', '#0284c7'],
        ['Financial', 'Financial and economic risks', '#059669'],
        ['Reputational', 'Brand and reputational risks', '#d97706'],
    ];
    foreach ($categories as $i => $cat) {
        Database::query(
            "INSERT INTO risk_categories (name, description, color, sort_order) VALUES (?,?,?,?)",
            [$cat[0], $cat[1], $cat[2], $i]
        );
    }
    log_msg('[AEGIS] Default risk categories seeded.');

    // Default settings
    $settings = [
        ['org_name', 'My Organization', 'string', 'Organization name'],
        ['org_logo', '', 'string', 'Organization logo URL'],
        ['date_format', 'Y-m-d', 'string', 'Date display format'],
        ['timezone', 'UTC', 'string', 'Application timezone'],
        ['session_timeout', '480', 'integer', 'Session timeout in minutes'],
        ['installed_at', date('Y-m-d H:i:s'), 'string', 'Installation timestamp'],
        ['version', '1.0.0', 'string', 'AEGIS version'],
        ['ai_settings', '{"provider":"","api_key":"","model":""}', 'string', 'AI advisor configuration (JSON)'],
        ['upload_allowed_types', 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip', 'string', 'Allowed upload file extensions'],
        ['upload_max_size_mb', '20', 'integer', 'Maximum file upload size in MB'],
        ['smtp_host', '', 'string', 'SMTP server hostname'],
        ['smtp_port', '587', 'integer', 'SMTP server port'],
        ['smtp_user', '', 'string', 'SMTP username'],
        ['smtp_pass', '', 'string', 'SMTP password'],
        ['smtp_from', '', 'string', 'Default from address for outbound email'],
        ['smtp_from_name', 'AEGIS GRC', 'string', 'Default from name for outbound email'],
    ];
    foreach ($settings as $s) {
        Database::query(
            "INSERT INTO settings (key, value, type, description) VALUES (?,?,?,?) ON CONFLICT (key) DO NOTHING",
            $s
        );
    }
    log_msg('[AEGIS] Default settings saved.');

    log_msg('[AEGIS] Installation complete!');
    runMigrations($pdo);
    exit(0);

} catch (Exception $e) {
    log_msg('[AEGIS] Installation failed: ' . $e->getMessage());
    log_msg($e->getTraceAsString());
    exit(1);
}

function runMigrations(PDO $pdo): void {
    $pdo->exec("SET search_path TO aegis");

    // ── Incidents ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.incidents (
        id               SERIAL PRIMARY KEY,
        incident_number  VARCHAR(20) UNIQUE NOT NULL,
        title            VARCHAR(255) NOT NULL,
        description      TEXT,
        severity         VARCHAR(20) DEFAULT 'medium',
        category         VARCHAR(100),
        status           VARCHAR(30) DEFAULT 'open',
        reported_by      INTEGER REFERENCES aegis.users(id),
        assigned_to      INTEGER REFERENCES aegis.users(id),
        affected_systems TEXT,
        impact_description TEXT,
        root_cause       TEXT,
        lessons_learned  TEXT,
        detected_at      TIMESTAMP,
        contained_at     TIMESTAMP,
        resolved_at      TIMESTAMP,
        created_at       TIMESTAMP DEFAULT NOW(),
        updated_at       TIMESTAMP DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.incident_updates (
        id           SERIAL PRIMARY KEY,
        incident_id  INTEGER NOT NULL REFERENCES aegis.incidents(id) ON DELETE CASCADE,
        user_id      INTEGER REFERENCES aegis.users(id),
        content      TEXT NOT NULL,
        update_type  VARCHAR(20) DEFAULT 'comment',
        created_at   TIMESTAMP DEFAULT NOW()
    )");

    // ── Vendors ───────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.vendors (
        id               SERIAL PRIMARY KEY,
        vendor_code      VARCHAR(20) UNIQUE NOT NULL,
        name             VARCHAR(255) NOT NULL,
        category         VARCHAR(100),
        website          VARCHAR(255),
        primary_contact  VARCHAR(100),
        contact_email    VARCHAR(255),
        risk_tier        VARCHAR(20) DEFAULT 'medium',
        status           VARCHAR(20) DEFAULT 'active',
        country          VARCHAR(100),
        description      TEXT,
        contract_start   DATE,
        contract_end     DATE,
        data_access      BOOLEAN DEFAULT FALSE,
        critical_service BOOLEAN DEFAULT FALSE,
        created_at       TIMESTAMP DEFAULT NOW(),
        updated_at       TIMESTAMP DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.vendor_assessments (
        id                   SERIAL PRIMARY KEY,
        vendor_id            INTEGER NOT NULL REFERENCES aegis.vendors(id) ON DELETE CASCADE,
        assessment_type      VARCHAR(50) DEFAULT 'security',
        status               VARCHAR(20) DEFAULT 'planned',
        overall_score        SMALLINT,
        risk_rating          VARCHAR(20),
        findings             TEXT,
        recommendations      TEXT,
        assessed_by          INTEGER REFERENCES aegis.users(id),
        scheduled_date       DATE,
        completed_date       DATE,
        next_assessment_date DATE,
        created_at           TIMESTAMP DEFAULT NOW()
    )");

    // ── Issues / Remediations ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.issues (
        id                    SERIAL PRIMARY KEY,
        issue_number          VARCHAR(20) UNIQUE NOT NULL,
        title                 VARCHAR(255) NOT NULL,
        description           TEXT,
        severity              VARCHAR(20) DEFAULT 'medium',
        status                VARCHAR(30) DEFAULT 'open',
        source_type           VARCHAR(50),
        source_id             INTEGER,
        assigned_to           INTEGER REFERENCES aegis.users(id),
        created_by            INTEGER REFERENCES aegis.users(id),
        due_date              DATE,
        resolved_at           TIMESTAMP,
        resolution            TEXT,
        recurrence_prevention TEXT,
        created_at            TIMESTAMP DEFAULT NOW(),
        updated_at            TIMESTAMP DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.issue_updates (
        id          SERIAL PRIMARY KEY,
        issue_id    INTEGER NOT NULL REFERENCES aegis.issues(id) ON DELETE CASCADE,
        user_id     INTEGER REFERENCES aegis.users(id),
        content     TEXT NOT NULL,
        update_type VARCHAR(20) DEFAULT 'comment',
        created_at  TIMESTAMP DEFAULT NOW()
    )");

    // ── Evidence Files ────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS aegis.evidence_files (
        id            SERIAL PRIMARY KEY,
        entity_type   VARCHAR(50) NOT NULL,
        entity_id     INTEGER NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name   VARCHAR(255) NOT NULL,
        mime_type     VARCHAR(100),
        file_size     INTEGER,
        file_hash     VARCHAR(64),
        description   TEXT,
        expires_at    DATE,
        uploaded_by   INTEGER REFERENCES aegis.users(id),
        created_at    TIMESTAMP DEFAULT NOW()
    )");

    // ── MFA columns on users ──────────────────────────────────────────────────
    $pdo->exec("ALTER TABLE aegis.users ADD COLUMN IF NOT EXISTS mfa_secret  VARCHAR(64)");
    $pdo->exec("ALTER TABLE aegis.users ADD COLUMN IF NOT EXISTS mfa_enabled BOOLEAN DEFAULT FALSE");

    // ── New settings ──────────────────────────────────────────────────────────
    $newSettings = [
        ['smtp_host',            '',              'string',  'SMTP server hostname'],
        ['smtp_port',            '587',           'integer', 'SMTP server port'],
        ['smtp_user',            '',              'string',  'SMTP username'],
        ['smtp_pass',            '',              'string',  'SMTP password'],
        ['smtp_from',            '',              'string',  'From email address'],
        ['smtp_from_name',       'AEGIS GRC',     'string',  'From display name'],
        ['smtp_tls',             '1',             'boolean', 'Enable STARTTLS'],
        ['email_notifications',  '0',             'boolean', 'Enable email notifications'],
        ['upload_max_size_mb',   '20',            'integer', 'Max upload size (MB)'],
        ['upload_allowed_types', 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip', 'string', 'Allowed file extensions'],
        ['version',              '2.0.0',         'string',  'AEGIS version'],
    ];
    foreach ($newSettings as $s) {
        $stmt = $pdo->prepare("INSERT INTO aegis.settings (key, value, type, description) VALUES (?,?,?,?) ON CONFLICT (key) DO NOTHING");
        $stmt->execute($s);
    }

    // ── Indexes ───────────────────────────────────────────────────────────────
    // Tolerant like the migration loop below: an index is an optimization, so a
    // single failure (e.g. a column that drifted between schema.sql and the inline
    // table defs) must not abort the whole install.
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_incidents_status   ON aegis.incidents(status)",
        "CREATE INDEX IF NOT EXISTS idx_incidents_severity ON aegis.incidents(severity)",
        "CREATE INDEX IF NOT EXISTS idx_vendors_risk_tier  ON aegis.vendors(risk_tier)",
        "CREATE INDEX IF NOT EXISTS idx_issues_status      ON aegis.issues(status)",
        "CREATE INDEX IF NOT EXISTS idx_evidence_entity    ON aegis.evidence_files(entity_type, entity_id)",
    ];
    foreach ($indexes as $ddl) {
        try { $pdo->exec($ddl); }
        catch (PDOException $e) { log_msg('[AEGIS] index warning: ' . $e->getMessage()); }
    }

    // ── SQL migration files ───────────────────────────────────────────────────
    $migrationFiles = [
        '001_enterprise_phase1.sql',
        '002_phase2.sql',
        '003_phase3.sql',
        '004_risk_enhancements.sql',
        '005_risk_enterprise.sql',
        '006_email_risk_review.sql',
        '007_risk_extensions.sql',
        '008_notification_prefs.sql',
        '009_remove_seeded_packages.sql',
        '010_risk_matrix_cells.sql',
        '011_drop_builtin_columns.sql',
        '012_awareness_account_reviews_privacy.sql',
        '013_ssp.sql',
        '014_poam.sql',
        '015_projects.sql',
        '016_findings_automation.sql',
        '017_dashboards_raci.sql',
        '018_ssp_versioning.sql',
        '019_ssp_extended.sql',
        '020_module_identifiers.sql',
        '021_granular_permissions.sql',
        '022_branding.sql',
        '023_risk_scoring.sql',
        '024_ai_governance.sql',
        '025_audit_changes_text.sql',
    ];
    foreach ($migrationFiles as $file) {
        $path = AEGIS_ROOT . '/database/migrations/' . $file;
        if (!file_exists($path)) continue;
        try {
            $pdo->exec(file_get_contents($path));
            log_msg("[AEGIS] Applied migration: {$file}");
        } catch (PDOException $e) {
            log_msg("[AEGIS] Migration {$file} warning: " . $e->getMessage());
        }
    }

    log_msg('[AEGIS] Migrations applied.');
}
