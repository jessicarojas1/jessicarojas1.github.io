<?php
declare(strict_types=1);

// Bootstrap
define('AEGIS_ROOT', __DIR__);
define('AEGIS_START', microtime(true));

// Suppress PHP error display to users — errors go to the log only
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// Top-level exception handler: show a readable error page instead of blank screen
set_exception_handler(function (Throwable $e): void {
    error_log('[AEGIS] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>AEGIS — Configuration Error</title>"
       . "<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;display:flex;"
       . "align-items:center;justify-content:center;min-height:100vh;margin:0}"
       . ".box{background:#1e293b;border:1px solid #ef4444;border-radius:12px;padding:40px;max-width:540px}"
       . "h1{color:#ef4444;margin:0 0 12px}p{color:#94a3b8;margin:0 0 8px}code{color:#fbbf24}</style></head>"
       . "<body><div class='box'><h1>&#9888; Configuration Error</h1>"
       . "<p>{$msg}</p>"
       . "<p style='margin-top:16px'>Check that all required environment variables are set in your Render dashboard:<br>"
       . "<code>JWT_SECRET</code>, <code>DATABASE_URL</code>, <code>APP_URL</code></p></div></body></html>";
    exit(1);
});

// Load environment
foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(AEGIS_ROOT . '/' . $envFile)) {
        foreach (file(AEGIS_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

// Merge system environment into $_ENV.
// php.ini-production sets variables_order="GPCS" (no 'E'), so $_ENV is empty
// on Docker/Render/Heroku even though env vars are set. getenv() always works.
foreach ((getenv() ?: []) as $k => $v) {
    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
}

// Startup guard: JWT_SECRET must be present and strong enough to sign tokens
if (empty($_ENV['JWT_SECRET']) || strlen($_ENV['JWT_SECRET']) < 32) {
    throw new RuntimeException('JWT_SECRET must be set and at least 32 characters. Set it in your Render/environment dashboard.');
}

// Session config
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$_isHttps = ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || !empty($_SERVER['HTTPS']);
if ($_isHttps) {
    ini_set('session.cookie_secure', '1');
    // __Host- prefix: forces Secure, Path=/, no Domain attribute (prevents subdomain hijack)
    session_name('__Host-AEGIS');
}
session_start();

// Suppress version disclosure headers
header_remove('X-Powered-By');
@ini_set('expose_php', '0');

// Autoload core classes
spl_autoload_register(function (string $class): void {
    $paths = [
        AEGIS_ROOT . "/src/{$class}.php",
        AEGIS_ROOT . "/controllers/{$class}.php",
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) { require_once $path; return; }
    }
});

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Auth.php';
require_once AEGIS_ROOT . '/src/JWT.php';
require_once AEGIS_ROOT . '/src/SSO.php';

Security::setSecurityHeaders();

// Runtime schema migrations — safe to run on every request (no-op when already applied)
try {
    // KRI unit column: varchar(10) → varchar(50); direction: varchar(10) → varchar(20)
    // (direction values 'higher_worse'=12, 'lower_worse'=11 exceed original varchar(10))
    $__kriCols = Database::fetchAll(
        "SELECT column_name, character_maximum_length FROM information_schema.columns
         WHERE table_name='kris' AND column_name IN ('unit','direction') AND table_schema=ANY(ARRAY['public','aegis'])"
    );
    foreach ($__kriCols as $__kc) {
        if ($__kc['column_name'] === 'unit' && ($__kc['character_maximum_length'] ?? 50) < 50) {
            Database::query("ALTER TABLE kris ALTER COLUMN unit TYPE varchar(50)");
        }
        if ($__kc['column_name'] === 'direction' && ($__kc['character_maximum_length'] ?? 20) < 20) {
            Database::query("ALTER TABLE kris ALTER COLUMN direction TYPE varchar(20)");
        }
    }
    unset($__kriCols, $__kc);
} catch (Throwable) {}

try {
    // Vendors: add enterprise columns missing from base schema
    $__vcols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='vendors' AND table_schema='public'"
    );
    $__existing = array_column($__vcols, 'column_name');
    $__vendorMigrations = [
        'vendor_code'     => "ALTER TABLE vendors ADD COLUMN vendor_code VARCHAR(20)",
        'risk_tier'       => "ALTER TABLE vendors ADD COLUMN risk_tier VARCHAR(20) DEFAULT 'medium' CHECK (risk_tier IN ('critical','high','medium','low'))",
        'primary_contact' => "ALTER TABLE vendors ADD COLUMN primary_contact VARCHAR(255)",
        'country'         => "ALTER TABLE vendors ADD COLUMN country VARCHAR(100)",
        'data_access'     => "ALTER TABLE vendors ADD COLUMN data_access BOOLEAN NOT NULL DEFAULT FALSE",
        'critical_service'=> "ALTER TABLE vendors ADD COLUMN critical_service BOOLEAN NOT NULL DEFAULT FALSE",
        'contract_start'  => "ALTER TABLE vendors ADD COLUMN contract_start DATE",
        'contract_end'    => "ALTER TABLE vendors ADD COLUMN contract_end DATE",
    ];
    foreach ($__vendorMigrations as $__col => $__sql) {
        if (!in_array($__col, $__existing, true)) {
            Database::query($__sql);
        }
    }
    unset($__vcols, $__existing, $__vendorMigrations, $__col, $__sql);
} catch (Throwable) {}

try {
    // Assets: add created_by column missing from base schema
    $__assetCols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='assets' AND table_schema='public'"
    );
    if (!in_array('created_by', array_column($__assetCols, 'column_name'), true)) {
        Database::query("ALTER TABLE assets ADD COLUMN created_by INTEGER REFERENCES users(id)");
    }
    unset($__assetCols);
} catch (Throwable) {}

try {
    // Risk matrix config: seed default row if table is empty
    $__rmCount = Database::fetchOne("SELECT COUNT(*) AS cnt FROM risk_matrix_config");
    if (($__rmCount['cnt'] ?? 0) == 0) {
        Database::query(
            "INSERT INTO risk_matrix_config (name, rows, cols, row_label, col_label, row_labels, col_labels, thresholds, colors, is_active)
             VALUES ('Default', 5, 5, 'Likelihood', 'Impact',
               '[\"Rare\",\"Unlikely\",\"Possible\",\"Likely\",\"Almost Certain\"]'::jsonb,
               '[\"Negligible\",\"Minor\",\"Moderate\",\"Major\",\"Critical\"]'::jsonb,
               '{\"low\":4,\"high\":14,\"medium\":9,\"critical\":25}'::jsonb,
               '{\"low\":\"#22c55e\",\"high\":\"#f97316\",\"medium\":\"#f59e0b\",\"critical\":\"#ef4444\"}'::jsonb,
               true)"
        );
    }
    unset($__rmCount);
} catch (Throwable) {}

try {
    // Risks: add roadmap columns missing from base schema
    $__rcols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='risks' AND table_schema='public'"
    );
    $__rexisting = array_column($__rcols, 'column_name');
    $__riskMigrations = [
        'treatment_plan'   => "ALTER TABLE risks ADD COLUMN treatment_plan TEXT",
        'treatment_status' => "ALTER TABLE risks ADD COLUMN treatment_status VARCHAR(50)",
        'due_date'         => "ALTER TABLE risks ADD COLUMN due_date DATE",
    ];
    foreach ($__riskMigrations as $__col => $__sql) {
        if (!in_array($__col, $__rexisting, true)) {
            Database::query($__sql);
        }
    }
    unset($__rcols, $__rexisting, $__riskMigrations, $__col, $__sql);
} catch (Throwable) {}

