<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

/**
 * Organisation-wide roles. Reader is implicit for every active user. System Administrator
 * deliberately carries no document authority (separation of duties).
 */
enum GlobalRole: string
{
    case SystemAdministrator = 'SYSTEM_ADMINISTRATOR';
    case PalAdministrator = 'PAL_ADMINISTRATOR';
    case Publisher = 'PUBLISHER';
    case Author = 'AUTHOR';
    case Reader = 'READER';

    public function symfonyRole(): string
    {
        return 'ROLE_'.$this->value;
    }

    public function isMappable(): bool
    {
        return self::Reader !== $this;
    }

    /**
     * @return list<string>
     */
    public static function mappableValues(): array
    {
        return array_values(array_map(
            fn (self $role): string => $role->value,
            array_filter(self::cases(), fn (self $role): bool => $role->isMappable()),
        ));
    }
}
