<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\AccessRequests;
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
        $pageSize = 25;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($personId === null) {
            $items = [];
            $total = 0;
        } else {
            $pendingOnly = ($_GET['all'] ?? '') !== '1';
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
        $isAdmin = Authorize::can($user, 'campaign.manage');
        $personId = $user['person_id'] ?? null;
        if ($personId === null && !$isAdmin) {
            http_response_code(400);
            echo 'Your account has no linked person record, so no review items can be assigned to you.';
            return;
        }
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            Campaigns::decide($itemId, (int) ($personId ?? 0), $decision, $note, (int) $user['id'], $isAdmin);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: ' . self::returnTo(['decided' => 1]));
    }

    /** Mass-review: the same decide() rule applied to several items from one submit. */
    public static function decideBulk(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.review');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $isAdmin = Authorize::can($user, 'campaign.manage');
        $personId = $user['person_id'] ?? null;
        if ($personId === null && !$isAdmin) {
            http_response_code(400);
            echo 'Your account has no linked person record, so no review items can be assigned to you.';
            return;
        }
        $itemIds = array_map('intval', (array) ($_POST['item_id'] ?? []));
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        if ($itemIds === [] || !in_array($decision, ['approved', 'revoked'], true)) {
            http_response_code(400);
            echo 'Select at least one item and a decision.';
            return;
        }
        $result = Campaigns::decideBulk($itemIds, (int) ($personId ?? 0), $decision, $note, (int) $user['id'], $isAdmin);
        header('Location: ' . self::returnTo(['bulk_ok' => count($result['succeeded']), 'bulk_failed' => count($result['failed'])]));
    }

    /** JSON: entitlements an account can be granted (doesn't already hold) within its own application, for the review screen's "Add Entitlement" picker. */
    public static function reviewEntitlements(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.review');
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        $accountId = (int) ($_GET['system_account_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['entitlements' => ($applicationId > 0 && $accountId > 0) ? AccessRequests::requestableEntitlements($applicationId, $accountId) : []]);
    }

    /** Grant a new entitlement to an account while reviewing it (admin or that account's supervisor only — enforced again inside Campaigns::grantEntitlement()). */
    public static function grant(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'campaign.review');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $isAdmin = Authorize::can($user, 'campaign.manage');
        $personId = $user['person_id'] ?? null;
        $systemAccountId = (int) ($_POST['system_account_id'] ?? 0);
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            Campaigns::grantEntitlement($systemAccountId, $entitlementId, (int) $user['id'], $personId !== null ? (int) $personId : null, $isAdmin, $note);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: ' . self::returnTo(['granted' => 1]));
    }

    /**
     * Where a decide/decide-bulk/grant POST should redirect back to — either
     * My Reviews or the specific campaign's oversight view it came from,
     * with $extraQuery merged in as a result flag (decided=1, granted=1,
     * bulk_ok=N&bulk_failed=N).
     */
    private static function returnTo(array $extraQuery = []): string
    {
        $campaignId = (int) ($_POST['return_campaign_id'] ?? 0);
        $query = $campaignId > 0 ? ['id' => $campaignId] + $extraQuery : $extraQuery;
        $path = $campaignId > 0 ? '/app/campaigns/view' : '/app/campaigns/my-reviews';
        return $path . '?' . http_build_query($query);
    }
}