try {
    // user_notification_prefs: create if missing
    $__notifExists = Database::fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_name='user_notification_prefs' AND table_schema='public'"
    );
    if (!$__notifExists) {
        Database::query(
            "CREATE TABLE user_notification_prefs (
               id                SERIAL PRIMARY KEY,
               user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
               notification_type VARCHAR(100) NOT NULL,
               enabled           BOOLEAN NOT NULL DEFAULT TRUE,
               digest_mode       VARCHAR(20),
               digest_time       TIME,
               UNIQUE(user_id, notification_type)
             )"
        );
    }
    unset($__notifExists);
} catch (Throwable) {}

try {
    // vendor_assessments: add missing columns
    $__vacols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='vendor_assessments' AND table_schema='public'"
    );
    $__vaexisting = array_column($__vacols, 'column_name');
    $__vaMigrations = [
        'overall_score'        => "ALTER TABLE vendor_assessments ADD COLUMN overall_score INTEGER CHECK (overall_score >= 0 AND overall_score <= 100)",
        'risk_rating'          => "ALTER TABLE vendor_assessments ADD COLUMN risk_rating VARCHAR(20)",
        'next_assessment_date' => "ALTER TABLE vendor_assessments ADD COLUMN next_assessment_date DATE",
    ];
    foreach ($__vaMigrations as $__col => $__sql) {
        if (!in_array($__col, $__vaexisting, true)) {
            Database::query($__sql);
        }
    }
    unset($__vacols, $__vaexisting, $__vaMigrations, $__col, $__sql);
} catch (Throwable) {}

try {
    // compliance_objectives: add additional_information column if missing
    $__coCol = Database::fetchOne(
        "SELECT 1 FROM information_schema.columns WHERE table_name='compliance_objectives' AND column_name='additional_information' AND table_schema='public'"
    );
    if (!$__coCol) {
        Database::query("ALTER TABLE compliance_objectives ADD COLUMN additional_information TEXT");
    }
    unset($__coCol);
} catch (Throwable) {}

try {
    // totp_used_codes: prevent TOTP replay attacks within the 90-second window
    $__totpTable = Database::fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_name='totp_used_codes' AND table_schema='public'"
    );
    if (!$__totpTable) {
        Database::query(
            "CREATE TABLE totp_used_codes (
               id             SERIAL PRIMARY KEY,
               user_id        INTEGER NOT NULL,
               window_counter BIGINT  NOT NULL,
               used_at        TIMESTAMP NOT NULL DEFAULT NOW(),
               UNIQUE(user_id, window_counter)
             )"
        );
    }
    unset($__totpTable);
} catch (Throwable) {}

try {
    // users: add sessions_revoked_at for server-side session invalidation
    $__sraCol = Database::fetchOne(
        "SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='sessions_revoked_at' AND table_schema='public'"
    );
    if (!$__sraCol) {
        Database::query("ALTER TABLE users ADD COLUMN sessions_revoked_at TIMESTAMP");
    }
    unset($__sraCol);
} catch (Throwable) {}

try {
    // users: add force_password_change for first-login enforcement
    $__fpcCol = Database::fetchOne(
        "SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='force_password_change' AND table_schema='public'"
    );
    if (!$__fpcCol) {
        Database::query("ALTER TABLE users ADD COLUMN force_password_change BOOLEAN NOT NULL DEFAULT FALSE");
    }
    unset($__fpcCol);
} catch (Throwable) {}

try {
    // ai_inference_log: track AI API calls for ISO 42001 governance & auditability (AI-G1)
    $__aiLogTable = Database::fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_name='ai_inference_log' AND table_schema='public'"
    );
    if (!$__aiLogTable) {
        Database::query(
            "CREATE TABLE ai_inference_log (
               id          SERIAL PRIMARY KEY,
               user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
               provider    VARCHAR(50),
               model       VARCHAR(100),
               action      VARCHAR(100),
               input_hash  VARCHAR(64),
               tokens_used INTEGER,
               duration_ms INTEGER,
               success     BOOLEAN NOT NULL DEFAULT TRUE,
               error_msg   TEXT,
               created_at  TIMESTAMP NOT NULL DEFAULT NOW()
             )"
        );
    }
    unset($__aiLogTable);
} catch (Throwable) {}

try {
    // password_history: prevent immediate password reuse (ISO 27001 A.9.4.3 / ISO-G2)
    $__phTable = Database::fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_name='password_history' AND table_schema='public'"
    );
    if (!$__phTable) {
        Database::query(
            "CREATE TABLE password_history (
               id            SERIAL PRIMARY KEY,
               user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
               password_hash VARCHAR(255) NOT NULL,
               created_at    TIMESTAMP NOT NULL DEFAULT NOW()
             )"
        );
    }
    unset($__phTable);
} catch (Throwable) {}

try {
    // incidents: add HIPAA breach-notification tracking columns (HIPAA §164.400)
    $__incCols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='incidents' AND table_schema='public'"
    );
    $__incExisting = array_column($__incCols, 'column_name');
    if (!in_array('phi_involved', $__incExisting)) {
        Database::query("ALTER TABLE incidents ADD COLUMN phi_involved BOOLEAN NOT NULL DEFAULT FALSE");
    }
    if (!in_array('breach_notification_required', $__incExisting)) {
        Database::query("ALTER TABLE incidents ADD COLUMN breach_notification_required BOOLEAN NOT NULL DEFAULT FALSE");
    }
    if (!in_array('breach_notification_sent_at', $__incExisting)) {
        Database::query("ALTER TABLE incidents ADD COLUMN breach_notification_sent_at TIMESTAMP");
    }
    unset($__incCols, $__incExisting);
} catch (Throwable) {}

try {
    // Risk appetite: remove duplicate rows (keep lowest id per category), then seed defaults if empty
    Database::query(
        "DELETE FROM risk_appetite WHERE id NOT IN (
           SELECT MIN(id) FROM risk_appetite GROUP BY category
         )"
    );
    $__raCount = Database::fetchOne("SELECT COUNT(*) AS cnt FROM risk_appetite");
    if (($__raCount['cnt'] ?? 0) == 0) {
        $__raDefaults = [
            ['Financial',    'low',      6,  'Financial losses above $50,000 require board approval'],
            ['Operational',  'moderate', 12, 'Operational disruptions should be resolved within 48 hours'],
            ['Strategic',    'moderate', 10, 'Strategic risks require executive review before acceptance'],
            ['Compliance',   'low',      4,  'Compliance violations are not tolerated; immediate remediation required'],
            ['Technology',   'moderate', 12, 'Technology risks above threshold require CISO sign-off'],
            ['Reputational', 'low',      6,  'Reputational risks must be escalated to leadership immediately'],
        ];
        foreach ($__raDefaults as [$__cat, $__app, $__max, $__stmt]) {
            Database::query(
                "INSERT INTO risk_appetite (category, appetite, max_score, statement) VALUES (?, ?, ?, ?)",
                [$__cat, $__app, $__max, $__stmt]
            );
        }
    }
    unset($__raCount, $__raDefaults, $__cat, $__app, $__max, $__stmt);
} catch (Throwable) {}

