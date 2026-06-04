<?php
declare(strict_types=1);

class CUIController {

    public function index(): void {
        Auth::requireAuth();
        $items = Database::fetchAll(
            "SELECT ci.*, a.name AS asset_name
             FROM cui_inventory ci
             LEFT JOIN assets a ON a.id = ci.asset_id
             ORDER BY ci.created_at DESC"
        );
        $stats = [
            'total'     => count($items),
            'encrypted' => count(array_filter($items, fn($i) => $i['is_encrypted'])),
            'categories'=> count(array_unique(array_filter(array_column($items, 'cui_category')))),
        ];
        $pageTitle    = 'CUI Inventory';
        $activeModule = 'cui';
        $breadcrumbs  = [['CUI Inventory', null]];
        ob_start();
        require AEGIS_ROOT . '/views/cui/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $assets = Database::fetchAll("SELECT id, name FROM assets ORDER BY name");
        $pageTitle    = 'New CUI Record';
        $activeModule = 'cui';
        $breadcrumbs  = [['CUI Inventory', '/cui'], ['New Record', null]];
        ob_start();
        require AEGIS_ROOT . '/views/cui/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $desc = trim(Security::sanitizeInput($_POST['data_description'] ?? ''));
        if (!$desc) { $_SESSION['flash_error'] = 'Description is required.'; header('Location: /cui/create'); return; }

        $max = Database::fetchOne("SELECT MAX(CAST(SUBSTRING(inventory_number FROM 5) AS INTEGER)) AS m FROM cui_inventory");
        $next = str_pad((int)($max['m'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
        $number = 'CUI-' . $next;

        $id = Database::insert('cui_inventory', [
            'inventory_number'    => $number,
            'data_description'    => $desc,
            'cui_category'        => Security::sanitizeInput($_POST['cui_category'] ?? ''),
            'asset_id'            => $_POST['asset_id'] ?: null,
            'system_name'         => Security::sanitizeInput($_POST['system_name'] ?? ''),
            'location_description'=> Security::sanitizeInput($_POST['location_description'] ?? ''),
            'storage_type'        => in_array($_POST['storage_type'] ?? '', ['database','file_share','cloud','email','paper','other'], true) ? $_POST['storage_type'] : 'database',
            'access_controls'     => Security::sanitizeInput($_POST['access_controls'] ?? ''),
            'is_encrypted'        => isset($_POST['is_encrypted']) ? true : false,
            'encryption_details'  => Security::sanitizeInput($_POST['encryption_details'] ?? ''),
            'data_owner'          => Security::sanitizeInput($_POST['data_owner'] ?? ''),
            'created_by'          => Auth::id(),
        ]);

        Auth::log('cui_created', 'cui_inventory', $id, ['number' => $number]);
        $_SESSION['flash_success'] = 'CUI record created.';
        header("Location: /cui/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $item = $this->getItem($id);
        if (!$item) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }
        $assets = Database::fetchAll("SELECT id, name FROM assets ORDER BY name");
        $pageTitle    = Security::h($item['inventory_number']);
        $activeModule = 'cui';
        $breadcrumbs  = [['CUI Inventory', '/cui'], [$item['inventory_number'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/cui/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query(
            "UPDATE cui_inventory SET data_description=?, cui_category=?, asset_id=?, system_name=?,
             location_description=?, storage_type=?, access_controls=?, is_encrypted=?,
             encryption_details=?, data_owner=?, updated_at=NOW() WHERE id=?",
            [
                Security::sanitizeInput($_POST['data_description'] ?? ''),
                Security::sanitizeInput($_POST['cui_category'] ?? ''),
                $_POST['asset_id'] ?: null,
                Security::sanitizeInput($_POST['system_name'] ?? ''),
                Security::sanitizeInput($_POST['location_description'] ?? ''),
                in_array($_POST['storage_type'] ?? '', ['database','file_share','cloud','email','paper','other'], true) ? $_POST['storage_type'] : 'database',
                Security::sanitizeInput($_POST['access_controls'] ?? ''),
                isset($_POST['is_encrypted']) ? true : false,
                Security::sanitizeInput($_POST['encryption_details'] ?? ''),
                Security::sanitizeInput($_POST['data_owner'] ?? ''),
                $id,
            ]
        );
        $_SESSION['flash_success'] = 'CUI record updated.';
        header("Location: /cui/{$id}");
    }

    public function delete(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM cui_inventory WHERE id=?", [$id]);
        Auth::log('cui_deleted', 'cui_inventory', $id, []);
        $_SESSION['flash_success'] = 'CUI record deleted.';
        header('Location: /cui');
    }

    private function getItem(int $id): ?array {
        return Database::fetchOne(
            "SELECT ci.*, a.name AS asset_name FROM cui_inventory ci LEFT JOIN assets a ON a.id=ci.asset_id WHERE ci.id=?",
            [$id]
        ) ?: null;
    }
}
