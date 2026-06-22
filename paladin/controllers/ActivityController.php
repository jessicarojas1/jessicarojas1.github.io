<?php
declare(strict_types=1);

class ActivityController
{
    public function index(): void
    {
        Auth::requireAuth();
        $spaceId = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $space   = $spaceId ? Database::fetchOne("SELECT id, name, space_key, is_private FROM spaces WHERE id = ?", [$spaceId]) : null;
        // Object-level check: a user may only scope the feed to a space they can
        // actually view (deny private spaces they are not a member of).
        if ($spaceId !== null && (!$space || !SpaceAccess::canView($space))) {
            http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return;
        }
        // Only list spaces the user may view in the filter dropdown.
        $spaces  = Database::fetchAll(
            "SELECT id, name, is_private FROM spaces WHERE is_archived = FALSE ORDER BY name"
        );
        $spaces  = array_values(array_filter($spaces, static fn($s) => SpaceAccess::canView($s)));
        $items   = Activity::feed(60, $spaceId);
        require PALADIN_ROOT . '/views/activity/index.php';
    }
}
