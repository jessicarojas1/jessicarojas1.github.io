<?php
class DocsController {
    public function index(): void {
        Auth::requireAuth();
        $pageTitle    = 'Documentation';
        $activeModule = 'docs';
        $breadcrumbs  = [['Documentation', null]];
        require AEGIS_ROOT . '/views/docs/index.php';
    }
}
