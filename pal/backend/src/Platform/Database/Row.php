<?php

declare(strict_types=1);

namespace App\Platform\Database;

/**
 * Typed access to values from DBAL rows and decoded JSON. A value of the wrong type is
 * a schema or contract bug, so it fails loudly instead of being cast into something.
 */
final class Row
{
    /**
     * @param array<mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected "%s" to be a string, got %s.', $key, get_debug_type($value)));
    }

    /**
     * @param array<mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        return null === ($row[$key] ?? null) ? null : self::string($row, $key);
    }

    /**
     * @param array<mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected "%s" to be an integer, got %s.', $key, get_debug_type($value)));
    }

    /**
     * @param array<mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected "%s" to be a boolean, got %s.', $key, get_debug_type($value)));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    public static function strings(array $values): array
    {
        return array_map(static function (mixed $value): string {
            if (\is_string($value)) {
                return $value;
            }

            throw new \UnexpectedValueException('Expected a list of strings, got '.get_debug_type($value).'.');
        }, $values);
    }

    public static function scalarString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException('Expected a string, got '.get_debug_type($value).'.');
    }
}
