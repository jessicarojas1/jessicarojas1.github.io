<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Db;
use Redoubt\Support\Portfolio;

/** Leadership portfolio roll-up across all of the user's programs. */
final class PortfolioController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'The portfolio view requires the database.');
            return;
        }
        if (($user['memberships'] ?? []) === []) {
            self::plain(403, '403 Forbidden — no program access.');
            return;
        }
        $rows = Portfolio::forUser($user);
        $totals = Portfolio::totals($rows);
        Audit::log('portfolio.view', 'programs=' . count($rows), null, $user['id'] ?? null);
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_portfolio.php';
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
