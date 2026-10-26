<?php

declare(strict_types=1);

namespace App\Identity\User;

use App\Identity\Directory\DirectoryUserInfo;
use App\Identity\Oidc\IdTokenClaims;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

final class UserRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM app_user WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * @return array{id: string, is_active: bool, inserted: bool}
     */
    public function upsertFromSignIn(IdTokenClaims $claims, \DateTimeImmutable $now): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            INSERT INTO app_user (idp_tenant_id, idp_object_id, display_name, email, user_principal_name, source, first_login_at, last_login_at)
            VALUES (:tid, :oid, :name, :email, :upn, 'LOGIN', :now, :now)
            ON CONFLICT (idp_tenant_id, idp_object_id) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                email = COALESCE(EXCLUDED.email, app_user.email),
                user_principal_name = COALESCE(EXCLUDED.user_principal_name, app_user.user_principal_name),
                first_login_at = COALESCE(app_user.first_login_at, EXCLUDED.last_login_at),
                last_login_at = EXCLUDED.last_login_at,
                updated_at = now()
            RETURNING id, is_active, (xmax = 0) AS inserted
            SQL, [
            'tid' => $claims->tenantId,
            'oid' => $claims->objectId,
            'name' => $claims->displayName,
            'email' => $claims->email,
            'upn' => $claims->userPrincipalName,
            'now' => $now->format('Y-m-d H:i:s.uP'),
        ]);
        if (false === $row) {
            throw new \LogicException('Upsert returned no row.');
        }

        return ['id' => Row::string($row, 'id'), 'is_active' => Row::bool($row, 'is_active'), 'inserted' => Row::bool($row, 'inserted')];
    }

    /**
     * Creates or refreshes a user seen in a directory group. A null accountEnabled
     * (attribute not returned) leaves the active flag unchanged.
     *
     * @return array{id: string, was_active: bool|null, is_active: bool}
     */
    public function upsertFromDirectory(string $tenantId, DirectoryUserInfo $user, \DateTimeImmutable $now): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            WITH previous AS (
                SELECT is_active FROM app_user WHERE idp_tenant_id = :tid AND idp_object_id = :oid
            )
            INSERT INTO app_user (idp_tenant_id, idp_object_id, display_name, email, user_principal_name, is_active, source, directory_synced_at)
            VALUES (:tid, :oid, :name, :email, :upn, COALESCE(:enabled, true), 'DIRECTORY_SYNC', :now)
            ON CONFLICT (idp_tenant_id, idp_object_id) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                email = COALESCE(EXCLUDED.email, app_user.email),
                user_principal_name = COALESCE(EXCLUDED.user_principal_name, app_user.user_principal_name),
                is_active = COALESCE(:enabled, app_user.is_active),
                directory_synced_at = EXCLUDED.directory_synced_at,
                updated_at = now()
            RETURNING id, is_active, (SELECT is_active FROM previous) AS was_active
            SQL, [
            'tid' => $tenantId,
            'oid' => $user->objectId,
            'name' => $user->displayName,
            'email' => $user->email,
            'upn' => $user->userPrincipalName,
            'enabled' => null === $user->accountEnabled ? null : ($user->accountEnabled ? 'true' : 'false'),
            'now' => $now->format('Y-m-d H:i:s.uP'),
        ]);
        if (false === $row) {
            throw new \LogicException('Upsert returned no row.');
        }

        return [
            'id' => Row::string($row, 'id'),
            'was_active' => null === $row['was_active'] ? null : Row::bool($row, 'was_active'),
            'is_active' => Row::bool($row, 'is_active'),
        ];
    }

    public function updateTimezone(string $id, ?string $timezone): void
    {
        $this->connection->executeStatement('UPDATE app_user SET timezone = ?, updated_at = now() WHERE id = ?', [$timezone, $id]);
    }
}
