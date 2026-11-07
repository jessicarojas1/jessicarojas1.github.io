<?php

declare(strict_types=1);

namespace App\Identity\User;

use App\Audit\AuditActor;
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
        public readonly ?string $tenantId,
        public readonly ?string $objectId,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly ?string $timezone,
        public readonly array $globalRoles,
        /** Set for local (username/password) accounts. */
        public readonly ?string $username = null,
        public readonly bool $mustChangePassword = false,
        /**
         * The local credential's `password_changed_at`, used as a credential version: a change
         * or admin reset moves it, which invalidates every other session via isEqualTo(). Null
         * for SSO accounts (no local credential).
         */
        public readonly ?string $passwordChangedAt = null,
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

    public function isLocal(): bool
    {
        return null !== $this->username;
    }

    public function auditActor(): AuditActor
    {
        return null !== $this->objectId
            ? AuditActor::user($this->id, $this->displayName, $this->objectId)
            : AuditActor::localUser($this->id, $this->displayName, (string) $this->username);
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
        // The credential version IS compared: when a password change or admin reset moves
        // `password_changed_at`, every session still holding the old value is signed out on
        // its next request. The session that performs a self-change is kept valid by
        // MeController refreshing its token (so only other sessions are revoked).
        return $user instanceof self
            && $user->id === $this->id
            && $user->passwordChangedAt === $this->passwordChangedAt;
    }
}
