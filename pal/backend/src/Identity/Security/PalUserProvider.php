<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Authorization\RoleResolver;
use App\Identity\User\PalUser;
use App\Identity\User\UserRepository;
use App\Platform\Database\Row;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<PalUser>
 */
final class PalUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleResolver $roles,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): PalUser
    {
        $row = $this->users->findById($identifier);
        if (null === $row || !Row::bool($row, 'is_active')) {
            throw new UserNotFoundException('User not found or disabled.');
        }
        $id = Row::string($row, 'id');
        $objectId = Row::string($row, 'idp_object_id');

        return new PalUser(
            $id,
            Row::string($row, 'idp_tenant_id'),
            $objectId,
            Row::string($row, 'display_name'),
            Row::nullableString($row, 'email'),
            Row::nullableString($row, 'timezone'),
            $this->roles->rolesFor($id, $objectId),
        );
    }

    public function refreshUser(UserInterface $user): PalUser
    {
        if (!$user instanceof PalUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->id);
    }

    public function supportsClass(string $class): bool
    {
        return PalUser::class === $class;
    }
}
