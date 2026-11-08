<?php

declare(strict_types=1);

namespace App\Audit;

use App\Platform\Http\RequestContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Appends events to the hash-chained audit trail.
 *
 * Call it inside the transaction that makes the audited change, so the change and its
 * audit record commit or roll back together. A transaction-scoped advisory lock
 * serializes writers so each event chains onto the latest committed one. At PAL's
 * volume that global serialization is acceptable; it is the price of a single chain.
 */
final class AuditLogger
{
    /** PostgreSQL advisory-lock key that serialises chain writers (see write()). */
    public const CHAIN_LOCK_KEY = 7_243_001;

    /**
     * SIEM forwards buffered until their transaction commits. Each entry is
     * ['event_id' => string, 'payload' => array]. Flushed by flushPendingForward().
     *
     * @var list<array{event_id: string, payload: array<string, mixed>}>
     */
    private array $pendingForward = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditCanonicalizer $canonicalizer,
        private readonly ClockInterface $clock,
        private readonly RequestContext $requestContext,
        #[Autowire(service: 'monolog.logger.pal_audit')]
        private readonly LoggerInterface $auditLog,
    ) {
    }

    /**
     * @return string the event ID
     */
    public function record(AuditEntry $entry): string
    {
        if ($this->connection->isTransactionActive()) {
            // Nested in a business transaction: buffer the SIEM forward; it is emitted only once
            // that transaction commits (flushPendingForward, called after the request/command or
            // by the next self-managed record), so a rollback never forwards a phantom event.
            return $this->write($entry);
        }

        $eventId = $this->connection->transactional(fn (): string => $this->write($entry));
        $this->flushPendingForward();

        return $eventId;
    }

    /**
     * Forwards buffered events to the SIEM, but only those that are actually committed to
     * audit_event — so events written inside a transaction that then rolled back are dropped.
     * Never runs inside an open transaction (read-your-writes would forward prematurely).
     */
    public function flushPendingForward(): void
    {
        if ([] === $this->pendingForward || $this->connection->isTransactionActive()) {
            return;
        }
        $pending = $this->pendingForward;
        $this->pendingForward = [];

        $ids = array_map(static fn (array $p): string => $p['event_id'], $pending);
        $committed = [];
        foreach ($this->connection->fetchFirstColumn(
            'SELECT event_id FROM audit_event WHERE event_id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        ) as $eventId) {
            if (\is_string($eventId)) {
                $committed[$eventId] = true;
            }
        }
        foreach ($pending as $entry) {
            if (isset($committed[$entry['event_id']])) {
                $this->auditLog->info('audit_event', $entry['payload']);
            }
        }
    }

    private function write(AuditEntry $entry): string
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::CHAIN_LOCK_KEY]);
        $previous = $this->connection->fetchOne('SELECT hash FROM audit_event ORDER BY id DESC LIMIT 1');
        $previousHash = \is_string($previous) ? $previous : AuditCanonicalizer::GENESIS_HASH;

        $occurredAt = $this->clock->now();
        $record = [
            'event_id' => Uuid::v4()->toRfc4122(),
            'occurred_at' => AuditCanonicalizer::formatTimestamp($occurredAt),
            'event_type' => $entry->eventType,
            'actor_user_id' => $entry->actor->userId,
            'actor_snapshot' => $entry->actor->snapshot,
            'correlation_id' => $this->requestContext->correlationId(),
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'from_state' => $entry->fromState,
            'to_state' => $entry->toState,
            'changes' => $entry->changes,
            'reason' => $entry->reason,
        ];
        $hash = $this->canonicalizer->hash($previousHash, $record);

        $this->connection->insert('audit_event', [
            'event_id' => $record['event_id'],
            'occurred_at' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            'event_type' => $record['event_type'],
            'actor_user_id' => $record['actor_user_id'],
            'actor_snapshot' => json_encode($record['actor_snapshot'], \JSON_THROW_ON_ERROR),
            'correlation_id' => $record['correlation_id'],
            'subject_type' => $record['subject_type'],
            'subject_id' => $record['subject_id'],
            'from_state' => $record['from_state'],
            'to_state' => $record['to_state'],
            'changes' => null === $record['changes'] ? null : json_encode($record['changes'], \JSON_THROW_ON_ERROR),
            'reason' => $record['reason'],
            'prev_hash' => $previousHash,
            'hash' => $hash,
        ]);

        // Buffer the SIEM (Microsoft Sentinel) forward until the transaction commits; the database
        // row stays the record of truth. See flushPendingForward().
        $this->pendingForward[] = [
            'event_id' => $record['event_id'],
            'payload' => [
                'event_id' => $record['event_id'],
                'event_type' => $record['event_type'],
                'actor' => $record['actor_snapshot'],
                'subject_type' => $record['subject_type'],
                'subject_id' => $record['subject_id'],
                'from_state' => $record['from_state'],
                'to_state' => $record['to_state'],
            ],
        ];

        return $record['event_id'];
    }
}
