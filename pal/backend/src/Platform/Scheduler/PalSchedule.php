<?php

declare(strict_types=1);

namespace App\Platform\Scheduler;

use App\Audit\Message\VerifyAuditChain;
use App\Identity\Message\SyncDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring jobs, run by `messenger:consume scheduler_default`. The PostgreSQL advisory
 * lock keeps a second scheduler process from double-firing; the stateful cache lets a
 * restarted scheduler catch up on runs missed while it was down.
 */
#[AsSchedule('default')]
final class PalSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        #[Autowire(service: 'pal.lock.factory')]
        private readonly LockFactory $locks,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::every('15 minutes', new SyncDirectory()),
                RecurringMessage::every('1 day', new VerifyAuditChain(anchor: true), new \DateTimeImmutable('today 02:15', new \DateTimeZone('UTC'))),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->locks->createLock('pal-schedule-default'));
    }
}
