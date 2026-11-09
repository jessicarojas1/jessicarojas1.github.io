<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

/**
 * Organisation-wide roles. Reader is implicit for every active user. System Administrator
 * deliberately carries no document authority (separation of duties).
 *
 * Owner is the superuser (god mode): it holds every permission and is the account's root of
 * trust. It deliberately collapses separation of duties, so it is handled specially —
 * granted or revoked only by another Owner (UserAdminController), never conferred through a
 * directory-group mapping, and bootstrapped out of band (the local bootstrap admin, or
 * PAL_BOOTSTRAP_OWNER_OIDS for Entra). Its actions remain fully audited like any other.
 */
enum GlobalRole: string
{
    case Owner = 'OWNER';
    case SystemAdministrator = 'SYSTEM_ADMINISTRATOR';
    case PalAdministrator = 'PAL_ADMINISTRATOR';
    case Publisher = 'PUBLISHER';
    case Author = 'AUTHOR';
    case Reader = 'READER';

    public function symfonyRole(): string
    {
        return 'ROLE_'.$this->value;
    }

    /**
     * Whether a directory group may be mapped to this role. Reader is implicit, and Owner
     * (god mode) is never spread through directory sync — it is granted explicitly only.
     */
    public function isMappable(): bool
    {
        return self::Reader !== $this && self::Owner !== $this;
    }

    /**
     * Whether this role can be granted explicitly to a single user (user_role). Everything
     * except the implicit Reader, including Owner (which an existing Owner alone may grant).
     */
    public function isAssignable(): bool
    {
        return self::Reader !== $this;
    }

    /**
     * Roles that carry authority over document content or releases. Granting them is
     * subject to separation of duties (a user may not grant them to a group they belong to).
     * Owner has every authority by definition.
     */
    public function hasDocumentAuthority(): bool
    {
        return \in_array($this, [self::Owner, self::PalAdministrator, self::Publisher, self::Author], true);
    }

    /**
     * Roles a directory group can be mapped to.
     *
     * @return list<string>
     */
    public static function mappableValues(): array
    {
        return array_values(array_map(
            fn (self $role): string => $role->value,
            array_filter(self::cases(), fn (self $role): bool => $role->isMappable()),
        ));
    }

    /**
     * Roles that can be granted explicitly to a user.
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return array_values(array_map(
            fn (self $role): string => $role->value,
            array_filter(self::cases(), fn (self $role): bool => $role->isAssignable()),
        ));
    }
}
