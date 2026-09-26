<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
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
