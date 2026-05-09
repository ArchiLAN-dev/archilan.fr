<?php

declare(strict_types=1);

namespace App;

use App\Identity\Application\Message\CleanupRefreshTokensMessage;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsTask;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::cron('0 3 * * *', new CleanupRefreshTokensMessage()),
            )
            ->add(
                RecurringMessage::every('2 minutes', new CleanupStaleSessionsTask()),
            );
    }
}
