<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Accounts;
use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\RemediationTasks;
use Verity\Support\Security;

final class AccountsController
{
    public static function unmatched(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'account.view.unmatched');

        $filters = [
            'application_id' => (int) ($_GET['application_id'] ?? 0) ?: null,
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 25;
        $total = Accounts::countUnmatched($filters);
        $accounts = Accounts::unmatched($filters, $pageSize, ($page - 1) * $pageSize);
        foreach ($accounts as &$a) {
            $a['suggestion'] = Accounts::suggestMatch((int) $a['id']);
        }
        unset($a);
        $applications = Applications::list();

        $title = 'Unmatched Accounts';
        $navActive = 'unmatched';
        $breadcrumbs = ['Unmatched Accounts' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_accounts_unmatched.php';
    }

    public static function view(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $id = (int) ($_GET['id'] ?? 0);
        $account = $id > 0 ? Accounts::get($id) : null;
        if ($account === null) {
            http_response_code(404);
            echo 'Account not found.';
            return;
        }

        $canAll = Authorize::can($user, 'account.view');
        $ctx = $account['person_id'] !== null ? ['subject_person_id' => (int) $account['person_id']] : [];
        Authorize::requirePermission($user, $canAll ? 'account.view' : ($account['person_id'] !== null ? 'account.view.reports' : 'account.view.unmatched'), $ctx);

        $history = Accounts::linkHistory($id);
        $suggestion = $account['person_id'] === null ? Accounts::suggestMatch($id) : null;
        $assignments = \Verity\Support\Db::fetchAll(
            'SELECT ea.*, e.name AS entitlement_name, e.entitlement_type, e.is_privileged, e.risk_level
             FROM entitlement_assignment ea JOIN entitlement e ON e.id = ea.entitlement_id
             WHERE ea.system_account_id = :id ORDER BY e.name',
            ['id' => $id]
        );
        $canFlagRemediation = Authorize::can($user, 'remediation.manage');
        $remediationTasks = RemediationTasks::forAccount($id);

        $title = $account['username'] ?? $account['external_account_id'];
        $navActive = 'unmatched';
        $breadcrumbs = ['Accounts' => null, $title => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_account_detail.php';
    }

    public static function link(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'identity.manage.correlate');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $personId = (int) ($_POST['person_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        if ($accountId <= 0 || $personId <= 0) {
            http_response_code(400);
            echo 'account_id and person_id are required.';
            return;
        }
        // The client can ASK for link_method='deterministic' (the "Accept
        // Suggested Match" form), but the label is never taken on its word —
        // re-run the same suggestion engine server-side and only record
        // 'deterministic' if it independently agrees. A client claiming the
        // flag for a person it picked through the free-text search instead
        // silently falls back to 'manual', which is the honest answer and
        // not an error worth rejecting the request over.
        $method = 'manual';
        if (!empty($_POST['accept_suggestion'])) {
            $suggestion = Accounts::suggestMatch($accountId);
            if ($suggestion !== null && $suggestion['person_id'] === $personId) {
                $method = 'deterministic';
            }
        }
        Accounts::link($accountId, $personId, $method, $user['id'], $note);
        header('Location: /app/accounts/view?id=' . $accountId);
    }

    public static function unlink(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'identity.manage.correlate');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $accountId = (int) ($_POST['account_id'] ?? 0);
        Accounts::unlink($accountId, $user['id'], trim((string) ($_POST['note'] ?? '')) ?: null);
        header('Location: /app/accounts/view?id=' . $accountId);
    }

    /** JSON: candidate people for manual linking (AJAX, from the unmatched workspace). */
    public static function linkCandidates(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'identity.manage.correlate');
        $q = trim((string) ($_GET['q'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['candidates' => Accounts::linkCandidates($q)]);
    }
}
