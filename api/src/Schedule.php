<?php

declare(strict_types=1);

namespace App;

use App\Events\Application\Message\CleanupEventPrivateAccessLogMessage;
use App\Identity\Application\Message\CleanupEmailConfirmationTokensMessage;
use App\Identity\Application\Message\CleanupPasswordResetTokensMessage;
use App\Identity\Application\Message\CleanupRefreshTokensMessage;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Payments\Application\Message\CleanupHelloAssoSyncLogMessage;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsTask;
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
                RecurringMessage::cron('15 3 * * *', new CleanupEmailConfirmationTokensMessage()),
            )
            ->add(
                RecurringMessage::cron('20 3 * * *', new CleanupPasswordResetTokensMessage()),
            )
            ->add(
                RecurringMessage::cron('25 3 * * *', new CleanupHelloAssoSyncLogMessage()),
            )
            ->add(
                RecurringMessage::cron('30 3 * * *', new CleanupEventPrivateAccessLogMessage()),
            )
            ->add(
                RecurringMessage::every('2 minutes', new CleanupStaleSessionsTask()),
            )
            ->add(
                RecurringMessage::cron('0 0 * * 1', new GenerateWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            )
            ->add(
                RecurringMessage::cron('59 23 * * 0', new StopWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            );
    }
}