try {
    // ssp_plans: add file-upload columns for network architecture and data flow diagrams
    $__sspCols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='ssp_plans' AND table_schema='public'"
    );
    $__sspExisting = array_column($__sspCols, 'column_name');
    $__sspMigrations = [
        'network_arch_filename' => "ALTER TABLE ssp_plans ADD COLUMN network_arch_filename TEXT",
        'network_arch_data'     => "ALTER TABLE ssp_plans ADD COLUMN network_arch_data    TEXT",
        'data_flow_filename'    => "ALTER TABLE ssp_plans ADD COLUMN data_flow_filename   TEXT",
        'data_flow_data'        => "ALTER TABLE ssp_plans ADD COLUMN data_flow_data       TEXT",
    ];
    foreach ($__sspMigrations as $__col => $__sql) {
        if (!in_array($__col, $__sspExisting)) Database::query($__sql);
    }
    unset($__sspCols, $__sspExisting, $__sspMigrations, $__col, $__sql);
} catch (Throwable) {}

try {
    // audit_findings: add audit_id FK so findings can be linked to specific audits
    $__afCols = Database::fetchAll(
        "SELECT column_name FROM information_schema.columns WHERE table_name='audit_findings' AND table_schema='public'"
    );
    $__afExisting = array_column($__afCols, 'column_name');
    if (!in_array('audit_id', $__afExisting)) {
        Database::query("ALTER TABLE audit_findings ADD COLUMN audit_id INTEGER REFERENCES audits(id) ON DELETE SET NULL");
    }
    unset($__afCols, $__afExisting);
} catch (Throwable) {}

try {
    // Fix existing risks where inherent_score was never computed
    Database::query(
        "UPDATE risks SET inherent_score = likelihood * impact
         WHERE (inherent_score IS NULL OR inherent_score = 0) AND likelihood > 0 AND impact > 0"
    );
} catch (Throwable) {}

try {
    // Awareness Training tables (migration 012)
    Database::query(
        "CREATE TABLE IF NOT EXISTS awareness_programs (
            id           SERIAL PRIMARY KEY,
            title        VARCHAR(255) NOT NULL,
            description  TEXT,
            content_type VARCHAR(30)  DEFAULT 'document',
            content_body TEXT,
            content_url  VARCHAR(500),
            due_date     DATE,
            status       VARCHAR(20)  DEFAULT 'active',
            created_by   INTEGER REFERENCES users(id),
            created_at   TIMESTAMP    DEFAULT NOW(),
            updated_at   TIMESTAMP    DEFAULT NOW()
         )"
    );
    Database::query(
        "CREATE TABLE IF NOT EXISTS awareness_assignments (
            id         SERIAL PRIMARY KEY,
            program_id INTEGER NOT NULL REFERENCES awareness_programs(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            completed  BOOLEAN   DEFAULT FALSE,
            completed_at TIMESTAMP,
            notes      TEXT,
            UNIQUE(program_id, user_id)
         )"
    );
} catch (Throwable) {}

try {
    // Account Reviews tables (migration 012)
    Database::query(
        "CREATE TABLE IF NOT EXISTS account_reviews (
            id           SERIAL PRIMARY KEY,
            title        VARCHAR(255) NOT NULL,
            description  TEXT,
            scope        TEXT,
            reviewer_id  INTEGER REFERENCES users(id),
            status       VARCHAR(20)  DEFAULT 'pending',
            due_date     DATE,
            completed_at TIMESTAMP,
            created_by   INTEGER REFERENCES users(id),
            created_at   TIMESTAMP    DEFAULT NOW(),
            updated_at   TIMESTAMP    DEFAULT NOW()
         )"
    );
    Database::query(
        "CREATE TABLE IF NOT EXISTS account_review_items (
            id             SERIAL PRIMARY KEY,
            review_id      INTEGER NOT NULL REFERENCES account_reviews(id) ON DELETE CASCADE,
            account_name   VARCHAR(255) NOT NULL,
            user_full_name VARCHAR(255),
            system_name    VARCHAR(255),
            access_level   VARCHAR(100),
            decision       VARCHAR(20)  DEFAULT 'pending',
            decision_notes TEXT,
            reviewed_at    TIMESTAMP,
            reviewed_by    INTEGER REFERENCES users(id)
         )"
    );
} catch (Throwable) {}

try {
    // Data Privacy tables (migration 012)
    Database::query(
        "CREATE TABLE IF NOT EXISTS privacy_records (
            id                      SERIAL PRIMARY KEY,
            name                    VARCHAR(255) NOT NULL,
            description             TEXT,
            controller_name         VARCHAR(255),
            processor_name          VARCHAR(255),
            purpose                 TEXT,
            legal_basis             VARCHAR(50),
            data_subject_categories TEXT,
            data_categories         TEXT,
            recipients              TEXT,
            third_country_transfers TEXT,
            retention_period        VARCHAR(255),
            security_measures       TEXT,
            dpia_required           BOOLEAN   DEFAULT FALSE,
            dpia_completed          BOOLEAN   DEFAULT FALSE,
            dpia_date               DATE,
            status                  VARCHAR(20) DEFAULT 'active',
            created_by              INTEGER REFERENCES users(id),
            created_at              TIMESTAMP   DEFAULT NOW(),
            updated_at              TIMESTAMP   DEFAULT NOW()
         )"
    );
    Database::query(
        "CREATE TABLE IF NOT EXISTS data_subject_requests (
            id            SERIAL PRIMARY KEY,
            request_type  VARCHAR(50),
            subject_name  VARCHAR(255),
            subject_email VARCHAR(255),
            description   TEXT,
            status        VARCHAR(20) DEFAULT 'open',
            due_date      DATE,
            completed_at  TIMESTAMP,
            assigned_to   INTEGER REFERENCES users(id),
            notes         TEXT,
            created_at    TIMESTAMP   DEFAULT NOW(),
            updated_at    TIMESTAMP   DEFAULT NOW()
         )"
    );
} catch (Throwable) {}

