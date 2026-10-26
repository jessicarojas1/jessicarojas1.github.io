<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * People picker (choosing approvers, granting access). Returns active users only, and
 * only names and email: the internal directory every employee already sees.
 */
final class UserSearchController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/api/users', name: 'api_user_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 100));
        if (mb_strlen($query) < 2) {
            return new JsonResponse(['users' => []]);
        }
        $like = '%'.addcslashes($query, '%_\\').'%';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, email FROM app_user WHERE is_active AND (display_name ILIKE ? OR email ILIKE ? OR user_principal_name ILIKE ?) ORDER BY display_name LIMIT 20',
            [$like, $like, $like],
        );

        return new JsonResponse(['users' => array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'displayName' => Row::string($r, 'display_name'),
            'email' => Row::nullableString($r, 'email'),
        ], $rows)]);
    }
}
