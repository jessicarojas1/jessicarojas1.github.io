<?php

declare(strict_types=1);

namespace App\Settings;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SettingsRepository
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'PAL_DEFAULT_TIMEZONE')]
        private readonly string $fallbackTimezone,
    ) {
    }

    public function get(string $key): mixed
    {
        $value = $this->connection->fetchOne('SELECT value FROM org_setting WHERE key = ?', [$key]);

        return false === $value ? null : json_decode(Row::scalarString($value), true, 32, \JSON_THROW_ON_ERROR);
    }

    public function set(string $key, mixed $value, ?string $userId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO org_setting (key, value, updated_by, updated_at) VALUES (?, ?, ?, now())
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()
            SQL, [$key, json_encode($value, \JSON_THROW_ON_ERROR), $userId]);
    }

    public function defaultTimezone(): string
    {
        $stored = $this->get('default_timezone');
        $candidate = \is_string($stored) ? $stored : $this->fallbackTimezone;

        return \in_array($candidate, \DateTimeZone::listIdentifiers(), true) ? $candidate : 'UTC';
    }
}
