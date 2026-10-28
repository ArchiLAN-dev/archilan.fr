<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\PlayTimeStatsQueryInterface;
use App\Community\Application\Support\MetricBagBuilder;
use App\Community\Domain\AchievementMetricCatalog;
use App\Sessions\Application\Query\RunResultsQuery;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Entity\SlotPlaySpan;

/**
 * Story 32.26: the bridge's heartbeat names the slots a game client holds; play time is the sum of the spans
 * it grows, shown per slot in the run results and counted in the achievement facts.
 */
final class PlayTimeTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testTheHeartbeatOpensASpanForEachHeldSlotOfTheSession(): void
    {
        $this->entityManager->persist(Session::createRunning('s1', 'e1', 'host', 38281, null, null, new \DateTimeImmutable()));
        $this->slot('s1', 'user-a', 'Zoe', 'slot-zoe');
        $this->slot('s1', 'user-b', 'Max', 'slot-max');
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/v1/internal/sessions/s1/heartbeat', [
            'connected_slots' => [['slot' => 2, 'name' => 'Zoe'], ['slot' => 9, 'name' => 'Stranger'], ['slot' => 3], 'junk'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', '/api/v1/internal/sessions/s1/heartbeat', [
            'connected_slots' => [['slot' => 2, 'name' => 'Zoe']],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $spans = $this->entityManager->getRepository(SlotPlaySpan::class)->findBy(['sessionId' => 's1']);
        self::assertCount(1, $spans, 'one span, grown by the second heartbeat; the unknown slot is dropped');
        self::assertSame('Zoe', $spans[0]->getSlotName());
    }

    public function testAHeartbeatWithoutABodyStillWorks(): void
    {
        $this->entityManager->persist(Session::createRunning('s1', 'e1', 'host', 38281, null, null, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->request('POST', '/api/v1/internal/sessions/s1/heartbeat', server: ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(SlotPlaySpan::class)->findBy(['sessionId' => 's1']));
    }

    public function testTheRunResultsCarryEachSlotPlayTime(): void
    {
        $session = Session::createRunning('s1', 'e1', 'host', 38281, null, null, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $session->transition(Session::STATUS_FINISHED, new \DateTimeImmutable('2026-10-01T14:00:00+00:00'));
        $this->entityManager->persist($session);
        $this->slot('s1', 'user-a', 'Zoe', 'slot-zoe');
        $this->slot('s1', 'user-b', 'Max', 'slot-max');
        $this->span('s1', 'Zoe', '10:00:00', '11:00:00');
        $this->span('s1', 'Zoe', '12:00:00', '12:30:00');
        $this->entityManager->flush();

        $query = self::getContainer()->get(RunResultsQuery::class);
        self::assertInstanceOf(RunResultsQuery::class, $query);
        $results = $query->execute('s1');
        self::assertNotNull($results);

        $played = [];
        foreach ($results['slots'] as $slot) {
            self::assertIsString($slot['slotName']);
            $played[$slot['slotName']] = $slot['playedSeconds'];
        }
        self::assertSame(5400, $played['Zoe'] ?? null, 'two spans: 1 h + 30 min');
        self::assertSame(0, $played['Max'] ?? null, 'never seen connected');
    }

    public function testThePlayTimeCountsForThePlayersOfTheSlot(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        foreach (['s1', 's2'] as $session) {
            $this->entityManager->persist(Session::create($session, 'event-'.$session, new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        }
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice-1');
        $this->slot('s2', $alice->getId(), 'Alice', 'slot-alice-2');
        $this->slot('s1', $bob->getId(), 'Bob', 'slot-bob-1');
        // Carol plays Alice's slot of the second session with her.
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-alice-2', $carol->getId(), new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));

        $this->span('s1', 'Alice', '10:00:00', '12:30:00');   // 2 h 30
        $this->span('s1', 'Alice', '14:00:00', '14:45:00');   // 45 min
        $this->span('s2', 'Alice', '10:00:00', '10:20:00');   // 20 min
        $this->span('s1', 'Bob', '10:00:00', '18:00:00');     // someone else's
        $this->span('s1', 'Nobody', '10:00:00', '18:00:00');  // no such slot
        $this->entityManager->flush();

        $stats = $this->query()->forUser($alice->getId());
        self::assertSame(12900, $stats->totalSeconds);
        self::assertSame(9000, $stats->longestSpanSeconds);
        self::assertSame(11700, $stats->maxSessionSeconds);

        self::assertSame(1200, $this->query()->forUser($carol->getId())->totalSeconds, 'the slot she co-plays');

        $builder = self::getContainer()->get(MetricBagBuilder::class);
        self::assertInstanceOf(MetricBagBuilder::class, $builder);
        $bag = $builder->build($alice->getId());
        self::assertSame(3, $bag->get(AchievementMetricCatalog::FACT_PLAYED_HOURS));
        self::assertSame(150, $bag->get(AchievementMetricCatalog::FACT_LONGEST_PLAY_SPAN_MINUTES));
        self::assertSame(3, $bag->get(AchievementMetricCatalog::FACT_MAX_PLAYED_HOURS_IN_SESSION));
        self::assertTrue(AchievementMetricCatalog::isValidFact(AchievementMetricCatalog::FACT_PLAYED_HOURS));

        $nobody = $this->createUser('dan@example.org', slug: 'dan');
        self::assertSame(0, $this->query()->forUser($nobody->getId())->totalSeconds);
    }

    private function slot(string $session, string $userId, string $name, string $slotId): void
    {
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session, $userId, 'game-1', $name, 1, $slotId));
    }

    private function span(string $session, string $slot, string $from, string $to): void
    {
        $span = SlotPlaySpan::open(bin2hex(random_bytes(16)), $session, $slot, new \DateTimeImmutable('2026-10-01T'.$from.'+00:00'));
        $span->extendTo(new \DateTimeImmutable('2026-10-01T'.$to.'+00:00'));
        $this->entityManager->persist($span);
    }

    private function query(): PlayTimeStatsQueryInterface
    {
        $query = self::getContainer()->get(PlayTimeStatsQueryInterface::class);
        self::assertInstanceOf(PlayTimeStatsQueryInterface::class, $query);

        return $query;
    }
}
