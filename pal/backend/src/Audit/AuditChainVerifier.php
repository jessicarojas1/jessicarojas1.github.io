<?php

declare(strict_types=1);

namespace App\Audit;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Recomputes every hash in the audit chain. Detects edits, deletions, insertions and
 * reordering made outside the application (e.g. directly in the database). It cannot
 * detect a rewrite that also recomputes all later hashes; the external anchor of the
 * head hash covers that case.
 */
final class AuditChainVerifier
{
    private const BATCH = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditCanonicalizer $canonicalizer,
    ) {
    }

    public function verify(): AuditChainReport
    {
        $previousHash = AuditCanonicalizer::GENESIS_HASH;
        $lastId = 0;
        $checked = 0;

        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM audit_event WHERE id > ? ORDER BY id ASC LIMIT '.self::BATCH,
                [$lastId],
            );
            if ([] === $rows) {
                break;
            }
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                if (Row::string($row, 'prev_hash') !== $previousHash) {
                    return new AuditChainReport(false, $checked, $lastId ?: null, $previousHash, $id, 'prev_hash does not match the preceding event');
                }
                $recomputed = $this->canonicalizer->hash($previousHash, self::recordFromRow($row));
                if (!hash_equals($recomputed, Row::string($row, 'hash'))) {
                    return new AuditChainReport(false, $checked, $lastId ?: null, $previousHash, $id, 'event content does not match its hash');
                }
                $previousHash = Row::string($row, 'hash');
                $lastId = $id;
                ++$checked;
            }
        }

        return new AuditChainReport(true, $checked, $lastId ?: null, $previousHash);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function recordFromRow(array $row): array
    {
        return [
            'event_id' => $row['event_id'],
            'occurred_at' => AuditCanonicalizer::formatTimestamp(new \DateTimeImmutable(Row::string($row, 'occurred_at'))),
            'event_type' => $row['event_type'],
            'actor_user_id' => $row['actor_user_id'],
            'actor_snapshot' => self::decodeJson(Row::nullableString($row, 'actor_snapshot')),
            'correlation_id' => $row['correlation_id'],
            'subject_type' => $row['subject_type'],
            'subject_id' => $row['subject_id'],
            'from_state' => $row['from_state'],
            'to_state' => $row['to_state'],
            'changes' => self::decodeJson(Row::nullableString($row, 'changes')),
            'reason' => $row['reason'],
        ];
    }

    private static function decodeJson(?string $value): mixed
    {
        return null === $value ? null : json_decode($value, true, 64, \JSON_THROW_ON_ERROR);
    }
}
