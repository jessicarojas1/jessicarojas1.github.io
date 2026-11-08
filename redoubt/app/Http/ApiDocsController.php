<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\ApiClients;
use Redoubt\Support\Auth;
use Redoubt\Support\OpenApi;
use Redoubt\Support\Webhooks;

/** Human-readable REST API & webhook documentation. Any authenticated user. */
final class ApiDocsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $spec = OpenApi::spec($host);
        $scopes = ApiClients::SCOPES;
        $events = Webhooks::EVENTS;
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_api_docs.php';
    }
}
