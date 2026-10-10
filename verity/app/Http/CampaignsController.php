<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Campaigns;
use Verity\Support\People;
use Verity\Support\Security;

final class CampaignsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $canManage = Authorize::can($user, 'campaign.manage');
        Authorize::requirePermission($user, $canManage ? 'campaign.manage' : 'campaign.view');

        $filters = ['status' => trim((string) ($_GET['status'] ?? ''))];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 25;
        $total = Campaigns::countAll($filters);
        $campaigns = Campaigns::list($filters, $pageSize, ($page - 1) * $pageSize);
        $applications = $canManage ? Applications::list() : [];
        $people = $canManage ? People::list([], 500, 0) : [];

        $title = 'Certification Campaigns';
        $navActive = 'campaigns';
        $breadcrumbs = ['Certification Campaigns' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_campaigns.php';
    }

    public static function create(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'scope_type' => (string) ($_POST['scope_type'] ?? ''),
            'scope_application_id' => (int) ($_POST['scope_application_id'] ?? 0) ?: null,
            'reviewer_strategy' => (string) ($_POST['reviewer_strategy'] ?? ''),
            'default_reviewer_person_id' => (int) ($_POST['default_reviewer_person_id'] ?? 0) ?: null,
            'due_at' => trim((string) ($_POST['due_at'] ?? '')) ?: null,
        ];
        if ($data['name'] === '') {
            http_response_code(400);
            echo 'Campaign name is required.';
            return;
        }
        try {
            $result = Campaigns::create($data, $user['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/campaigns/view?id=' . $result['id'] . '&launched=' . $result['item_count']);
    }

    public static function view(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $canManage = Authorize::can($user, 'campaign.manage');
        Authorize::requirePermission($user, $canManage ? 'campaign.manage' : 'campaign.view');

        $id = (int) ($_GET['id'] ?? 0);
        $campaign = $id > 0 ? Campaigns::get($id) : null;
        if ($campaign === null) {
            http_response_code(404);
            echo 'Campaign not found.';
            return;
        }

        $filters = ['decision' => trim((string) ($_GET['decision'] ?? ''))];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $total = Campaigns::countItemsFor($id, $filters);
        $items = Campaigns::itemsFor($id, $filters, $pageSize, ($page - 1) * $pageSize);
        $progress = [
            'total' => Campaigns::countItemsFor($id),
            'approved' => Campaigns::countItemsFor($id, ['decision' => 'approved']),
            'revoked' => Campaigns::countItemsFor($id, ['decision' => 'revoked']),
            'pending' => Campaigns::countItemsFor($id, ['decision' => 'pending']),
        ];

        $title = $campaign['name'];
        $navActive = 'campaigns';
        $breadcrumbs = ['Certification Campaigns' => '/app/campaigns', $campaign['name'] => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_campaign_detail.php';
    }

    public static function complete(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        Campaigns::complete($id, $user['id']);
        header('Location: /app/campaigns/view?id=' . $id);
    }

    public static function cancel(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        Campaigns::cancel($id, $user['id']);
        header('Location: /app/campaigns/view?id=' . $id);
    }

    /** "My Reviews" — items assigned to the signed-in user's own person record. */
    public static function myReviews(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.review');

        $personId = $user['person_id'] ?? null;
        if ($personId === null) {
            $items = [];
            $total = 0;
        } else {
            $pendingOnly = ($_GET['all'] ?? '') !== '1';
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $pageSize = 25;
            $total = Campaigns::countReviewQueueFor((int) $personId, $pendingOnly);
            $items = Campaigns::reviewQueueFor((int) $personId, $pendingOnly, $pageSize, ($page - 1) * $pageSize);
        }

        $title = 'My Reviews';
        $navActive = 'myreviews';
        $breadcrumbs = ['My Reviews' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_my_reviews.php';
    }

    public static function decide(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.review');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $personId = $user['person_id'] ?? null;
        if ($personId === null) {
            http_response_code(400);
            echo 'Your account has no linked person record, so no review items can be assigned to you.';
            return;
        }
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            Campaigns::decide($itemId, (int) $personId, $decision, $note, (int) $user['id']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/campaigns/my-reviews');
    }
}
