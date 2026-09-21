<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Faq;
use Redoubt\Support\Security;

/** FAQ / Knowledge Base module (Annex H). Members view; faq.manage maintains. */
final class FaqController
{
    public static function index(string $nonce): void
    {
        [$user, $programId, $programs] = self::ctx();
        if ($user === null) {
            return;
        }
        $isManager = Authorize::can($user, 'faq.manage', ['program_id' => $programId]);
        $items = Faq::listForUser($user, $programId, $isManager);
        $can = ['manage' => $isManager];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_faq.php';
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
        Authorize::requirePermission($user, 'faq.manage', ['program_id' => $programId]);
        $actorId = $user['id'] ?? null;
        $data = [
            'question' => trim((string) ($_POST['question'] ?? '')),
            'answer'   => trim((string) ($_POST['answer'] ?? '')),
            'audience' => self::audience(),
        ];
        switch ((string) ($_POST['action'] ?? '')) {
            case 'create': Faq::create($programId, $data, $actorId); break;
            case 'update': Faq::update((int) ($_POST['id'] ?? 0), $programId, $data, $actorId); break;
            case 'delete': Faq::delete((int) ($_POST['id'] ?? 0), $programId, $actorId); break;
            default: self::plain(400, 'Unknown action.'); return;
        }
        header('Location: /app/faq?program_id=' . $programId);
    }

    /** @return array{0:?array,1:int,2:array<int,string>} */
    private static function ctx(): array
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'FAQ requires the database.');
            return [null, 0, []];
        }
        $programs = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
            $programs[$pid] = $row['name'] ?? ('Program #' . $pid);
        }
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program access.');
            return [null, 0, []];
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        return [$user, $programId, $programs];
    }

    /** @return string[] */
    private static function audience(): array
    {
        $a = [];
        foreach (['all', 'internal', 'customer'] as $t) {
            if (!empty($_POST['aud_' . $t])) {
                $a[] = $t;
            }
        }
        return $a;
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
