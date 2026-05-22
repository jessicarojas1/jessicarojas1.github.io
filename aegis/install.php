<?php
declare(strict_types=1);
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
if (Database::tableExists('users')) {
    log_msg('[AEGIS] Database already installed, skipping.');
    exit(0);
}

log_msg('[AEGIS] Starting database installation...');

try {
    $pdo = Database::getInstance();
    $schema = file_get_contents(AEGIS_ROOT . '/database/schema.sql');

    // Execute each statement individually for compatibility and clear error reporting
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($s) => strlen($s) > 5 && !preg_match('/^\s*--/', $s)
    );
    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }
    log_msg('[AEGIS] Schema created.');

    // Default admin
    $adminEmail    = $_ENV['ADMIN_EMAIL']    ?? 'admin@aegisgrc.local';
    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? 'Admin@aegis1!';
    $adminHash     = Security::hashPassword($adminPassword);

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

    // Seed built-in standards
    seedStandards($pdo);

    // Default settings
    $settings = [
        ['org_name', 'My Organization', 'string', 'Organization name'],
        ['org_logo', '', 'string', 'Organization logo URL'],
        ['date_format', 'Y-m-d', 'string', 'Date display format'],
        ['timezone', 'UTC', 'string', 'Application timezone'],
        ['session_timeout', '480', 'integer', 'Session timeout in minutes'],
        ['installed_at', date('Y-m-d H:i:s'), 'string', 'Installation timestamp'],
        ['version', '1.0.0', 'string', 'AEGIS version'],
    ];
    foreach ($settings as $s) {
        Database::query(
            "INSERT INTO settings (key, value, type, description) VALUES (?,?,?,?) ON CONFLICT (key) DO NOTHING",
            $s
        );
    }
    log_msg('[AEGIS] Default settings saved.');

    log_msg('[AEGIS] Installation complete!');
    exit(0);

} catch (Exception $e) {
    log_msg('[AEGIS] Installation failed: ' . $e->getMessage());
    log_msg($e->getTraceAsString());
    exit(1);
}

