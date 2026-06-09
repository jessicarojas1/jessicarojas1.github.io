<?php
declare(strict_types=1);

class DocsController {

    public function index(): void {
        Auth::requireAuth();
        require PALADIN_ROOT . '/views/docs/index.php';
    }
}
