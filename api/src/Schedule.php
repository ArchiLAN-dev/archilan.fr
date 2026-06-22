<?php

declare(strict_types=1);

namespace App;

use App\Community\Application\Message\RecomputeAllAchievementsMessage;
use App\Events\Application\Message\CleanupEventPrivateAccessLogMessage;
use App\Identity\Application\Message\CleanupEmailConfirmationTokensMessage;
use App\Identity\Application\Message\CleanupPasswordResetTokensMessage;
use App\Identity\Application\Message\CleanupRefreshTokensMessage;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Payments\Application\Message\CleanupHelloAssoSyncLogMessage;
use App\PersonalRuns\Application\Message\ReconcileStuckRunsMessage;
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
                // Backstop: catch any achievement unlock the real-time post-archive path missed
                // (story 30.26). Runs hourly (at :45) so a missed unlock is reconciled within the hour.
                RecurringMessage::cron('45 * * * *', new RecomputeAllAchievementsMessage()),
            )
            ->add(
                RecurringMessage::every('2 minutes', new CleanupStaleSessionsTask()),
            )
            ->add(
                // Backstop côté run : tourne juste après le watchdog session, pour avancer une run dont
                // le webhook de cycle de vie s'est perdu une fois la session résolue (story 17.14).
                RecurringMessage::every('2 minutes', new ReconcileStuckRunsMessage()),
            )
            ->add(
                RecurringMessage::cron('0 0 * * 1', new GenerateWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            )
            ->add(
                RecurringMessage::cron('59 23 * * 0', new StopWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            );
    }
}
