<?php

declare(strict_types=1);

namespace App;

use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\Community\Application\Message\ExtendDiscordTimeoutsMessage;
use App\Community\Application\Message\PollModerationDirectMessagesMessage;
use App\Community\Application\Message\RecomputeAllAchievementsMessage;
use App\Events\Application\Message\CleanupEventPrivateAccessLogMessage;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use App\GameSelection\Application\Message\SweepApworldCatalogMessage;
use App\Identity\Application\Message\CleanupEmailConfirmationTokensMessage;
use App\Identity\Application\Message\CleanupPasswordResetTokensMessage;
use App\Identity\Application\Message\CleanupRefreshTokensMessage;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Payments\Application\Message\CleanupHelloAssoSyncLogMessage;
use App\Payments\Application\Message\SyncHelloAssoMembershipFormMessage;
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
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
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
                // Backstop for the HelloAsso webhook (story 22.7): a membership payment it missed used to
                // wait for an admin click. Hourly, so a paid membership applies within the hour.
                RecurringMessage::cron('10 * * * *', new SyncHelloAssoMembershipFormMessage()),
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
                // Story 38.5: the apworld version tracker only ever ran by hand. Nightly, before the
                // players are up; the automatic update of story 38.6 hangs off its report.
                RecurringMessage::cron('0 4 * * *', new CheckApworldUpdatesMessage(), new \DateTimeZone('Europe/Paris')),
                // Story 38.9: the rolling catalogue test, a small batch a night. After the version check
                // of 04:00, so a game about to be updated is not retested for nothing.
                RecurringMessage::cron('0 5 * * *', new SweepApworldCatalogMessage(), new \DateTimeZone('Europe/Paris')),
                // Derive apworld incidents from the orchestrator's test verdicts (story 38.1). A pull,
                // not a webhook: orchestrator webhooks have no retry, a lost one would be a lost alert.
                RecurringMessage::every('5 minutes', new ReconcileApworldIncidentsMessage()),
            )
            ->add(
                // Story 39.4: what members answer the bot in private. The bot holds no live connection to
                // Discord, so its DM channels are read back, for the open moderation cases only.
                RecurringMessage::every('1 minute', new PollModerationDirectMessagesMessage()),
            )
            ->add(
                // Story 39.6: Discord caps a timeout at 28 days; a nightly pass keeps a longer suspension going.
                RecurringMessage::cron('30 4 * * *', new ExtendDiscordTimeoutsMessage(), new \DateTimeZone('Europe/Paris')),
            )
            ->add(
                RecurringMessage::cron('0 0 * * 1', new GenerateWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            )
            ->add(
                RecurringMessage::cron('59 23 * * 0', new StopWeeklyRunsMessage(), new \DateTimeZone('UTC')),
            );
    }
}
