<?php
declare(strict_types=1);

class AssetController {

    public function index(): void {
        Auth::requirePermission('asset.view');

        $type        = Security::sanitizeInput($_GET['type']        ?? '');
        $criticality = Security::sanitizeInput($_GET['criticality'] ?? '');
        $status      = Security::sanitizeInput($_GET['status']      ?? '');

        $where  = ['1=1'];
        $params = [];

        $validTypes = ['server','workstation','application','database','network','cloud','mobile','iot','saas'];
        if ($type && in_array($type, $validTypes, true)) {
            $where[]  = 'a.asset_type = ?';
            $params[] = $type;
        }

        $validCriticalities = ['critical','high','medium','low'];
        if ($criticality && in_array($criticality, $validCriticalities, true)) {
            $where[]  = 'a.criticality = ?';
            $params[] = $criticality;
        }

        $validStatuses = ['active','decommissioned','maintenance'];
        if ($status && in_array($status, $validStatuses, true)) {
            $where[]  = 'a.status = ?';
            $params[] = $status;
        }

        $whereSQL = implode(' AND ', $where);

        // Server-side pagination (TD-5). Filters reference a.* only, so COUNT needs no joins.
        $assetTotal = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM assets a WHERE {$whereSQL}", $params)['c'] ?? 0);
        $pagination = Pagination::build($assetTotal);

        $assets = Database::fetchAll(
            "SELECT a.*, u.name AS owner_name
             FROM assets a
             LEFT JOIN users u ON u.id = a.owner_id
             WHERE {$whereSQL}
             ORDER BY
               CASE a.criticality WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
               a.name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$pagination['perPage'], $pagination['offset']])
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*)                                              AS total,
               COUNT(*) FILTER (WHERE criticality = 'critical')     AS critical,
               COUNT(*) FILTER (WHERE criticality = 'high')         AS high,
               COUNT(*) FILTER (WHERE criticality = 'medium')       AS medium,
               COUNT(*) FILTER (WHERE criticality = 'low')          AS low,
               COUNT(*) FILTER (WHERE status = 'active')            AS active,
               COUNT(*) FILTER (WHERE status = 'decommissioned')    AS decommissioned,
               COUNT(*) FILTER (WHERE status = 'maintenance')       AS maintenance
             FROM assets"
        );

        $byType = Database::fetchAll(
            "SELECT asset_type, COUNT(*) AS cnt
             FROM assets
             GROUP BY asset_type
             ORDER BY cnt DESC"
        );

        $activeModule = 'assets';
        require AEGIS_ROOT . '/views/assets/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('asset.create');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/assets/create.php';
    }

    public function create(): void {
        Auth::requirePermission('asset.create');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $name           = Security::sanitizeInput($_POST['name']          ?? '');
        $assetType      = Security::sanitizeInput($_POST['asset_type']    ?? 'server');
        $criticality    = Security::sanitizeInput($_POST['criticality']   ?? 'medium');
        $classification = Security::sanitizeInput($_POST['classification'] ?? '');
        $status         = Security::sanitizeInput($_POST['status']        ?? 'active');
        $ownerId        = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id();
        $location       = Security::sanitizeInput($_POST['location']      ?? '');
        $hostname       = Security::sanitizeInput($_POST['hostname']      ?? '');
        $vendor         = Security::sanitizeInput($_POST['vendor']        ?? '');
        $version        = Security::sanitizeInput($_POST['version']       ?? '');
        $description    = Security::sanitizeInput($_POST['description']   ?? '');
        $lastScanned    = Security::sanitizeInput($_POST['last_scanned']  ?? '');

        // Validate IP address — set null if invalid or empty
        $ipRaw = trim($_POST['ip_address'] ?? '');
        $ipAddress = ($ipRaw !== '' && filter_var($ipRaw, FILTER_VALIDATE_IP)) ? $ipRaw : null;

        // Parse tags from comma-separated string into JSON array
        $tagsRaw = Security::sanitizeInput($_POST['tags'] ?? '');
        $tagsArr = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $tagsJson = !empty($tagsArr) ? json_encode($tagsArr, JSON_UNESCAPED_UNICODE) : null;

        if (!$name) {
            $_SESSION['asset_error'] = 'Asset name is required.';
            header('Location: /assets/create');
            return;
        }

        $validTypes        = ['server','workstation','application','database','network','cloud','mobile','iot','saas'];
        $validCriticalities = ['critical','high','medium','low'];
        $validStatuses     = ['active','decommissioned','maintenance'];

        if (!in_array($assetType, $validTypes, true))           $assetType      = 'server';
        if (!in_array($criticality, $validCriticalities, true)) $criticality    = 'medium';
        if (!in_array($status, $validStatuses, true))           $status         = 'active';

        // Generate asset code from next sequential ID
        $maxRow    = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM assets");
        $assetCode = 'AST-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $id = Database::insert('assets', [
            'asset_code'     => $assetCode,
            'name'           => $name,
            'asset_type'     => $assetType,
            'criticality'    => $criticality,
            'classification' => $classification ?: null,
            'status'         => $status,
            'owner_id'       => $ownerId,
            'location'       => $location       ?: null,
            'ip_address'     => $ipAddress,
            'hostname'       => $hostname       ?: null,
            'vendor'         => $vendor         ?: null,
            'version'        => $version        ?: null,
            'description'    => $description    ?: null,
            'last_scanned'   => $lastScanned    ?: null,
            'tags'           => $tagsJson,
            'created_by'     => Auth::id(),
        ]);

        Auth::log('create_asset', 'assets', $id, ['asset_code' => $assetCode]);
        $_SESSION['flash_success'] = "Asset {$assetCode} created successfully.";
        header('Location: /assets/' . $id);
    }

    public function view(string $id): void {
        Auth::requirePermission('asset.view');
        $id = (int)$id;

        $asset = Database::fetchOne(
            "SELECT a.*, u.name AS owner_name, u2.name AS created_by_name
             FROM assets a
             LEFT JOIN users u  ON u.id  = a.owner_id
             LEFT JOIN users u2 ON u2.id = a.created_by
             WHERE a.id = ?",
            [$id]
        );

        if (!$asset) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $linkedRisks = Database::fetchAll(
            "SELECT r.id, r.title, r.risk_id, r.likelihood, r.impact, r.inherent_score, r.status,
                    u.name AS owner_name
             FROM asset_risk_links arl
             JOIN risks r ON r.id = arl.risk_id
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE arl.asset_id = ?
             ORDER BY r.inherent_score DESC",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        // All risks for the link-risk modal dropdown
        $allRisks = Database::fetchAll(
            "SELECT r.id, r.title, r.risk_id, r.inherent_score
             FROM risks r
             WHERE r.status NOT IN ('closed')
               AND r.id NOT IN (
                 SELECT risk_id FROM asset_risk_links WHERE asset_id = ?
               )
             ORDER BY r.inherent_score DESC, r.title",
            [$id]
        );

        ob_start();
        require AEGIS_ROOT . '/views/assets/view.php';
        $content    = ob_get_clean();
        $pageTitle  = Security::h($asset['name']);
        $activeModule = 'assets';
        $breadcrumbs = [['Asset Inventory', '/assets'], [Security::h($asset['name']), null]];
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('asset.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        $name           = Security::sanitizeInput($_POST['name']          ?? '');
        $assetType      = Security::sanitizeInput($_POST['asset_type']    ?? 'server');
        $criticality    = Security::sanitizeInput($_POST['criticality']   ?? 'medium');
        $classification = Security::sanitizeInput($_POST['classification'] ?? '');
        $status         = Security::sanitizeInput($_POST['status']        ?? 'active');
        $ownerId        = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $location       = Security::sanitizeInput($_POST['location']      ?? '');
        $hostname       = Security::sanitizeInput($_POST['hostname']      ?? '');
        $vendor         = Security::sanitizeInput($_POST['vendor']        ?? '');
        $version        = Security::sanitizeInput($_POST['version']       ?? '');
        $description    = Security::sanitizeInput($_POST['description']   ?? '');
        $lastScanned    = Security::sanitizeInput($_POST['last_scanned']  ?? '');

        $ipRaw     = trim($_POST['ip_address'] ?? '');
        $ipAddress = ($ipRaw !== '' && filter_var($ipRaw, FILTER_VALIDATE_IP)) ? $ipRaw : null;

        $tagsRaw  = Security::sanitizeInput($_POST['tags'] ?? '');
        $tagsArr  = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $tagsJson = !empty($tagsArr) ? json_encode($tagsArr, JSON_UNESCAPED_UNICODE) : null;

        $validTypes        = ['server','workstation','application','database','network','cloud','mobile','iot','saas'];
        $validCriticalities = ['critical','high','medium','low'];
        $validStatuses     = ['active','decommissioned','maintenance'];
        if (!in_array($assetType, $validTypes, true))           $assetType   = 'server';
        if (!in_array($criticality, $validCriticalities, true)) $criticality = 'medium';
        if (!in_array($status, $validStatuses, true))           $status      = 'active';

        Database::query(
            "UPDATE assets SET
               name = ?, asset_type = ?, criticality = ?, classification = ?,
               status = ?, owner_id = ?, location = ?, ip_address = ?,
               hostname = ?, vendor = ?, version = ?, description = ?,
               last_scanned = ?, tags = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $name, $assetType, $criticality, $classification ?: null,
                $status, $ownerId, $location ?: null, $ipAddress,
                $hostname ?: null, $vendor ?: null, $version ?: null, $description ?: null,
                $lastScanned ?: null, $tagsJson, $id,
            ]
        );

        Auth::log('update_asset', 'assets', $id);
        header('Location: /assets/' . $id . '?saved=1');
    }

    public function linkRisk(string $id): void {
        Auth::requirePermission('asset.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $assetId = (int)$id;
        $riskId  = (int)($_POST['risk_id'] ?? 0);

        if ($riskId) {
            Database::query(
                "INSERT INTO asset_risk_links (asset_id, risk_id)
                 VALUES (?, ?)
                 ON CONFLICT (asset_id, risk_id) DO NOTHING",
                [$assetId, $riskId]
            );
            Auth::log('link_risk_to_asset', 'assets', $assetId, ['risk_id' => $riskId]);
        }

        header('Location: /assets/' . $assetId . '?linked=1');
    }

    public function unlinkRisk(string $assetId, string $riskId): void {
        Auth::requirePermission('asset.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $assetId = (int)$assetId;
        $riskId  = (int)$riskId;

        Database::query(
            "DELETE FROM asset_risk_links WHERE asset_id = ? AND risk_id = ?",
            [$assetId, $riskId]
        );

        Auth::log('unlink_risk_from_asset', 'assets', $assetId, ['risk_id' => $riskId]);
        header('Location: /assets/' . $assetId . '?unlinked=1');
    }
}
