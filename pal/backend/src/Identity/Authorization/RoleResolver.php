<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Current global roles of a user: Reader for everyone, plus roles mapped to the
 * directory groups they currently belong to, plus the bootstrap administrator list.
 */
final class RoleResolver
{
    /** @var list<string> */
    private readonly array $bootstrapAdminObjectIds;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'PAL_BOOTSTRAP_ADMIN_OIDS')]
        string $bootstrapAdminObjectIds,
    ) {
        $this->bootstrapAdminObjectIds = array_values(array_filter(array_map(
            fn (string $id): string => strtolower(trim($id)),
            explode(',', $bootstrapAdminObjectIds),
        )));
    }

    /**
     * @return list<GlobalRole>
     */
    public function rolesFor(string $userId, string $objectId): array
    {
        $roles = [GlobalRole::Reader];
        $mapped = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT m.role
            FROM global_role_mapping m
            JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
            JOIN directory_group g ON g.id = m.group_id AND g.tracked
            WHERE gm.user_id = ?
            SQL, [$userId]);
        foreach (Row::strings($mapped) as $value) {
            $role = GlobalRole::tryFrom($value);
            if (null !== $role && !\in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        if (\in_array(strtolower($objectId), $this->bootstrapAdminObjectIds, true) && !\in_array(GlobalRole::SystemAdministrator, $roles, true)) {
            $roles[] = GlobalRole::SystemAdministrator;
        }

        return $roles;
    }
}
