<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\AuditCanonicalizer;
use PHPUnit\Framework\TestCase;

final class AuditCanonicalizerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'event_id' => '0b6c1f5e-1d1a-4f0e-9a51-6c3f8b9e2a10',
            'occurred_at' => '2026-10-26T12:00:00.123456Z',
            'event_type' => 'USER_SIGNED_IN',
            'actor_user_id' => null,
            'actor_snapshot' => ['type' => 'system', 'name' => 'test'],
            'correlation_id' => 'abc12345',
            'subject_type' => null,
            'subject_id' => null,
            'from_state' => null,
            'to_state' => null,
            'changes' => ['b' => 1, 'a' => ['y' => true, 'x' => null]],
            'reason' => null,
        ], $overrides);
    }

    public function testNestedKeyOrderDoesNotChangeTheHash(): void
    {
        $c = new AuditCanonicalizer();
        $reordered = $this->record(['changes' => ['a' => ['x' => null, 'y' => true], 'b' => 1]]);

        self::assertSame($c->hash(AuditCanonicalizer::GENESIS_HASH, $this->record()), $c->hash(AuditCanonicalizer::GENESIS_HASH, $reordered));
    }

    public function testAnyFieldChangeChangesTheHash(): void
    {
        $c = new AuditCanonicalizer();
        $base = $c->hash(AuditCanonicalizer::GENESIS_HASH, $this->record());

        self::assertNotSame($base, $c->hash(AuditCanonicalizer::GENESIS_HASH, $this->record(['reason' => 'x'])));
        self::assertNotSame($base, $c->hash(str_repeat('1', 64), $this->record()));
    }

    public function testListsKeepTheirOrder(): void
    {
        $c = new AuditCanonicalizer();

        self::assertNotSame(
            $c->canonicalize($this->record(['changes' => ['ids' => ['1', '2']]])),
            $c->canonicalize($this->record(['changes' => ['ids' => ['2', '1']]])),
        );
    }

    public function testFloatsAreRejectedBecauseTheyDoNotRoundTrip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AuditCanonicalizer())->canonicalize($this->record(['changes' => ['ratio' => 0.1]]));
    }

    public function testMissingOrExtraFieldsAreRejected(): void
    {
        $record = $this->record();
        unset($record['reason']);
        $this->expectException(\InvalidArgumentException::class);
        (new AuditCanonicalizer())->canonicalize($record);
    }

    public function testTimestampsAreNormalisedToUtcMicroseconds(): void
    {
        $at = new \DateTimeImmutable('2026-11-01 01:30:00.5-06:00');

        self::assertSame('2026-11-01T07:30:00.500000Z', AuditCanonicalizer::formatTimestamp($at));
    }
}
