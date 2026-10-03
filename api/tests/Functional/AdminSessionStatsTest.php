<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Application\Query\SessionStatsQueryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Shared\Application\Support\StatsPeriod;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 42.2: the Parties section of the admin statistics page.
 */
final class AdminSessionStatsTest extends FunctionalTestCase
{
    private const string NOW = '2026-10-03T12:00:00+00:00';

    public function testTheSectionCountsLaunchesGoalsAndGames(): void
    {
        // 4 weeks: 09-07, 09-14, 09-21, 09-28 (in progress); the previous period starts on 08-10.
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $run = Run::create($owner->getId(), 'Run de test', new \DateTimeImmutable('2026-09-08T10:00:00+00:00'));
        $oldRun = Run::create($owner->getId(), 'Ancienne run', new \DateTimeImmutable('2026-08-20T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->persist($oldRun);
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $celeste = $this->createGame('Celeste', 'celeste');

        // The run is launched, relaunched the next day, then launched again in the last week; still running.
        $this->startedSession('s-run-1', $run->getId(), '2026-09-09T10:00:00+00:00');
        $this->startedSession('s-run-2', $run->getId(), '2026-09-10T10:00:00+00:00');
        $this->startedSession('s-run-3', $run->getId(), '2026-09-29T10:00:00+00:00', Session::STATUS_RUNNING);
        $this->startedSession('s-event', 'event-lan', '2026-09-15T10:00:00+00:00');
        $this->startedSession('s-before', $oldRun->getId(), '2026-08-21T10:00:00+00:00');

        $alice = SessionSlot::create(bin2hex(random_bytes(16)), 's-run-1', $owner->getId(), $hollow->getId(), 'Alice', 1, 'slot-alice');
        $alice->recordGoal(new \DateTimeImmutable('2026-09-29T12:00:00+00:00'));
        $this->entityManager->persist($alice);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), 's-event', 'player-bob', $celeste->getId(), 'Bob', 1, 'slot-bob'));
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-bob', 'player-carol', new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        foreach ([['s-run-1', 'Alice', '2026-09-09T11:00:00+00:00'], ['s-run-1', 'Alice', '2026-09-09T12:00:00+00:00'], ['s-run-1', 'Alice', '2026-08-20T12:00:00+00:00'], ['s-event', 'Bob', '2026-09-15T11:00:00+00:00']] as [$session, $slot, $at]) {
            $this->entityManager->persist($this->checkBy($session, $slot, $at));
        }

        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)),
            'weekly-1',
            $owner->getId(),
            1,
            new \DateTimeImmutable('2026-09-22T10:00:00+00:00'),
            new \DateTimeImmutable('2026-09-23T10:00:00+00:00'),
            launchedAt: new \DateTimeImmutable('2026-09-22T10:00:00+00:00'),
            goalReachedAt: new \DateTimeImmutable('2026-09-23T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement("UPDATE run SET status = 'active' WHERE id = :id", ['id' => $run->getId()]);

        $stats = $this->query()->stats(StatsPeriod::fromCode('4s', new \DateTimeImmutable(self::NOW)));

        self::assertSame(1, $stats['runningSessions']);
        self::assertSame(1, $stats['activeRuns']);

        self::assertSame([1, 0, 0, 0], array_column($stats['runsCreated']['series'], 'value'));
        self::assertSame(1, $stats['runsCreated']['previous']);

        self::assertSame([1, 0, 0, 1], array_column($stats['runsLaunched']['series'], 'value'), 'a relaunch the next day is the same run');
        self::assertSame(1, $stats['runsLaunched']['total'], 'distinct over the period');
        self::assertSame(1, $stats['runsLaunched']['previous']);

        self::assertSame([0, 1, 0, 0], array_column($stats['eventSessionsLaunched']['series'], 'value'));
        self::assertSame([0, 0, 1, 0], array_column($stats['weeklyLaunched']['series'], 'value'));
        self::assertSame([0, 0, 1, 0], array_column($stats['weeklyCompleted']['series'], 'value'));
        self::assertSame([0, 0, 0, 1], array_column($stats['goalsReached']['series'], 'value'));

        self::assertSame([
            ['gameId' => $celeste->getId(), 'name' => 'Celeste', 'players' => 2, 'checks' => 1],
            ['gameId' => $hollow->getId(), 'name' => 'Hollow Knight', 'players' => 1, 'checks' => 2],
        ], $stats['topGames'], 'by distinct players, co-players included; the check before the period is left out');
    }

    public function testTheEndpointIsAdminOnly(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/admin/stats/sessions');
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->request('GET', '/api/v1/admin/stats/sessions?period=12m');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        $period = $body['period'] ?? null;
        self::assertIsArray($period);
        self::assertSame('month', $period['granularity'] ?? null);
        self::assertSame([], $body['topGames'] ?? null);
    }

    private function startedSession(string $id, string $eventId, string $startedAt, string $status = Session::STATUS_FINISHED): void
    {
        $this->entityManager->persist(Session::create($id, $eventId, new \DateTimeImmutable($startedAt)));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE session SET status = :status, started_at = :at WHERE id = :id',
            ['status' => $status, 'at' => $startedAt, 'id' => $id],
        );
    }

    private function checkBy(string $sessionId, string $slotName, string $at): SessionFeedEvent
    {
        return new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $sessionId,
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $slotName.' sent an item',
            new \DateTimeImmutable($at),
            1,
            'Item',
            0,
            2,
            'Location',
            1,
            $slotName,
            'Game',
            2,
            'Someone',
            'Game',
        );
    }

    private function query(): SessionStatsQueryInterface
    {
        $query = self::getContainer()->get(SessionStatsQueryInterface::class);
        self::assertInstanceOf(SessionStatsQueryInterface::class, $query);

        return $query;
    }
}