function seedStandards(PDO $pdo): void {
    // --- CMMC 2.0 Level 2 ---
    $pdo->exec("INSERT INTO standards (code, name, version, description, category, authority, is_builtin)
        VALUES ('CMMC-L2', 'Cybersecurity Maturity Model Certification', '2.0 Level 2',
        'CMMC 2.0 Level 2 aligns with NIST SP 800-171 and requires 110 practices across 14 domains.',
        'Cybersecurity', 'Department of Defense', TRUE)");
    $stdId = (int) $pdo->lastInsertId('standards_id_seq');

    $pdo->exec("INSERT INTO compliance_packages (standard_id, name, version, description, is_paid)
        VALUES ({$stdId}, 'CMMC 2.0 Level 2 - Full', '2.0',
        'Complete CMMC Level 2 compliance package with all 110 practices and assessment objectives.', FALSE)");
    $pkgId = (int) $pdo->lastInsertId('compliance_packages_id_seq');

    $cmmcData = json_decode(file_get_contents(AEGIS_ROOT . '/database/seeds/cmmc_l2.json'), true);
    $sort = 0;
    foreach ($cmmcData as $domain) {
        $stmt = $pdo->prepare("INSERT INTO compliance_objectives (package_id, code, title, category, level, sort_order) VALUES (?,?,?,?,1,?) RETURNING id");
        $stmt->execute([$pkgId, $domain['domain_id'], $domain['domain_name'], $domain['domain_name'], $sort++]);
        $domainId = (int) $stmt->fetchColumn();

        foreach ($domain['practices'] as $practice) {
            $stmt = $pdo->prepare("INSERT INTO compliance_objectives (package_id, parent_id, code, title, description, category, level, sort_order) VALUES (?,?,?,?,?,?,2,?) RETURNING id");
            $stmt->execute([$pkgId, $domainId, $practice['id'], $practice['title'], $practice['title'], $domain['domain_name'], $sort++]);
            $practiceId = (int) $stmt->fetchColumn();

            foreach ($practice['objectives'] ?? [] as $obj) {
                $stmt = $pdo->prepare("INSERT INTO compliance_objectives (package_id, parent_id, code, title, category, level, sort_order) VALUES (?,?,?,?,?,3,?)");
                $stmt->execute([$pkgId, $practiceId, $obj['id'], $obj['text'], $domain['domain_name'], $sort++]);
            }
        }
    }
    $total = $pdo->query("SELECT COUNT(*) FROM compliance_objectives WHERE package_id = {$pkgId}")->fetchColumn();
    $pdo->exec("UPDATE compliance_packages SET objectives_count = {$total} WHERE id = {$pkgId}");
    log_msg("[AEGIS] CMMC 2.0 L2 seeded ({$total} objectives).");

    // --- ISO 27001:2022 ---
    $pdo->exec("INSERT INTO standards (code, name, version, description, category, authority, is_builtin)
        VALUES ('ISO-27001', 'ISO/IEC 27001', '2022',
        'Information security management systems — Requirements. The global benchmark for ISMS.',
        'Information Security', 'ISO/IEC', TRUE)");
    $stdId = (int) $pdo->lastInsertId('standards_id_seq');

    $pdo->exec("INSERT INTO compliance_packages (standard_id, name, version, description, is_paid)
        VALUES ({$stdId}, 'ISO/IEC 27001:2022 Annex A Controls', '2022',
        'Complete Annex A controls from ISO 27001:2022 — 93 controls across 4 themes.', FALSE)");
    $pkgId = (int) $pdo->lastInsertId('compliance_packages_id_seq');

    seedISO27001($pdo, $pkgId);

    // --- ISO 42001:2023 ---
    $pdo->exec("INSERT INTO standards (code, name, version, description, category, authority, is_builtin)
        VALUES ('ISO-42001', 'ISO/IEC 42001', '2023',
        'Artificial Intelligence Management System — Requirements. The international standard for responsible AI governance.',
        'AI Governance', 'ISO/IEC', TRUE)");
    $stdId = (int) $pdo->lastInsertId('standards_id_seq');

    $pdo->exec("INSERT INTO compliance_packages (standard_id, name, version, description, is_paid)
        VALUES ({$stdId}, 'ISO/IEC 42001:2023 AI Management System', '2023',
        'Complete AI management system requirements and controls for responsible AI deployment.', FALSE)");
    $pkgId = (int) $pdo->lastInsertId('compliance_packages_id_seq');

    seedISO42001($pdo, $pkgId);
}

function seedISO27001(PDO $pdo, int $pkgId): void {
    $themes = [
        ['5', 'Organizational Controls', [
            ['5.1','Policies for information security'],['5.2','Information security roles and responsibilities'],
            ['5.3','Segregation of duties'],['5.4','Management responsibilities'],
            ['5.5','Contact with authorities'],['5.6','Contact with special interest groups'],
            ['5.7','Threat intelligence'],['5.8','Information security in project management'],
            ['5.9','Inventory of information and other associated assets'],['5.10','Acceptable use of information and other associated assets'],
            ['5.11','Return of assets'],['5.12','Classification of information'],
            ['5.13','Labelling of information'],['5.14','Information transfer'],
            ['5.15','Access control'],['5.16','Identity management'],
            ['5.17','Authentication information'],['5.18','Access rights'],
            ['5.19','Information security in supplier relationships'],['5.20','Addressing information security within supplier agreements'],
            ['5.21','Managing information security in the ICT supply chain'],['5.22','Monitoring, review and change management of supplier services'],
            ['5.23','Information security for use of cloud services'],['5.24','Information security incident management planning and preparation'],
            ['5.25','Assessment and decision on information security events'],['5.26','Response to information security incidents'],
            ['5.27','Learning from information security incidents'],['5.28','Collection of evidence'],
            ['5.29','Information security during disruption'],['5.30','ICT readiness for business continuity'],
            ['5.31','Legal, statutory, regulatory and contractual requirements'],['5.32','Intellectual property rights'],
            ['5.33','Protection of records'],['5.34','Privacy and protection of PII'],
            ['5.35','Independent review of information security'],['5.36','Compliance with policies, rules and standards for information security'],
            ['5.37','Documented operating procedures'],
        ]],
        ['6', 'People Controls', [
            ['6.1','Screening'],['6.2','Terms and conditions of employment'],
            ['6.3','Information security awareness, education and training'],['6.4','Disciplinary process'],
            ['6.5','Responsibilities after termination or change of employment'],['6.6','Confidentiality or non-disclosure agreements'],
            ['6.7','Remote working'],['6.8','Information security event reporting'],
        ]],
        ['7', 'Physical Controls', [
            ['7.1','Physical security perimeters'],['7.2','Physical entry'],
            ['7.3','Securing offices, rooms and facilities'],['7.4','Physical security monitoring'],
            ['7.5','Protecting against physical and environmental threats'],['7.6','Working in secure areas'],
            ['7.7','Clear desk and clear screen'],['7.8','Equipment siting and protection'],
            ['7.9','Security of assets off-premises'],['7.10','Storage media'],
            ['7.11','Supporting utilities'],['7.12','Cabling security'],
            ['7.13','Equipment maintenance'],['7.14','Secure disposal or re-use of equipment'],
        ]],
        ['8', 'Technological Controls', [
            ['8.1','User endpoint devices'],['8.2','Privileged access rights'],
            ['8.3','Information access restriction'],['8.4','Access to source code'],
            ['8.5','Secure authentication'],['8.6','Capacity management'],
            ['8.7','Protection against malware'],['8.8','Management of technical vulnerabilities'],
            ['8.9','Configuration management'],['8.10','Information deletion'],
            ['8.11','Data masking'],['8.12','Data leakage prevention'],
            ['8.13','Information backup'],['8.14','Redundancy of information processing facilities'],
            ['8.15','Logging'],['8.16','Monitoring activities'],
            ['8.17','Clock synchronization'],['8.18','Use of privileged utility programs'],
            ['8.19','Installation of software on operational systems'],['8.20','Networks security'],
            ['8.21','Security of network services'],['8.22','Segregation of networks'],
            ['8.23','Web filtering'],['8.24','Use of cryptography'],
            ['8.25','Secure development life cycle'],['8.26','Application security requirements'],
            ['8.27','Secure system architecture and engineering principles'],['8.28','Secure coding'],
            ['8.29','Security testing in development and acceptance'],['8.30','Outsourced development'],
            ['8.31','Separation of development, test and production environments'],['8.32','Change management'],
            ['8.33','Test information'],['8.34','Protection of information systems during audit testing'],
        ]],
    ];

    $sort = 0;
    foreach ($themes as [$code, $name, $controls]) {
        $stmt = $pdo->prepare("INSERT INTO compliance_objectives (package_id, code, title, category, level, sort_order) VALUES (?,?,?,?,1,?) RETURNING id");
        $stmt->execute([$pkgId, "Theme {$code}", $name, $name, $sort++]);
        $themeId = (int) $stmt->fetchColumn();

        foreach ($controls as [$ctrl, $title]) {
            $pdo->prepare("INSERT INTO compliance_objectives (package_id, parent_id, code, title, category, level, sort_order) VALUES (?,?,?,?,?,2,?)")
                ->execute([$pkgId, $themeId, $ctrl, $title, $name, $sort++]);
        }
    }
    $total = $pdo->query("SELECT COUNT(*) FROM compliance_objectives WHERE package_id = {$pkgId}")->fetchColumn();
    $pdo->exec("UPDATE compliance_packages SET objectives_count = {$total} WHERE id = {$pkgId}");
    log_msg("[AEGIS] ISO 27001:2022 seeded ({$total} controls).");
}

function seedISO42001(PDO $pdo, int $pkgId): void {
    $clauses = [
        ['4', 'Context of the Organization', [
            ['4.1','Understanding the organization and its context'],
            ['4.2','Understanding the needs and expectations of interested parties'],
            ['4.3','Determining the scope of the AI management system'],
            ['4.4','AI management system'],
            ['4.5','AI policy considerations'],
            ['4.6','AI risk and impact assessment'],
        ]],
        ['5', 'Leadership', [
            ['5.1','Leadership and commitment'],
            ['5.2','AI policy'],
            ['5.3','Organizational roles, responsibilities and authorities'],
        ]],
        ['6', 'Planning', [
            ['6.1','Actions to address risks and opportunities'],
            ['6.1.1','General risk and opportunity planning'],
            ['6.1.2','AI risk assessment'],
            ['6.1.3','AI risk treatment'],
            ['6.2','AI objectives and planning to achieve them'],
        ]],
        ['7', 'Support', [
            ['7.1','Resources'],
            ['7.2','Competence'],
            ['7.3','Awareness'],
            ['7.4','Communication'],
            ['7.5','Documented information'],
        ]],
        ['8', 'Operation', [
            ['8.1','Operational planning and control'],
            ['8.2','AI risk assessment process'],
            ['8.3','AI risk treatment process'],
            ['8.4','AI system impact assessment'],
            ['8.5','AI system design and development'],
            ['8.6','AI system life cycle management'],
        ]],
        ['9', 'Performance Evaluation', [
            ['9.1','Monitoring, measurement, analysis and evaluation'],
            ['9.2','Internal audit'],
            ['9.3','Management review'],
        ]],
        ['10', 'Improvement', [
            ['10.1','Continual improvement'],
            ['10.2','Nonconformity and corrective action'],
        ]],
        ['A', 'Annex A Controls', [
            ['A.2.2','Policies related to AI'],
            ['A.2.3','Internal organization'],
            ['A.2.4','Responsibilities for assets'],
            ['A.2.5','Human oversight of AI systems'],
            ['A.2.6','AI system impact assessment process'],
            ['A.3.2','Data governance'],
            ['A.3.3','Data acquisition'],
            ['A.3.4','Data preparation'],
            ['A.4.2','AI system technical robustness'],
            ['A.4.3','AI system accuracy'],
            ['A.4.4','AI system security'],
            ['A.4.5','AI system safety'],
            ['A.5.2','Customer relationship management'],
            ['A.5.3','Third-party and supply chain relationships'],
            ['A.6.1','Intended use and foreseeable misuse'],
            ['A.6.2','Transparency of AI systems'],
            ['A.6.2.2','Communication with users and affected parties'],
            ['A.7.2','Responsible use of AI'],
            ['A.7.3','Bias and fairness'],
            ['A.7.4','Privacy preservation'],
            ['A.8.3','Documentation requirements'],
        ]],
    ];

    $sort = 0;
    foreach ($clauses as [$code, $name, $items]) {
        $stmt = $pdo->prepare("INSERT INTO compliance_objectives (package_id, code, title, category, level, sort_order) VALUES (?,?,?,?,1,?) RETURNING id");
        $stmt->execute([$pkgId, "Clause {$code}", $name, $name, $sort++]);
        $clauseId = (int) $stmt->fetchColumn();

        foreach ($items as [$ctrl, $title]) {
            $pdo->prepare("INSERT INTO compliance_objectives (package_id, parent_id, code, title, category, level, sort_order) VALUES (?,?,?,?,?,2,?)")
                ->execute([$pkgId, $clauseId, $ctrl, $title, $name, $sort++]);
        }
    }
    $total = $pdo->query("SELECT COUNT(*) FROM compliance_objectives WHERE package_id = {$pkgId}")->fetchColumn();
    $pdo->exec("UPDATE compliance_packages SET objectives_count = {$total} WHERE id = {$pkgId}");
    log_msg("[AEGIS] ISO 42001:2023 seeded ({$total} items).");
}
