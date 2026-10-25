<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Produces the canonical byte string that is hashed into the audit chain.
 *
 * The same function runs when an event is written and when the chain is verified, so
 * the representation must survive a round trip through PostgreSQL: object keys are
 * sorted (jsonb reorders them) and floats are rejected (their text form is not stable).
 */
final class AuditCanonicalizer
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const FIELDS = [
        'event_id', 'occurred_at', 'event_type', 'actor_user_id', 'actor_snapshot', 'correlation_id',
        'subject_type', 'subject_id', 'from_state', 'to_state', 'changes', 'reason',
    ];

    /**
     * @param array<string, mixed> $record must contain exactly the canonical fields
     */
    public function hash(string $previousHash, array $record): string
    {
        return hash('sha256', $previousHash."\n".$this->canonicalize($record));
    }

    /**
     * @param array<string, mixed> $record
     */
    public function canonicalize(array $record): string
    {
        $keys = array_keys($record);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Audit record does not contain exactly the canonical fields.');
        }

        $ordered = [];
        foreach (self::FIELDS as $field) {
            $ordered[$field] = $this->normalize($record[$field]);
        }

        return json_encode($ordered, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    public static function formatTimestamp(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function normalize(mixed $value): mixed
    {
        if (\is_float($value)) {
            throw new \InvalidArgumentException('Floats are not allowed in audit records.');
        }
        if (\is_object($value)) {
            throw new \InvalidArgumentException('Objects are not allowed in audit records.');
        }
        if (!\is_array($value)) {
            return $value;
        }
        $normalized = array_map(fn (mixed $v): mixed => $this->normalize($v), $value);
        if (!array_is_list($normalized)) {
            ksort($normalized, \SORT_STRING);
        }

        return $normalized;
    }
}
