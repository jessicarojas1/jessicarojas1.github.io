<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Assistant;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Db;

/** Program Assistant — permission-aware retrieval Q&A. Any program member. */
final class AssistantController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'The assistant requires the database.');
            return;
        }
        $programs = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
            $programs[$pid] = $row['name'] ?? ('Program #' . $pid);
        }
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program access.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        $question = trim((string) ($_GET['q'] ?? ''));
        $answer = $question !== '' ? Assistant::ask($user, $programId, $question) : null;
        if ($question !== '') {
            Audit::log('assistant.ask', mb_substr($question, 0, 80), $programId, $user['id'] ?? null);
        }
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_assistant.php';
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
