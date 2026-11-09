<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Current global roles of a user: Reader for everyone, plus roles mapped to the
 * directory groups they currently belong to, plus the bootstrap administrator and
 * bootstrap owner lists.
 */
final class RoleResolver
{
    /** @var list<string> */
    private readonly array $bootstrapAdminObjectIds;
    /** @var list<string> */
    private readonly array $bootstrapOwnerObjectIds;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'PAL_BOOTSTRAP_ADMIN_OIDS')]
        string $bootstrapAdminObjectIds,
        #[Autowire(env: 'default::PAL_BOOTSTRAP_OWNER_OIDS')]
        string $bootstrapOwnerObjectIds = '',
    ) {
        $this->bootstrapAdminObjectIds = self::parseObjectIds($bootstrapAdminObjectIds);
        $this->bootstrapOwnerObjectIds = self::parseObjectIds($bootstrapOwnerObjectIds);
    }

    /**
     * @return list<string>
     */
    private static function parseObjectIds(string $raw): array
    {
        return array_values(array_filter(array_map(
            fn (string $id): string => strtolower(trim($id)),
            explode(',', $raw),
        )));
    }

    /**
     * @return list<GlobalRole>
     */
    public function rolesFor(string $userId, ?string $objectId): array
    {
        $roles = [GlobalRole::Reader];
        $mapped = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT m.role
            FROM global_role_mapping m
            JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
            JOIN directory_group g ON g.id = m.group_id AND g.tracked
            WHERE gm.user_id = :user
            UNION
            SELECT ur.role FROM user_role ur WHERE ur.user_id = :user
            SQL, ['user' => $userId]);
        foreach (Row::strings($mapped) as $value) {
            $role = GlobalRole::tryFrom($value);
            if (null !== $role && !\in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        if (null !== $objectId) {
            $normalisedObjectId = strtolower($objectId);
            if (\in_array($normalisedObjectId, $this->bootstrapAdminObjectIds, true) && !\in_array(GlobalRole::SystemAdministrator, $roles, true)) {
                $roles[] = GlobalRole::SystemAdministrator;
            }
            // The Entra root of trust: designated object IDs are Owners (god mode) until an
            // Owner is granted in-app. Mirrors the local bootstrap Owner for SSO deployments.
            if (\in_array($normalisedObjectId, $this->bootstrapOwnerObjectIds, true) && !\in_array(GlobalRole::Owner, $roles, true)) {
                $roles[] = GlobalRole::Owner;
            }
        }

        return $roles;
    }
}
