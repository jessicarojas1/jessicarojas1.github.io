<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\ReviewWorkflow;
use Redoubt\Support\Security;
use Throwable;

/**
 * Review & approval workflow console. Lifecycle Concept → WIP → Approved, routed
 * author → peer (optional) → QA → exec authorization. Each action is gated by the
 * step's permission.
 */
final class ReviewController
{
    /** action => permission(s) that may perform it (any-of). */
    private const ACTION_PERM = [
        'create'       => ['review.submit'],
        'submit'       => ['review.submit'],
        'reopen'       => ['review.submit'],
        'peer_approve' => ['review.peer'],
        'peer_skip'    => ['review.peer', 'review.submit'],
        'qa_approve'   => ['review.qa'],
        'qa_reject'    => ['review.qa'],
        'exec_approve' => ['review.authorize'],
        'exec_reject'  => ['review.authorize'],
    ];

    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Reviews require the database.');
            return;
        }
        $programs = self::programs($user, 'review.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — reviews require review.view.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'review.view', ['program_id' => $programId]);

        $stage = isset($_GET['stage']) && isset(ReviewWorkflow::STAGES[$_GET['stage']]) ? $_GET['stage'] : null;
        $items = ReviewWorkflow::listForProgram($programId, $stage);
        $history = [];
        foreach ($items as $it) {
            $history[$it['id']] = ReviewWorkflow::history($it['id']);
        }
        $perms = [
            'submit'    => Authorize::can($user, 'review.submit', ['program_id' => $programId]),
            'peer'      => Authorize::can($user, 'review.peer', ['program_id' => $programId]),
            'qa'        => Authorize::can($user, 'review.qa', ['program_id' => $programId]),
            'authorize' => Authorize::can($user, 'review.authorize', ['program_id' => $programId]),
        ];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_reviews.php';
    }

    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $actorId = $user['id'] ?? null;

        if ($action === 'delete') {
            Authorize::requirePermission($user, 'review.submit', ['program_id' => $programId]);
            $id = (int) ($_POST['id'] ?? 0);
            $row = ReviewWorkflow::getRaw($id, $programId);
            $isOwner = $row && (int) ($row['created_by'] ?? 0) === (int) $actorId;
            if (!$isOwner && !Authorize::can($user, 'review.authorize', ['program_id' => $programId])) {
                self::plain(403, '403 Forbidden — only the author or an authorizer may delete.');
                return;
            }
            ReviewWorkflow::delete($id, $programId, $actorId);
            header('Location: /app/reviews?program_id=' . $programId);
            return;
        }

        $perms = self::ACTION_PERM[$action] ?? null;
        if ($perms === null) {
            self::plain(400, 'Unknown action.');
            return;
        }
        if (!self::canAny($user, $perms, $programId)) {
            self::plain(403, '403 Forbidden — you lack the permission for this step.');
            return;
        }
        try {
            if ($action === 'create') {
                ReviewWorkflow::create($programId, [
                    'title'       => trim((string) ($_POST['title'] ?? '')),
                    'kind'        => (string) ($_POST['kind'] ?? 'deliverable'),
                    'description' => (string) ($_POST['description'] ?? ''),
                    'ref_url'     => (string) ($_POST['ref_url'] ?? ''),
                ], $actorId);
            } else {
                ReviewWorkflow::act((int) ($_POST['id'] ?? 0), $programId, $action, $actorId, (string) ($_POST['comment'] ?? ''));
            }
        } catch (Throwable $e) {
            self::plain(400, 'Action failed: ' . $e->getMessage());
            return;
        }
        header('Location: /app/reviews?program_id=' . $programId);
    }

    private static function canAny(array $user, array $perms, int $programId): bool
    {
        foreach ($perms as $p) {
            if (Authorize::can($user, $p, ['program_id' => $programId])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    private static function programs(array $user, string $perm): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, $perm, ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