// Parse route
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . ltrim($uri, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Health check — unauthenticated, no session required (load balancer / uptime monitoring)
if ($uri === '/health' && $method === 'GET') {
    header('Content-Type: application/json');
    $dbOk = false;
    $dbLatencyMs = 0;
    try {
        $t0 = microtime(true);
        Database::fetchOne("SELECT 1 AS ok");
        $dbLatencyMs = round((microtime(true) - $t0) * 1000, 2);
        $dbOk = true;
    } catch (Throwable) {}
    $diskFree = disk_free_space(AEGIS_ROOT);
    $overall = $dbOk ? 'healthy' : 'degraded';
    http_response_code($dbOk ? 200 : 503);
    // Return minimal information to avoid fingerprinting/info disclosure (OWASP API3)
    echo json_encode([
        'status'    => $overall,
        'timestamp' => date('c'),
        'checks'    => [
            'database' => $dbOk ? 'ok' : 'error',
            'disk'     => ($diskFree !== false && $diskFree > 100 * 1024 * 1024) ? 'ok' : 'low',
        ],
    ]);
    exit;
}

// API docs (Swagger UI — no auth required)
if ($uri === '/api/docs' && $method === 'GET') {
    require_once AEGIS_ROOT . '/api/docs.php';
    exit;
}

// API handler
if (str_starts_with($uri, '/api/')) {
    require_once AEGIS_ROOT . '/api/index.php';
    exit;
}

// Route table
$routes = [
    'GET'  => [
        '/'                           => ['DashboardController', 'index'],
        '/profile/notifications'      => ['ProfileController', 'notifications'],
        '/login'                      => ['AuthController', 'loginForm'],
        '/compliance'                 => ['ComplianceController', 'index'],
        '/compliance/create'          => ['ComplianceController', 'createForm'],
        '/compliance/import'          => ['ComplianceController', 'importForm'],
        '/compliance/csv-template'    => ['ComplianceController', 'downloadCsvTemplate'],
        '/compliance/excel-template'  => ['ComplianceController', 'downloadExcelTemplate'],
        '/audit'                      => ['AuditController', 'index'],
        '/audit/create'               => ['AuditController', 'createForm'],
        '/policy'                     => ['PolicyController', 'index'],
        '/policy/mapping'             => ['PolicyController', 'mapping'],
        '/policy/create'              => ['PolicyController', 'createForm'],
        '/risk'                       => ['RiskController', 'index'],
        '/risk/dashboard'             => ['RiskController', 'dashboard'],
        '/risk/create'                => ['RiskController', 'createForm'],
        '/risk/matrix'                => ['RiskController', 'matrix'],
        '/admin'                      => ['AdminController', 'index'],
        '/admin/users'                => ['AdminController', 'users'],
        '/admin/risk-matrix'          => ['AdminController', 'riskMatrix'],
        '/admin/workflows'            => ['AdminController', 'workflows'],
        '/admin/alerts'               => ['AdminController', 'alerts'],
        '/admin/api-keys'             => ['AdminController', 'apiKeys'],
        '/admin/permissions'          => ['AdminController', 'permissions'],
        '/admin/logs'                 => ['AdminController', 'logs'],
        '/export'                     => ['ExportController', 'index'],
        '/metrics'                    => ['MetricsController', 'index'],
        '/docs'                       => ['DocsController', 'index'],
        '/incident'                   => ['IncidentController', 'index'],
        '/incident/create'            => ['IncidentController', 'createForm'],
        '/vendor'                     => ['VendorController', 'index'],
        '/vendor/create'              => ['VendorController', 'createForm'],
        '/vendor/contracts'           => ['VendorController', 'contracts'],
        '/compliance/gap-analysis'    => ['ComplianceController', 'gapAnalysis'],
        '/issue'                      => ['IssueController', 'index'],
        '/issue/create'               => ['IssueController', 'createForm'],
        '/report'                     => ['ReportController', 'index'],
        '/report/compliance'          => ['ReportController', 'compliance'],
        '/report/executive'           => ['ReportController', 'executive'],
        '/report/risk'                => ['ReportController', 'risk'],
        '/admin/email'                => ['AdminController', 'email'],
        '/admin/settings'             => ['AdminController', 'settings'],
        '/admin/module-visibility'    => ['AdminController', 'moduleVisibility'],
        '/mfa/verify'                 => ['AuthController', 'mfaVerifyForm'],
        '/mfa/setup'                  => ['AuthController', 'mfaSetupForm'],
        '/evidence/list'              => ['EvidenceController', 'listForEntity'],
        '/sso/login'                  => ['SSOController', 'login'],
        '/sso/callback'               => ['SSOController', 'callback'],
        '/admin/settings/sso'         => ['SSOController', 'settingsForm'],
        '/approvals'                  => ['ApprovalController', 'pending'],
        '/admin/approval-templates'   => ['ApprovalController', 'templates'],
        '/import'                     => ['ImportController', 'index'],
        '/documents'                  => ['DocumentController', 'index'],
        '/documents/create'           => ['DocumentController', 'createForm'],
        '/report/board'               => ['ReportController', 'board'],
        '/report/board-pack'          => ['ReportController', 'board'],
        '/report/risk-detail'         => ['ReportController', 'riskDetail'],
        '/admin/storage'              => ['AdminController', 'storage'],
        '/admin/retention'            => ['AdminController', 'retention'],
        '/admin/sessions'             => ['AdminController', 'sessions'],
        '/calendar'                   => ['CalendarController', 'index'],
        '/risk/roadmap'               => ['RiskController', 'roadmap'],
        '/risk/exceptions'            => ['RiskExceptionController', 'index'],
        '/admin/webhooks'             => ['WebhookController', 'index'],
        '/admin/webhooks/create'      => ['WebhookController', 'createForm'],
        '/questionnaire'              => ['QuestionnaireController', 'index'],
        '/questionnaire/create'       => ['QuestionnaireController', 'createForm'],
        '/change'                     => ['ChangeController', 'index'],
        '/change/create'              => ['ChangeController', 'createForm'],
        '/bcp'                        => ['BCPController', 'index'],
        '/bcp/create'                 => ['BCPController', 'createForm'],
        '/assets'                     => ['AssetController', 'index'],
        '/assets/create'              => ['AssetController', 'createForm'],
        '/search'                     => ['SearchController', 'index'],
        '/forgot-password'            => ['AuthController', 'forgotPasswordForm'],
        '/profile/edit'               => ['ProfileController', 'editForm'],
        '/admin/security-policy'      => ['AdminController', 'securityPolicy'],
        '/admin/custom-fields'        => ['AdminController', 'customFields'],
        '/admin/tags'                 => ['TagController', 'index'],
        '/admin/approval-templates/create' => ['ApprovalController', 'createTemplate'],
        '/tags/entity'                => ['TagController', 'entityTags'],
        '/policy/attestations'        => ['PolicyController', 'attestations'],
        '/policy/attestations/create' => ['PolicyController', 'createCampaign'],
        '/my-attestations'            => ['PolicyController', 'myAttestations'],
        '/admin/risk-appetite'        => ['AdminController', 'riskAppetite'],
        '/compliance/testing'         => ['ComplianceController', 'testingDashboard'],
        '/playbooks'                  => ['PlaybookController', 'index'],
        '/playbooks/create'           => ['PlaybookController', 'createForm'],
        '/vendor/contracts'           => ['VendorController', 'contracts'],
        '/compliance/gap-analysis'    => ['ComplianceController', 'gapAnalysis'],
        '/threats'                    => ['ThreatController', 'index'],
        '/threats/create'             => ['ThreatController', 'createForm'],
        '/treatment'                  => ['TreatmentController', 'index'],
        '/kris'                       => ['KRIController', 'index'],
        '/kris/create'                => ['KRIController', 'createForm'],
        '/mfa/backup-codes'           => ['AuthController', 'backupCodesForm'],
        '/incident/sla'               => ['IncidentController', 'slaReport'],
        '/admin/sla-policy'           => ['AdminController', 'slaPolicy'],
        '/risk/reviews'               => ['RiskReviewController', 'index'],
        '/risk/reviews/create'        => ['RiskReviewController', 'createForm'],
        '/admin/email-templates'      => ['AdminController', 'emailTemplates'],
        '/admin/scheduled-reports'    => ['AdminController', 'scheduledReports'],
        '/admin/scheduled-reports/create' => ['AdminController', 'scheduledReportForm'],
        '/admin/email-delivery'       => ['AdminController', 'emailDelivery'],
        '/risk-acceptances'           => ['RiskAcceptanceController', 'index'],
        '/risk/scenarios'             => ['ScenarioController', 'index'],
        '/awareness'                  => ['AwarenessController', 'index'],
        '/awareness/create'           => ['AwarenessController', 'createForm'],
        '/account-reviews'            => ['AccountReviewController', 'index'],
        '/account-reviews/create'     => ['AccountReviewController', 'createForm'],
        '/privacy'                    => ['PrivacyController', 'index'],
        '/privacy/create'             => ['PrivacyController', 'createForm'],
        '/privacy/requests'           => ['PrivacyController', 'requests'],
        '/ssp'                        => ['SSPController', 'index'],
        '/ssp/create'                 => ['SSPController', 'createForm'],
        '/poam'                       => ['POAMController', 'index'],
        '/projects'                   => ['ProjectController', 'index'],
        '/projects/create'            => ['ProjectController', 'createForm'],
        '/audit-findings'             => ['AuditFindingController', 'index'],
        '/automation'                 => ['AutomationController', 'index'],
        '/automation/create'          => ['AutomationController', 'createForm'],
        '/cui'                        => ['CUIController', 'index'],
        '/cui/create'                 => ['CUIController', 'createForm'],
        '/odp'                        => ['ODPController', 'index'],
        '/sprs'                       => ['SPRSController', 'index'],
        '/dashboards'                 => ['CustomDashboardController', 'index'],
        '/raci'                       => ['RACIController', 'index'],
    ],
    'POST' => [
        '/profile/notifications/save'    => ['ProfileController', 'saveNotifications'],
        '/profile/notifications/digest'  => ['ProfileController', 'saveNotificationDigest'],
        '/login'                         => ['AuthController', 'login'],
        '/logout'                        => ['AuthController', 'logout'],
        '/mfa/verify'                    => ['AuthController', 'mfaVerify'],
        '/mfa/setup/verify'              => ['AuthController', 'mfaSetupVerify'],
        '/mfa/disable'                   => ['AuthController', 'mfaDisable'],
        '/compliance/create'             => ['ComplianceController', 'create'],
        '/compliance/import'             => ['ComplianceController', 'import'],
        '/compliance/add-single-control' => ['ComplianceController', 'addSingleControl'],
        '/compliance/clear-all'          => ['ComplianceController', 'clearAll'],
        '/compliance/delete-selected'    => ['ComplianceController', 'deleteSelected'],
        '/audit/create'                  => ['AuditController', 'create'],
        '/policy/create'                 => ['PolicyController', 'create'],
        '/risk/create'                   => ['RiskController', 'create'],
        '/admin/users/create'            => ['AdminController', 'createUser'],
        '/admin/risk-matrix/update'      => ['AdminController', 'updateRiskMatrix'],
        '/admin/workflows/create'        => ['AdminController', 'createWorkflow'],
        '/admin/api-keys/create'         => ['AdminController', 'createApiKey'],
        '/admin/email/save'              => ['AdminController', 'saveEmail'],
        '/admin/email/test'              => ['AdminController', 'testEmail'],
        '/admin/settings/save'           => ['AdminController', 'saveSettings'],
        '/admin/module-visibility/save'  => ['AdminController', 'saveModuleVisibility'],
        '/admin/logs/export'             => ['AdminController', 'exportLogs'],
        '/admin/settings/sso/save'       => ['SSOController', 'saveSettings'],
        '/import/upload'                 => ['ImportController', 'upload'],
        '/metrics/schedule/save'         => ['MetricsController', 'saveSchedule'],
        '/documents/create'              => ['DocumentController', 'create'],
        '/export/download'               => ['ExportController', 'download'],
        '/export/download-all'           => ['ExportController', 'downloadAll'],
        '/incident/create'               => ['IncidentController', 'create'],
        '/vendor/create'                 => ['VendorController', 'create'],
        '/issue/create'                  => ['IssueController', 'create'],
        '/evidence/upload'               => ['EvidenceController', 'upload'],
        '/admin/webhooks/create'         => ['WebhookController', 'create'],
        '/questionnaire/create'          => ['QuestionnaireController', 'create'],
        '/change/create'                 => ['ChangeController', 'create'],
        '/bcp/create'                    => ['BCPController', 'create'],
        '/assets/create'                 => ['AssetController', 'create'],
        '/admin/storage/save'            => ['AdminController', 'saveStorage'],
        '/admin/storage/test'            => ['AdminController', 'testStorage'],
        '/admin/retention/save'          => ['AdminController', 'saveRetention'],
        '/admin/retention/run'           => ['AdminController', 'runRetention'],
        '/forgot-password'               => ['AuthController', 'forgotPassword'],
        '/profile/update'                => ['ProfileController', 'update'],
        '/profile/change-password'       => ['ProfileController', 'changePassword'],
        '/admin/security-policy/save'    => ['AdminController', 'saveSecurityPolicy'],
        '/admin/custom-fields/save'      => ['AdminController', 'saveCustomField'],
        '/admin/tags/create'             => ['TagController', 'create'],
        '/admin/approval-templates/save' => ['ApprovalController', 'saveTemplate'],
        '/risk/bulk-update'              => ['RiskController', 'bulkUpdate'],
        '/tags/add'                      => ['TagController', 'addToEntity'],
        '/tags/remove'                   => ['TagController', 'removeFromEntity'],
        '/policy/attestations/save'      => ['PolicyController', 'saveCampaign'],
        '/admin/risk-appetite/save'      => ['AdminController', 'saveRiskAppetite'],
        '/playbooks/create'              => ['PlaybookController', 'create'],
        '/threats/create'               => ['ThreatController', 'create'],
        '/kris/create'                  => ['KRIController', 'create'],
        '/mfa/backup-codes/generate'    => ['AuthController', 'generateBackupCodes'],
        '/mfa/backup-verify'            => ['AuthController', 'mfaBackupVerify'],
        '/admin/sla-policy/save'        => ['AdminController', 'saveSlaPolicy'],
        '/risk/reviews/create'          => ['RiskReviewController', 'create'],
        '/admin/email-templates/update' => ['AdminController', 'updateEmailTemplate'],
        '/admin/scheduled-reports/create'       => ['AdminController', 'createScheduledReport'],
        '/admin/alerts/config/save'             => ['AdminController', 'saveAlertConfig'],
        '/awareness/create'                     => ['AwarenessController', 'create'],
        '/account-reviews/create'               => ['AccountReviewController', 'create'],
        '/privacy/create'                       => ['PrivacyController', 'create'],
        '/privacy/requests/create'              => ['PrivacyController', 'createRequest'],
        '/ssp/create'                           => ['SSPController', 'create'],
        '/poam/generate'                        => ['POAMController', 'generate'],
        '/poam/create'                          => ['POAMController', 'create'],
        '/poam/import'                          => ['POAMController', 'importCsv'],
        '/projects/create'                      => ['ProjectController', 'create'],
        '/audit-findings/create'                => ['AuditFindingController', 'create'],
        '/automation/create'                    => ['AutomationController', 'create'],
        '/cui/create'                           => ['CUIController', 'create'],
        '/odp/save'                             => ['ODPController', 'save'],
        '/dashboards/create'                    => ['CustomDashboardController', 'create'],
    ],
];

// Dynamic route patterns
$dynamicRoutes = [
    'GET' => [
        '#^/compliance/(\d+)/ai-suggestions$#'      => ['ComplianceController', 'aiSuggestions'],
        '#^/compliance/(\d+)$#'                     => ['ComplianceController', 'viewPackage'],
        '#^/compliance/(\d+)/objective/(\d+)$#'     => ['ComplianceController', 'viewObjective'],
        '#^/audit/(\d+)$#'                          => ['AuditController', 'view'],
        '#^/audit/(\d+)/edit$#'                     => ['AuditController', 'editForm'],
        '#^/audit/(\d+)/export$#'                   => ['AuditController', 'exportPackage'],
        '#^/policy/(\d+)$#'                         => ['PolicyController', 'view'],
        '#^/policy/(\d+)/edit$#'                    => ['PolicyController', 'editForm'],
        '#^/risk/(\d+)/exception/create$#'          => ['RiskExceptionController', 'createForm'],
        '#^/risk/exception/(\d+)$#'                 => ['RiskExceptionController', 'view'],
        '#^/risk/(\d+)$#'                           => ['RiskController', 'view'],
        '#^/risk/(\d+)/edit$#'                      => ['RiskController', 'editForm'],
        '#^/admin/users/(\d+)/edit$#'               => ['AdminController', 'editUser'],
        '#^/admin/workflows/(\d+)/edit$#'           => ['AdminController', 'editWorkflow'],
        '#^/incident/(\d+)$#'                       => ['IncidentController', 'view'],
        '#^/vendor/(\d+)$#'                         => ['VendorController', 'view'],
        '#^/vendor/(\d+)/contract/create$#'         => ['VendorController', 'createContract'],
        '#^/issue/(\d+)$#'                          => ['IssueController', 'view'],
        '#^/evidence/(\d+)/download$#'              => ['EvidenceController', 'download'],
        '#^/admin/webhooks/(\d+)/edit$#'            => ['WebhookController', 'editForm'],
        '#^/admin/webhooks/(\d+)/deliveries$#'      => ['WebhookController', 'deliveries'],
        '#^/questionnaire/(\d+)$#'                  => ['QuestionnaireController', 'view'],
        '#^/questionnaire/assignment/(\d+)/respond$#' => ['QuestionnaireController', 'respond'],
        '#^/change/(\d+)$#'                         => ['ChangeController', 'view'],
        '#^/bcp/(\d+)$#'                            => ['BCPController', 'view'],
        '#^/assets/(\d+)$#'                         => ['AssetController', 'view'],
        '#^/calendar/feed$#'                        => ['CalendarController', 'feed'],
        '#^/reset-password/([A-Za-z0-9+/=_-]+)$#'  => ['AuthController', 'resetPasswordForm'],
        '#^/compliance/(\d+)/scorecard$#'            => ['ComplianceController', 'scorecard'],
        '#^/vendor/portal/([A-Za-z0-9_-]+)$#'       => ['VendorController', 'portalView'],
        '#^/policy/attestations/(\d+)$#'            => ['PolicyController', 'viewCampaign'],
        '#^/policy/(\d+)/attest$#'                  => ['PolicyController', 'attestForm'],
        '#^/compliance/control/(\d+)/test$#'         => ['ComplianceController', 'testControl'],
        '#^/playbooks/(\d+)$#'                       => ['PlaybookController', 'view'],
        '#^/vendor/(\d+)/contract/create$#'          => ['VendorController', 'createContract'],
        '#^/risk/(\d+)/treatment/create$#'           => ['TreatmentController', 'createForm'],
        '#^/treatment/(\d+)$#'                       => ['TreatmentController', 'view'],
        '#^/threats/(\d+)$#'                         => ['ThreatController', 'view'],
        '#^/kris/(\d+)$#'                            => ['KRIController', 'view'],
        '#^/risk/reviews/(\d+)$#'                    => ['RiskReviewController', 'view'],
        '#^/admin/email-templates/(\d+)/edit$#'      => ['AdminController', 'emailTemplateForm'],
        '#^/admin/email-templates/(\d+)/preview$#'   => ['AdminController', 'previewEmailTemplate'],
        '#^/admin/scheduled-reports/(\d+)/edit$#'    => ['AdminController', 'scheduledReportForm'],
        '#^/admin/alerts/config/(\d+)/edit$#'        => ['AdminController', 'alertConfigForm'],
        '#^/verify-email/([A-Za-z0-9]+)$#'          => ['UnsubscribeController', 'verifyEmail'],
        '#^/unsubscribe/([A-Za-z0-9_-]+)$#'         => ['UnsubscribeController', 'unsubscribe'],
        '#^/admin/alerts/config/create$#'            => ['AdminController', 'alertConfigForm'],
        '#^/risk/(\d+)/accept$#'             => ['RiskAcceptanceController', 'createForm'],
        '#^/risk-acceptances/(\d+)/renew$#'  => ['RiskAcceptanceController', 'renew'],
        '#^/risk/(\d+)/bowtie$#'             => ['BowTieController', 'view'],
        '#^/risk/(\d+)/scenario/create$#'    => ['ScenarioController', 'createForm'],
        '#^/awareness/(\d+)$#'               => ['AwarenessController', 'view'],
        '#^/account-reviews/(\d+)$#'         => ['AccountReviewController', 'view'],
        '#^/privacy/(\d+)$#'                 => ['PrivacyController', 'view'],
        '#^/ssp/(\d+)$#'                           => ['SSPController', 'view'],
        '#^/ssp/(\d+)/generate$#'                  => ['SSPController', 'generate'],
        '#^/ssp/(\d+)/download/network-arch$#'     => ['SSPController', 'downloadNetworkArch'],
        '#^/ssp/(\d+)/download/data-flow$#'        => ['SSPController', 'downloadDataFlow'],
        '#^/poam/(\d+)$#'                    => ['POAMController', 'view'],
        '#^/projects/(\d+)$#'               => ['ProjectController', 'view'],
        '#^/audit-findings/(\d+)$#'         => ['AuditFindingController', 'view'],
        '#^/automation/(\d+)$#'             => ['AutomationController', 'view'],
        '#^/cui/(\d+)$#'                    => ['CUIController', 'view'],
        '#^/odp/package/(\d+)$#'            => ['ODPController', 'packageView'],
        '#^/dashboards/(\d+)$#'             => ['CustomDashboardController', 'view'],
        '#^/raci/(\d+)$#'                   => ['RACIController', 'view'],
        '#^/raci/(\d+)/responsibility$#'    => ['RACIController', 'responsibilityMatrix'],
    ],
    'POST' => [
        '#^/compliance/(\d+)/objective/(\d+)/update$#' => ['ComplianceController', 'updateObjective'],
        '#^/audit/(\d+)/update$#'                      => ['AuditController', 'update'],
        '#^/audit/(\d+)/complete$#'                    => ['AuditController', 'complete'],
        '#^/audit/(\d+)/item/(\d+)/update$#'           => ['AuditController', 'updateItem'],
        '#^/policy/(\d+)/update$#'                     => ['PolicyController', 'update'],
        '#^/policy/(\d+)/map$#'                        => ['PolicyController', 'mapObjective'],
        '#^/policy/(\d+)/unmap/(\d+)$#'                => ['PolicyController', 'unmapObjective'],
        '#^/risk/(\d+)/exception/create$#'              => ['RiskExceptionController', 'create'],
        '#^/risk/exception/(\d+)/decide$#'             => ['RiskExceptionController', 'decide'],
        '#^/risk/(\d+)/update$#'                       => ['RiskController', 'update'],
        '#^/risk/(\d+)/delete$#'                       => ['RiskController', 'delete'],
        '#^/risk/(\d+)/response-action$#'              => ['RiskController', 'addResponseAction'],
        '#^/risk/response-action/(\d+)/update$#'       => ['RiskController', 'updateResponseAction'],
        '#^/risk/(\d+)/submit-review$#'                => ['RiskController', 'submitReview'],
        '#^/risk/(\d+)/approve$#'                      => ['RiskController', 'approve'],
        '#^/risk/(\d+)/reject-review$#'                => ['RiskController', 'rejectReview'],
        '#^/risk/(\d+)/link-control$#'                 => ['RiskController', 'linkControl'],
        '#^/risk/control-link/(\d+)/remove$#'          => ['RiskController', 'removeControlLink'],
        '#^/risk/(\d+)/link-related$#'                 => ['RiskController', 'linkRelated'],
        '#^/risk/related-link/(\d+)/remove$#'          => ['RiskController', 'removeRelatedLink'],
        '#^/admin/users/(\d+)/update$#'                => ['AdminController', 'updateUser'],
        '#^/admin/users/(\d+)/delete$#'                => ['AdminController', 'deleteUser'],
        '#^/admin/workflows/(\d+)/toggle$#'            => ['AdminController', 'toggleWorkflow'],
        '#^/admin/api-keys/(\d+)/revoke$#'             => ['AdminController', 'revokeApiKey'],
        '#^/admin/permissions/(\d+)/update$#'          => ['AdminController', 'updatePermissions'],
        '#^/alerts/(\d+)/read$#'                       => ['DashboardController', 'markAlertRead'],
        '#^/incident/(\d+)/update$#'                   => ['IncidentController', 'update'],
        '#^/incident/(\d+)/add-update$#'               => ['IncidentController', 'addUpdate'],
        '#^/incident/(\d+)/close$#'                    => ['IncidentController', 'close'],
        '#^/vendor/(\d+)/update$#'                     => ['VendorController', 'update'],
        '#^/vendor/(\d+)/assessment$#'                 => ['VendorController', 'addAssessment'],
        '#^/vendor/(\d+)/assessment/(\d+)/update$#'    => ['VendorController', 'updateAssessment'],
        '#^/vendor/(\d+)/contract/save$#'              => ['VendorController', 'saveContract'],
        '#^/vendor/contract/(\d+)/update$#'            => ['VendorController', 'updateContract'],
        '#^/issue/(\d+)/update$#'                      => ['IssueController', 'update'],
        '#^/issue/(\d+)/add-update$#'                  => ['IssueController', 'addUpdate'],
        '#^/evidence/(\d+)/delete$#'                   => ['EvidenceController', 'delete'],
        '#^/approvals/(\d+)/review$#'                  => ['ApprovalController', 'review'],
        '#^/approvals/(\d+)/decide$#'                  => ['ApprovalController', 'decide'],
        '#^/metrics/schedule/(\d+)/delete$#'           => ['MetricsController', 'deleteSchedule'],
        '#^/documents/(\d+)$#'                                  => ['DocumentController', 'view'],
        '#^/documents/(\d+)/update$#'                           => ['DocumentController', 'update'],
        '#^/documents/(\d+)/upload-version$#'                   => ['DocumentController', 'uploadVersion'],
        '#^/admin/webhooks/(\d+)/update$#'                      => ['WebhookController', 'update'],
        '#^/admin/webhooks/(\d+)/toggle$#'                      => ['WebhookController', 'toggleActive'],
        '#^/admin/webhooks/(\d+)/delete$#'                      => ['WebhookController', 'delete'],
        '#^/questionnaire/(\d+)/assign$#'                       => ['QuestionnaireController', 'assign'],
        '#^/questionnaire/assignment/(\d+)/submit$#'            => ['QuestionnaireController', 'submitResponse'],
        '#^/change/(\d+)/update$#'                              => ['ChangeController', 'update'],
        '#^/change/(\d+)/add-update$#'                          => ['ChangeController', 'addUpdate'],
        '#^/bcp/(\d+)/update$#'                                 => ['BCPController', 'update'],
        '#^/bcp/(\d+)/add-exercise$#'                           => ['BCPController', 'addExercise'],
        '#^/assets/(\d+)/update$#'                              => ['AssetController', 'update'],
        '#^/assets/(\d+)/link-risk$#'                           => ['AssetController', 'linkRisk'],
        '#^/assets/(\d+)/unlink-risk/(\d+)$#'                   => ['AssetController', 'unlinkRisk'],
        '#^/admin/sessions/([a-zA-Z0-9]+)/kill$#'               => ['AdminController', 'killSession'],
        '#^/reset-password/([A-Za-z0-9+/=_-]+)$#'               => ['AuthController', 'resetPassword'],
        '#^/vendor/(\d+)/portal-link$#'                          => ['VendorController', 'generatePortalLink'],
        '#^/vendor/portal/([A-Za-z0-9_-]+)/submit$#'            => ['VendorController', 'portalSubmit'],
        '#^/admin/custom-fields/(\d+)/delete$#'                  => ['AdminController', 'deleteCustomField'],
        '#^/admin/tags/(\d+)/delete$#'                           => ['TagController', 'delete'],
        '#^/admin/approval-templates/(\d+)/toggle$#'             => ['ApprovalController', 'toggleTemplate'],
        '#^/policy/(\d+)/attest$#'                               => ['PolicyController', 'attest'],
        '#^/compliance/control/(\d+)/test/save$#'                => ['ComplianceController', 'saveTest'],
        '#^/compliance/(\d+)/update$#'                           => ['ComplianceController', 'updatePackage'],
        '#^/compliance/(\d+)/delete$#'                           => ['ComplianceController', 'deletePackage'],
        '#^/compliance/(\d+)/domain/add$#'                       => ['ComplianceController', 'addDomain'],
        '#^/compliance/(\d+)/domain/(\d+)/update$#'              => ['ComplianceController', 'updateDomain'],
        '#^/compliance/(\d+)/domain/(\d+)/delete$#'              => ['ComplianceController', 'deleteDomain'],
        '#^/compliance/(\d+)/domain/(\d+)/control/add$#'         => ['ComplianceController', 'addControl'],
        '#^/compliance/(\d+)/control/(\d+)/update$#'             => ['ComplianceController', 'updateControl'],
        '#^/compliance/(\d+)/control/(\d+)/delete$#'             => ['ComplianceController', 'deleteControl'],
        '#^/compliance/(\d+)/bulk-status$#'                      => ['ComplianceController', 'bulkStatus'],
        '#^/compliance/(\d+)/bulk-assess$#'                     => ['ComplianceController', 'bulkAssess'],
        '#^/playbooks/(\d+)/toggle$#'                            => ['PlaybookController', 'toggle'],
        '#^/incident/(\d+)/playbook/start$#'                     => ['PlaybookController', 'startRun'],
        '#^/playbooks/run/(\d+)/complete-step$#'                 => ['PlaybookController', 'completeStep'],
        '#^/vendor/(\d+)/contract/save$#'                        => ['VendorController', 'saveContract'],
        '#^/vendor/contract/(\d+)/update$#'                      => ['VendorController', 'updateContract'],
        '#^/risk/(\d+)/treatment/create$#'                       => ['TreatmentController', 'create'],
        '#^/treatment/(\d+)/update$#'                            => ['TreatmentController', 'update'],
        '#^/treatment/(\d+)/milestone/add$#'                     => ['TreatmentController', 'addMilestone'],
        '#^/treatment/milestone/(\d+)/complete$#'                => ['TreatmentController', 'completeMilestone'],
        '#^/treatment/milestone/(\d+)/delete$#'                  => ['TreatmentController', 'deleteMilestone'],
        '#^/threats/(\d+)/update$#'                              => ['ThreatController', 'update'],
        '#^/threats/(\d+)/link-risk$#'                           => ['ThreatController', 'linkRisk'],
        '#^/threats/(\d+)/unlink-risk/(\d+)$#'                   => ['ThreatController', 'unlinkRisk'],
        '#^/kris/(\d+)/record$#'                                 => ['KRIController', 'recordValue'],
        '#^/kris/(\d+)/toggle$#'                                 => ['KRIController', 'toggle'],
        '#^/incident/(\d+)/acknowledge$#'                        => ['IncidentController', 'acknowledge'],
        '#^/risk/reviews/(\d+)/start$#'                          => ['RiskReviewController', 'start'],
        '#^/risk/reviews/(\d+)/complete$#'                       => ['RiskReviewController', 'complete'],
        '#^/risk/reviews/(\d+)/cancel$#'                         => ['RiskReviewController', 'cancel'],
        '#^/risk/reviews/(\d+)/item/(\d+)/update$#'              => ['RiskReviewController', 'updateItem'],
        '#^/admin/email-templates/(\d+)/update$#'                => ['AdminController', 'updateEmailTemplate'],
        '#^/admin/scheduled-reports/(\d+)/update$#'              => ['AdminController', 'updateScheduledReport'],
        '#^/admin/scheduled-reports/(\d+)/delete$#'              => ['AdminController', 'deleteScheduledReport'],
        '#^/admin/alerts/config/(\d+)/delete$#'                  => ['AdminController', 'deleteAlertConfig'],
        '#^/risk/(\d+)/accept$#'                    => ['RiskAcceptanceController', 'create'],
        '#^/risk-acceptances/(\d+)/revoke$#'        => ['RiskAcceptanceController', 'revoke'],
        '#^/risk/(\d+)/bowtie/add-cause$#'          => ['BowTieController', 'addCause'],
        '#^/risk-bowtie/cause/(\d+)/remove$#'       => ['BowTieController', 'removeCause'],
        '#^/risk/(\d+)/bowtie/add-consequence$#'    => ['BowTieController', 'addConsequence'],
        '#^/risk-bowtie/consequence/(\d+)/remove$#' => ['BowTieController', 'removeConsequence'],
        '#^/risk/(\d+)/bowtie/add-barrier$#'        => ['BowTieController', 'addBarrier'],
        '#^/risk-bowtie/barrier/(\d+)/remove$#'     => ['BowTieController', 'removeBarrier'],
        '#^/risk/(\d+)/scenario/create$#'           => ['ScenarioController', 'create'],
        '#^/risk-scenarios/(\d+)/delete$#'          => ['ScenarioController', 'delete'],
        '#^/awareness/(\d+)/complete$#'             => ['AwarenessController', 'complete'],
        '#^/awareness/(\d+)/assign$#'               => ['AwarenessController', 'assign'],
        '#^/awareness/(\d+)/delete$#'               => ['AwarenessController', 'delete'],
        '#^/account-reviews/(\d+)/add-item$#'       => ['AccountReviewController', 'addItem'],
        '#^/account-reviews/(\d+)/item/(\d+)/decide$#' => ['AccountReviewController', 'decide'],
        '#^/account-reviews/(\d+)/delete$#'         => ['AccountReviewController', 'delete'],
        '#^/privacy/(\d+)/delete$#'                 => ['PrivacyController', 'delete'],
        '#^/privacy/requests/(\d+)/update$#'        => ['PrivacyController', 'updateRequest'],
        '#^/ssp/(\d+)/update$#'                     => ['SSPController', 'update'],
        '#^/ssp/(\d+)/delete$#'                     => ['SSPController', 'delete'],
        '#^/ssp/(\d+)/add-package$#'                => ['SSPController', 'addPackage'],
        '#^/ssp/(\d+)/remove-package/(\d+)$#'       => ['SSPController', 'removePackage'],
        '#^/ssp/(\d+)/statement/(\d+)/save$#'       => ['SSPController', 'saveStatement'],
        '#^/poam/(\d+)/update$#'                    => ['POAMController', 'update'],
        '#^/poam/(\d+)/delete$#'                    => ['POAMController', 'delete'],
        '#^/poam/(\d+)/milestone/add$#'             => ['POAMController', 'addMilestone'],
        '#^/poam/(\d+)/milestone/(\d+)/complete$#'  => ['POAMController', 'completeMilestone'],
        '#^/projects/(\d+)/update$#'                => ['ProjectController', 'update'],
        '#^/projects/(\d+)/delete$#'                => ['ProjectController', 'delete'],
        '#^/projects/(\d+)/task/add$#'              => ['ProjectController', 'addTask'],
        '#^/projects/(\d+)/task/(\d+)/complete$#'   => ['ProjectController', 'completeTask'],
        '#^/projects/(\d+)/task/(\d+)/delete$#'     => ['ProjectController', 'deleteTask'],
        '#^/projects/(\d+)/link/add$#'              => ['ProjectController', 'addLink'],
        '#^/projects/(\d+)/link/(\d+)/remove$#'     => ['ProjectController', 'removeLink'],
        '#^/audit-findings/(\d+)/update$#'          => ['AuditFindingController', 'update'],
        '#^/audit-findings/(\d+)/add-update$#'      => ['AuditFindingController', 'addUpdate'],
        '#^/audit-findings/(\d+)/close$#'           => ['AuditFindingController', 'close'],
        '#^/audit-findings/(\d+)/delete$#'          => ['AuditFindingController', 'delete'],
        '#^/automation/(\d+)/toggle$#'              => ['AutomationController', 'toggle'],
        '#^/automation/(\d+)/delete$#'              => ['AutomationController', 'delete'],
        '#^/automation/(\d+)/test$#'                => ['AutomationController', 'testRun'],
        '#^/cui/(\d+)/update$#'                     => ['CUIController', 'update'],
        '#^/cui/(\d+)/delete$#'                     => ['CUIController', 'delete'],
        '#^/dashboards/(\d+)/add-widget$#'          => ['CustomDashboardController', 'addWidget'],
        '#^/dashboards/(\d+)/widget/(\d+)/remove$#' => ['CustomDashboardController', 'removeWidget'],
        '#^/dashboards/(\d+)/delete$#'              => ['CustomDashboardController', 'delete'],
        '#^/raci/(\d+)/save$#'                      => ['RACIController', 'save'],
        '#^/raci/(\d+)/responsibility/save$#'        => ['RACIController', 'saveResponsibility'],
    ],
];

function dispatch(string $controller, string $action, array $params = []): void {
    $file = AEGIS_ROOT . "/controllers/{$controller}.php";
    if (!file_exists($file)) { http_response_code(404); die('Controller not found'); }
    require_once $file;
    $ctrl = new $controller();
    if (!method_exists($ctrl, $action)) { http_response_code(404); die('Action not found'); }
    if ($params) {
        $refParams = (new ReflectionMethod($ctrl, $action))->getParameters();
        foreach ($params as $i => &$p) {
            $type = ($refParams[$i] ?? null)?->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                settype($p, $type->getName());
            }
        }
        unset($p);
    }
    $ctrl->$action(...$params);
}

// Track active session for admin session management
if (!empty($_SESSION['user_id']) || !empty($_SESSION['user']['id'])) {
    try {
        $trackUserId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
        if ($trackUserId > 0 && session_id() !== '') {
            Database::query(
                "INSERT INTO active_sessions (id, user_id, ip_address, user_agent, last_seen_at)
                 VALUES (?,?,?,?,NOW())
                 ON CONFLICT (id) DO UPDATE SET last_seen_at=NOW(), ip_address=EXCLUDED.ip_address",
                [session_id(), $trackUserId, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]
            );
        }
    } catch (Throwable) {}
}

// Static routes
if (isset($routes[$method][$uri])) {
    [$controller, $action] = $routes[$method][$uri];
    dispatch($controller, $action);
    exit;
}

// Dynamic routes
foreach ($dynamicRoutes[$method] ?? [] as $pattern => [$controller, $action]) {
    if (preg_match($pattern, $uri, $matches)) {
        array_shift($matches);
        dispatch($controller, $action, $matches);
        exit;
    }
}

// 404
http_response_code(404);
require AEGIS_ROOT . '/views/errors/404.php';
