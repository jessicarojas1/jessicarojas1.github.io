<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\AccessRequests;
use Verity\Support\Audit;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\EntitlementAssignments;
use Verity\Support\People;
use Verity\Support\RemediationTasks;
use Verity\Support\RiskScoring;
use Verity\Support\Security;

final class IdentitiesController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();

        $canAll = Authorize::can($user, 'identity.view');
        $canReports = Authorize::can($user, 'identity.view.reports');
        if (!$canAll && !$canReports) {
            Authorize::requirePermission($user, 'identity.view');
        }

        $filters = [
            'search' => trim((string) ($_GET['q'] ?? '')),
            'department' => (string) ($_GET['department'] ?? ''),
            'employment_status' => (string) ($_GET['employment_status'] ?? ''),
            'identity_type' => (string) ($_GET['identity_type'] ?? ''),
        ];
        if (!$canAll && $canReports) {
            $selfId = $user['person_id'] ?? null;
            $ids = $selfId !== null ? Authorize::reportsOf((int) $selfId) : [];
            if ($selfId !== null) {
                $ids[] = (int) $selfId;
            }
            $filters['person_ids'] = $ids;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 25;
        $total = People::count($filters);
        $people = People::list($filters, $pageSize, ($page - 1) * $pageSize);
        $departments = People::departments();
        $riskScores = RiskScoring::scoresFor(array_map(static fn ($p) => (int) $p['id'], $people));

        $title = 'Identity Directory';
        $navActive = 'identities';
        $breadcrumbs = ['Identities' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_identities.php';
    }

    public static function view(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $id = (int) ($_GET['id'] ?? 0);
        $person = $id > 0 ? People::get($id) : null;
        if ($person === null) {
            http_response_code(404);
            echo 'Identity not found.';
            return;
        }

        $canAll = Authorize::can($user, 'identity.view');
        Authorize::requirePermission($user, $canAll ? 'identity.view' : 'identity.view.reports', ['subject_person_id' => $id]);

        // Edit access to this specific identity's entitlements: an admin
        // (entitlement.manage) can edit anyone; otherwise, reaching this
        // line without $canAll already proves — via the
        // identity.view.reports permission's own subject_person_id
        // scoping, just enforced above — that the viewer is this person's
        // supervisor somewhere up the reporting chain. No separate
        // permission is needed for that case, same as Campaigns' decide()
        // rule: admin OR the subject's supervisor, never anyone else.
        $isEntitlementAdmin = Authorize::can($user, 'entitlement.manage');
        $canEditAccess = $isEntitlementAdmin || !$canAll;

        $accounts = People::accountsFor($id);
        $entitlementsByAccount = People::entitlementsForAccounts(array_map(static fn ($a) => (int) $a['id'], $accounts));
        $reports = People::directReports($id);
        $risk = RiskScoring::identityScore($id);

        $title = $person['display_name'];
        $navActive = 'identities';
        $breadcrumbs = ['Identities' => '/app/identities', $person['display_name'] => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_identity_detail.php';
    }

    /** JSON: entitlements an account can be granted (doesn't already hold) within its own application, for the identity page's "Add Entitlement" picker. */
    public static function entitlementsForAccount(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        self::requireEditAccess($user, (int) ($_GET['person_id'] ?? 0));
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        $accountId = (int) ($_GET['system_account_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['entitlements' => ($applicationId > 0 && $accountId > 0) ? AccessRequests::requestableEntitlements($applicationId, $accountId) : []]);
    }

    public static function grantAccess(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $personId = (int) ($_POST['person_id'] ?? 0);
        self::requireEditAccess($user, $personId);
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $isAdmin = Authorize::can($user, 'entitlement.manage');
        $actingPersonId = $user['person_id'] ?? null;
        $systemAccountId = (int) ($_POST['system_account_id'] ?? 0);
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            EntitlementAssignments::grant($systemAccountId, $entitlementId, (int) $user['id'], $actingPersonId !== null ? (int) $actingPersonId : null, $isAdmin, $note);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/identities/view?id=' . $personId . '&granted=1');
    }

    /** Flags an existing entitlement assignment for removal — a tracked remediation task, never an immediate delete; see EntitlementAssignments.php's own doc comment for why. */
    public static function flagForRemoval(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $personId = (int) ($_POST['person_id'] ?? 0);
        self::requireEditAccess($user, $personId);
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $systemAccountId = (int) ($_POST['system_account_id'] ?? 0);
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            RemediationTasks::create([
                'system_account_id' => $systemAccountId,
                'entitlement_id' => $entitlementId,
                'task_type' => 'remove_access',
                'description' => $note ?? 'Flagged for removal from the Identity Directory access editor.',
            ], (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/identities/view?id=' . $personId . '&flagged=1');
    }

    /** Admin (entitlement.manage) or the subject's own supervisor — re-verified here, not just trusted from a page render earlier in the request lifecycle. */
    private static function requireEditAccess(array $user, int $subjectPersonId): void
    {
        if (Authorize::can($user, 'entitlement.manage')) {
            return;
        }
        $actingPersonId = $user['person_id'] ?? null;
        if ($actingPersonId !== null && $subjectPersonId > 0 && Authorize::isInReportingChain((int) $actingPersonId, $subjectPersonId)) {
            return;
        }
        Audit::denied('authz.deny.entitlement.manage', 'person#' . $subjectPersonId, $user['id'] ?? null);
        http_response_code(403);
        echo 'You are not authorized to edit this identity\'s access.';
        exit;
    }
}
