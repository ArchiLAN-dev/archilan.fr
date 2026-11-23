<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 56.8: when each slot of a running event should finish at the pace of its last hour of play, which ones hold
 * the others' progression past the end of the event, and the real durations against the expected ones.
 */
final class EventForecastTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable()->setTime((int) date('H'), (int) date('i'));
    }

    public function testItForecastsEachSlotAndFlagsTheOnesToRelease(): void
    {
        [$eventId, $hollowKnight, $celeste] = $this->liveEvent();

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->client->request('GET', '/api/v1/admin/events/'.$eventId.'/forecast');

        self::assertResponseIsSuccessful();
        $live = $this->data()['live'] ?? null;
        self::assertIsArray($live);
        self::assertSame('s-live', $live['sessionId']);
        self::assertIsArray($live['slots']);
        $slots = array_column($live['slots'], null, 'slotName');
        // The one to act on comes first.
        self::assertIsArray($live['slots'][0] ?? null);
        self::assertSame('Alice', $live['slots'][0]['slotName'] ?? null);

        // 30 checks in her last hour of play: 200 left at 30 an hour, past the end, 7 of the others' items still hers.
        $alice = $slots['Alice'];
        self::assertIsArray($alice);
        self::assertSame('observed', $alice['paceSource']);
        self::assertEquals(30, $alice['checksPerHour']);
        self::assertSame(7, $alice['holdingProgression']);
        self::assertTrue($alice['toRelease']);
        self::assertIsString($alice['finishAt']);
        self::assertEqualsWithDelta($this->now->getTimestamp() + 200 * 120, new \DateTimeImmutable($alice['finishAt'])->getTimestamp(), 120);

        // Two checks are not a pace: the game's estimate stands in, and 10 checks left finish in time.
        $bob = $slots['Bob'];
        self::assertIsArray($bob);
        self::assertSame('estimated', $bob['paceSource']);
        self::assertEquals(60, $bob['checksPerHour']);
        self::assertFalse($bob['toRelease']);

        // No pace at all, and a goal reached: no time.
        $carol = $slots['Carol'];
        self::assertIsArray($carol);
        self::assertNull($carol['finishAt']);
        self::assertNull($carol['paceSource']);
        $dave = $slots['Dave'];
        self::assertIsArray($dave);
        self::assertTrue($dave['goalReached']);
        self::assertNull($dave['finishAt']);
        self::assertFalse($dave['toRelease']);

        // A finished slot of the event: expected 2 h, took 3.
        $calibration = $this->data()['calibration'] ?? null;
        self::assertIsArray($calibration);
        self::assertEquals([['slotName' => 'Erin', 'gameName' => 'Hollow Knight', 'estimatedHours' => 2, 'actualHours' => 3, 'gap' => 0.5]], $calibration['slots']);
        self::assertEquals(0.5, $calibration['meanGap']);

        // The game's sheet sums it up; Celeste has nothing to compare.
        $this->client->request('GET', '/api/v1/admin/games/'.$hollowKnight);
        self::assertResponseIsSuccessful();
        self::assertEquals(['slots' => 1, 'meanGap' => 0.5], $this->data()['durationCalibration']);
        $this->client->request('GET', '/api/v1/admin/games/'.$celeste);
        self::assertNull($this->data()['durationCalibration']);
    }

    public function testWithoutARunningSessionThereIsNoLiveForecast(): void
    {
        $event = $this->createEvent('ArchiLAN', $this->now->modify('-1 day'), $this->now->modify('-20 hours'));
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_ADMIN']));

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/forecast');
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['live']);
        self::assertEquals(['slots' => [], 'meanGap' => null], $this->data()['calibration']);

        $this->client->request('GET', '/api/v1/admin/events/unknown/forecast');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAdminsSeeIt(): void
    {
        $event = $this->createEvent('ArchiLAN', $this->now->modify('-1 day'), $this->now->modify('-20 hours'));
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/forecast');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{0: string, 1: string, 2: string} the event, Hollow Knight and Celeste */
    private function liveEvent(): array
    {
        $event = $this->createEvent('ArchiLAN', $this->now->modify('-2 hours'), $this->now->modify('+1 hour'));
        $hollowKnight = $this->game('Hollow Knight', 'hollow-knight', 60);
        $celeste = $this->game('Celeste', 'celeste', null);
        $start = $this->now->modify('-2 hours');
        $this->entityManager->persist(Session::createRunning('s-live', $event->getId(), 'localhost', 38281, null, null, $start));

        $lastCheck = $this->now->modify('-5 minutes');
        $alice = $this->slot('s-live', 'Alice', $hollowKnight, 0, 100, 300, 10);
        $alice->recordCheckActivity($lastCheck);
        for ($k = 0; $k < 30; ++$k) {
            // Three of them are progression items of Bob's.
            $this->feed('s-live', 'Alice', $k < 3 ? 'Bob' : 'Alice', $k < 3 ? 1 : 0, $lastCheck->modify('-'.(2 * $k).' minutes'));
        }
        // An older check, out of her last hour of play.
        $this->feed('s-live', 'Alice', 'Alice', 0, $lastCheck->modify('-90 minutes'));

        $bob = $this->slot('s-live', 'Bob', $hollowKnight, 1, 90, 100, 0);
        $bob->recordCheckActivity($lastCheck);
        $this->feed('s-live', 'Bob', 'Bob', 0, $lastCheck);
        $this->feed('s-live', 'Bob', 'Bob', 0, $lastCheck->modify('-1 minute'));

        $this->slot('s-live', 'Carol', $celeste, 2, 10, 500, 3);
        $dave = $this->slot('s-live', 'Dave', $hollowKnight, 3, 50, 50, 0);
        $dave->recordGoal($start->modify('+1 hour'));

        // An earlier session of the event, finished: Erin was expected at 2 h and took 3.
        $this->entityManager->persist(Session::createRunning('s-old', $event->getId(), 'localhost', 38282, null, null, $start->modify('-1 day')));
        $erin = $this->slot('s-old', 'Erin', $hollowKnight, 0, 120, 120, 0);
        $erin->recordEstimatedHours(2.0);
        $erin->recordGoal($start->modify('-1 day +3 hours'));
        $this->entityManager->flush();
        $old = $this->entityManager->find(Session::class, 's-old');
        self::assertInstanceOf(Session::class, $old);
        $old->transition(Session::STATUS_FINISHED, $start);
        $this->entityManager->flush();

        return [$event->getId(), $hollowKnight, $celeste];
    }

    private function game(string $name, string $slug, ?int $pace): string
    {
        $game = $this->createGame($name, $slug);
        self::assertInstanceOf(Game::class, $game);
        $game->estimatePace($pace);
        $this->entityManager->flush();

        return $game->getId();
    }

    private function slot(string $sessionId, string $name, string $gameId, int $order, int $checks, int $locations, int $hosted): SessionSlot
    {
        $slot = SessionSlot::create($sessionId.'-'.$name, $sessionId, 'reg-'.$name, $gameId, $name, $order);
        $slot->recordProgress($checks, 0);
        $slot->recordMeasures($locations, 10, $hosted);
        $this->entityManager->persist($slot);

        return $slot;
    }

    private function feed(string $sessionId, string $sender, string $receiver, int $flags, \DateTimeImmutable $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $sessionId,
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $sender.' found an item',
            $at,
            1,
            'Item',
            $flags,
            2,
            'Location',
            1,
            $sender,
            'Game',
            2,
            $receiver,
            'Game',
        ));
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
