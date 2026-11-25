<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Application\Query\GamePaceQueryInterface;
use App\Sessions\Application\Query\PaceSample;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;

/**
 * Story 56.2, fixed after the 1.1.0 review: a slot's pace is its checks over the time it was actually played, not
 * over the days an asynchronous run lasted.
 */
final class GamePaceQueryTest extends FunctionalTestCase
{
    public function testAPaceCountsOnlyThePlayedTimeUpToTheGoal(): void
    {
        $this->entityManager->persist(Session::createRunning('s1', 'e1', 'host', 38281, null, null, new \DateTimeImmutable('2026-10-01T20:00:00+00:00')));
        // Five evenings: the goal is reached on the fifth, about 100 h after the launch.
        $this->goalSlot('s1', 'Zoe', 60, '2026-10-05T23:00:00+00:00');
        $this->span('s1', 'Zoe', '2026-10-01T20:00:00', '2026-10-01T23:00:00');
        $this->span('s1', 'Zoe', '2026-10-05T21:00:00', '2026-10-06T01:00:00');
        $this->entityManager->flush();

        $samples = $this->samples();

        // 3 h on the first evening, then 2 h until the goal (the hours after it do not count): 60 checks in 5 h.
        self::assertCount(1, $samples);
        self::assertEqualsWithDelta(12.0, $samples[0]->checksPerHour, 0.001);
    }

    public function testASlotNeverSeenConnectedKeepsTheTimeFromLaunchToGoal(): void
    {
        $this->entityManager->persist(Session::createRunning('s1', 'e1', 'host', 38281, null, null, new \DateTimeImmutable('2026-10-01T20:00:00+00:00')));
        $this->goalSlot('s1', 'Max', 40, '2026-10-01T22:00:00+00:00');
        $this->entityManager->flush();

        $samples = $this->samples();

        self::assertCount(1, $samples);
        self::assertEqualsWithDelta(20.0, $samples[0]->checksPerHour, 0.001);
    }

    /** @return list<PaceSample> */
    private function samples(): array
    {
        $query = self::getContainer()->get(GamePaceQueryInterface::class);
        self::assertInstanceOf(GamePaceQueryInterface::class, $query);

        return array_values(array_filter($query->samples(), static fn (PaceSample $sample): bool => 'game-1' === $sample->gameId));
    }

    private function goalSlot(string $session, string $name, int $checks, string $goal): void
    {
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session, 'registration-'.$name, 'game-1', $name, 1);
        $slot->recordProgress($checks, 0);
        $slot->recordGoal(new \DateTimeImmutable($goal));
        $this->entityManager->persist($slot);
    }

    private function span(string $session, string $slot, string $from, string $to): void
    {
        $span = SlotPlaySpan::open(bin2hex(random_bytes(16)), $session, $slot, new \DateTimeImmutable($from.'+00:00'));
        $span->extendTo(new \DateTimeImmutable($to.'+00:00'));
        $this->entityManager->persist($span);
    }
}
