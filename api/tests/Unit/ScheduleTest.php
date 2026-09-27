<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\Community\Application\Message\ExtendDiscordTimeoutsMessage;
use App\Community\Application\Message\PollModerationDirectMessagesMessage;
use App\GameSelection\Application\Message\SweepApworldCatalogMessage;
use App\Payments\Application\Message\SyncHelloAssoMembershipFormMessage;
use App\Schedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

final class ScheduleTest extends TestCase
{
    public function testTheApworldVersionCheckRunsEveryNightAtFourParisTime(): void
    {
        // Story 38.5: the version tracker only ever ran by hand, and sat on 0.16.0 while 0.18.2 was out.
        $recurring = $this->recurringMessageFor(CheckApworldUpdatesMessage::class);

        $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertNotNull($next);
        self::assertSame('2026-09-26 04:00', $next->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'));
    }

    public function testTheCatalogueSweepRunsEveryNightAtFiveParisTime(): void
    {
        // Story 38.9: after the version check of 04:00, so a game about to be updated is not retested.
        $recurring = $this->recurringMessageFor(SweepApworldCatalogMessage::class);

        $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertNotNull($next);
        self::assertSame('2026-09-26 05:00', $next->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'));
    }

    public function testTheMembershipPaymentsAreSyncedEveryHour(): void
    {
        // Story 22.7: the webhook was the only automatic path; a missed one waited for an admin click.
        $recurring = $this->recurringMessageFor(SyncHelloAssoMembershipFormMessage::class);

        $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-09-27 12:30:00', new \DateTimeZone('UTC')));

        self::assertNotNull($next);
        self::assertSame('2026-09-27 13:10', $next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function testTheAnswersToTheBotAreReadEveryMinute(): void
    {
        // Story 39.4: the bot holds no live connection; its DM channels are read back.
        $recurring = $this->recurringMessageFor(PollModerationDirectMessagesMessage::class);

        $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-09-27 12:30:00', new \DateTimeZone('UTC')));

        self::assertNotNull($next);
        self::assertLessThanOrEqual(60, $next->getTimestamp() - new \DateTimeImmutable('2026-09-27 12:30:00', new \DateTimeZone('UTC'))->getTimestamp());
    }

    public function testDiscordTimeoutsAreExtendedEveryNight(): void
    {
        // Story 39.6: Discord caps a timeout at 28 days; a daily pass keeps a longer suspension going.
        $recurring = $this->recurringMessageFor(ExtendDiscordTimeoutsMessage::class);

        $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-09-27 12:30:00', new \DateTimeZone('UTC')));

        self::assertNotNull($next);
        self::assertSame('2026-09-28 04:30', $next->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'));
    }

    /**
     * @param class-string $messageClass
     */
    private function recurringMessageFor(string $messageClass): RecurringMessage
    {
        $schedule = new Schedule(new ArrayAdapter())->getSchedule();

        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof $messageClass) {
                    return $recurring;
                }
            }
        }

        self::fail(sprintf('%s is not scheduled.', $messageClass));
    }
}
