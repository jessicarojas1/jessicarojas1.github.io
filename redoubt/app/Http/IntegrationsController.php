<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\ApiClients;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Connectors;
use Redoubt\Support\Db;
use Redoubt\Support\Digest;
use Redoubt\Support\Security;
use Redoubt\Support\Session;
use Redoubt\Support\Webhooks;

/**
 * Integrations console — connect API clients and webhooks. Requires
 * integration.config. Secrets (API token / webhook signing secret) are shown
 * exactly once, immediately after creation, via a one-request session flash.
 */
final class IntegrationsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Integrations require the database.');
            return;
        }
        $programs = self::programs($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — integrations require integration.config.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'integration.config', ['program_id' => $programId]);

        $clients = ApiClients::listForProgram($programId);
        $subs = Webhooks::listSubscriptions($programId);
        $deliveries = [];
        foreach ($subs as $s) {
            $deliveries[$s['id']] = Webhooks::listDeliveries((int) $s['id'], 5);
        }
        $scopes = ApiClients::SCOPES;
        $events = Webhooks::EVENTS;
        $connectors = Connectors::listForProgram($programId);
        $kinds = Connectors::KINDS;
        $flash = self::takeFlash();
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_integrations.php';
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
        Authorize::requirePermission($user, 'integration.config', ['program_id' => $programId]);
        $actorId = $user['id'] ?? null;

        switch ((string) ($_POST['action'] ?? '')) {
            case 'create_key':
                $scopes = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
                $token = ApiClients::create($programId, trim((string) ($_POST['name'] ?? '')), $scopes, $actorId);
                self::setFlash('token', $token);
                break;
            case 'revoke_key':
                ApiClients::revoke((int) ($_POST['id'] ?? 0), $programId, $actorId);
                break;
            case 'create_hook':
                $url = trim((string) ($_POST['url'] ?? ''));
                if (!preg_match('#^https?://#i', $url)) {
                    self::plain(400, 'Webhook URL must start with http(s)://');
                    return;
                }
                $events = is_array($_POST['events'] ?? null) ? $_POST['events'] : [];
                $secret = Webhooks::createSubscription($programId, $url, $events, $actorId);
                self::setFlash('secret', $secret);
                break;
            case 'delete_hook':
                Webhooks::deleteSubscription((int) ($_POST['id'] ?? 0), $programId, $actorId);
                break;
            case 'test_hook':
                Webhooks::sendTest((int) ($_POST['id'] ?? 0), $programId, $actorId);
                self::setFlash('info', 'Test event sent — check the delivery log below.');
                break;
            case 'create_connector':
                $url = trim((string) ($_POST['base_url'] ?? ''));
                $hook = trim((string) ($_POST['webhook_url'] ?? ''));
                Connectors::create($programId, (string) ($_POST['kind'] ?? 'other'), [
                    'base_url'    => ($url !== '' && preg_match('#^https?://#i', $url)) ? $url : null,
                    'webhook_url' => ($hook !== '' && preg_match('#^https?://#i', $hook)) ? $hook : null,
                    'note'        => trim((string) ($_POST['note'] ?? '')) ?: null,
                ], trim((string) ($_POST['secret_ref'] ?? '')), $actorId);
                break;
            case 'send_digest':
                $r = Digest::run($programId, $actorId);
                if ($r['recipients'] === 0) {
                    $msg = 'No one has pending reviews right now — nothing to send.';
                } elseif ($r['reason'] !== null) {
                    $msg = 'Prepared digests for ' . $r['recipients'] . ' recipient(s), but ' . $r['reason'] . '.';
                } else {
                    $msg = 'Review digests sent to ' . $r['recipients'] . ' recipient(s): '
                        . $r['emails_sent'] . ' email(s)' . ($r['teams'] ? ' + Teams card' : '')
                        . ($r['emails_skipped'] > 0 ? ' (' . $r['emails_skipped'] . ' skipped)' : '') . '.';
                }
                self::setFlash('info', $msg);
                break;
            case 'toggle_connector':
                Connectors::setActive((int) ($_POST['id'] ?? 0), $programId, ($_POST['active'] ?? '') === '1', $actorId);
                break;
            case 'delete_connector':
                Connectors::delete((int) ($_POST['id'] ?? 0), $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/admin/integrations?program_id=' . $programId);
    }

    private static function setFlash(string $type, string $value): void
    {
        Session::start();
        $_SESSION['int_flash'] = ['type' => $type, 'value' => $value];
    }

    /** @return array{type:string,value:string}|null */
    private static function takeFlash(): ?array
    {
        Session::start();
        $f = $_SESSION['int_flash'] ?? null;
        unset($_SESSION['int_flash']);
        return is_array($f) ? $f : null;
    }

    /** @return array<int,string> */
    private static function programs(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, 'integration.config', ['program_id' => $pid])) {
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
