<?php

declare(strict_types=1);

namespace App;

use App\Identity\Application\Message\CleanupRefreshTokensMessage;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsTask;
use App\Sessions\Application\ScheduledTask\InactivityWatchdogMessage;
use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
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
                RecurringMessage::cron('5 0 * * *', new CheckMembershipExpiryMessage()),
            )
            ->add(
                RecurringMessage::cron('0 3 * * *', new CleanupRefreshTokensMessage()),
            )
            ->add(
                RecurringMessage::every('2 minutes', new CleanupStaleSessionsTask()),
            )
            ->add(
                RecurringMessage::every('5 minutes', new InactivityWatchdogMessage()),
            )
            ->add(
                RecurringMessage::cron('0 0 * * 1', new GenerateWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            )
            ->add(
                RecurringMessage::cron('59 23 * * 0', new StopWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            );
    }
}
