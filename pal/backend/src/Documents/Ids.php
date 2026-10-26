<?php

declare(strict_types=1);

namespace App\Documents;

final class Ids
{
    public static function isUuid(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value);
    }
}
