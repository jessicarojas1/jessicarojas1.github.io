<?php

declare(strict_types=1);

namespace App\Identity\User;

use App\Identity\Authorization\GlobalRole;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The signed-in user. Reloaded from the database on every request, so role changes from
 * directory sync apply without signing out, and disabled accounts lose access at once.
 */
final class PalUser implements UserInterface, EquatableInterface
{
    /** @var non-empty-string */
    public readonly string $id;

    /**
     * @param list<GlobalRole> $globalRoles
     */
    public function __construct(
        string $id,
        public readonly string $tenantId,
        public readonly string $objectId,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly ?string $timezone,
        public readonly array $globalRoles,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A user must have an ID.');
        }
        $this->id = $id;
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_map(fn (GlobalRole $role): string => $role->symfonyRole(), $this->globalRoles);
    }

    public function hasRole(GlobalRole $role): bool
    {
        return \in_array($role, $this->globalRoles, true);
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No credentials are held: authentication is delegated to Entra ID.
    }

    public function isEqualTo(UserInterface $user): bool
    {
        // Roles are intentionally not compared: authorization reads current roles from the
        // refreshed user, and comparing them would sign users out on every role change.
        return $user instanceof self && $user->id === $this->id;
    }
}
