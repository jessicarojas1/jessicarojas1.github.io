<?php
declare(strict_types=1);

class ActivityController
{
    public function index(): void
    {
        Auth::requireAuth();
        $spaceId = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $space   = $spaceId ? Database::fetchOne("SELECT id, name, space_key FROM spaces WHERE id = ?", [$spaceId]) : null;
        $spaces  = Database::fetchAll("SELECT id, name FROM spaces WHERE is_archived = FALSE ORDER BY name");
        $items   = Activity::feed(60, $spaceId);
        require PALADIN_ROOT . '/views/activity/index.php';
    }
}
