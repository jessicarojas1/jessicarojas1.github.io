<?php
declare(strict_types=1);

class DocsController {
    public function index(): void {
        Auth::requireAuth();
        $pageTitle    = 'Documentation';
        $activeModule = 'docs';
        $breadcrumbs  = [['Documentation', null]];
        $section      = Security::sanitizeInput($_GET['s'] ?? 'overview');
        require AEGIS_ROOT . '/views/docs/index.php';
    }
}
