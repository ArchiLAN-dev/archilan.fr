<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\ItemsFromOthersQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Sessions\Application\Command\RecordSessionFeedEvent;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 30.49: the items received from another player - not from one's own slots, nor handed over by a release or
 * a collect.
 */
final class ItemsFromOthersTest extends FunctionalTestCase
{
    private const string SESSION = 'session-items';

    public function testOnlyItemsFoundByAnotherPlayerCount(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        $this->entityManager->persist(Session::create(self::SESSION, 'event-items', new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $aliceSlot = $this->slot($alice->getId(), 'Alice', 'slot-alice');
        $this->slot($alice->getId(), 'Alice2', 'slot-alice2');
        $bobSlot = $this->slot($bob->getId(), 'Bob', 'slot-bob');
        $this->slot($carol->getId(), 'Carol', 'slot-carol');
        // Released before story 30.49: marked, never dated.
        $this->slot($carol->getId(), 'Dan', 'slot-dan')->markAsReleased();
        $this->slot($bob->getId(), 'Frank', 'slot-frank');
        $this->slot($bob->getId(), 'Gina', 'slot-gina');
        $aliceSlot->recordGoal(new \DateTimeImmutable('2026-10-01T11:40:00+00:00'));
        // Alice plays Carol's slot too.
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-carol', $alice->getId(), new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $bobSlot->recordHandOver(false, new \DateTimeImmutable('2026-10-01T12:00:00+00:00'));

        $this->item('Bob', 'Alice', '2026-10-01T11:00:00+00:00');      // counts
        $this->item('Bob', 'Carol', '2026-10-01T11:01:00+00:00');      // counts: Alice plays Carol's slot
        $this->item('Alice', 'Alice', '2026-10-01T11:02:00+00:00');    // her own world
        $this->item('Alice2', 'Alice', '2026-10-01T11:03:00+00:00');   // another of her slots
        $this->item('Carol', 'Alice', '2026-10-01T11:04:00+00:00');    // a slot she co-plays
        $this->item('Bob', 'Alice', '2026-10-01T12:00:01+00:00');      // Bob released
        for ($i = 0; $i < 10; ++$i) {
            $this->item('Dan', 'Alice', '2026-10-01T11:30:00+00:00');  // a burst from a released slot: an undated release
            $this->item('Frank', 'Alice', '2026-10-01T11:20:00+00:00'); // ten real checks in a second: they count
            $this->item('Gina', 'Alice', '2026-10-01T11:45:00+00:00');  // a burst after her goal: an undated collect
        }
        $this->entityManager->flush();

        self::assertSame(12, $this->query()->count($alice->getId()));
        self::assertSame(1, $this->query()->count($carol->getId()), 'the item Bob sent to her slot');
    }

    public function testACollectIsDatedOnTheSlotAndLeavesOutWhatFollows(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->entityManager->persist(Session::create(self::SESSION, 'event-items', new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $this->slot($alice->getId(), 'Alice', 'slot-alice');
        $this->slot($bob->getId(), 'Bob', 'slot-bob');
        $this->item('Bob', 'Alice', '2026-10-01T11:00:00+00:00');
        $this->item('Bob', 'Alice', '2026-10-01T13:00:00+00:00');
        $this->entityManager->flush();

        $record = self::getContainer()->get(RecordSessionFeedEvent::class);
        self::assertInstanceOf(RecordSessionFeedEvent::class, $record);
        $record->record(self::SESSION, ['type' => 'collect', 'text' => 'Alice (Team #1) has collected their items', 'timestamp' => '2026-10-01T12:00:00+00:00', 'sender' => ['slot' => 1, 'name' => 'Alice', 'game' => 'Game']]);

        self::assertSame(1, $this->query()->count($alice->getId()));
        self::assertContains(AchievementMetricCatalog::FACT_ITEMS_FROM_OTHERS, array_keys(AchievementMetricCatalog::facts()));
    }

    private function slot(string $userId, string $name, string $slotId): SessionSlot
    {
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), self::SESSION, $userId, 'game-1', $name, 1, $slotId);
        $this->entityManager->persist($slot);

        return $slot;
    }

    private function item(string $sender, string $receiver, string $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            self::SESSION,
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $sender.' sent an item to '.$receiver,
            new \DateTimeImmutable($at),
            1,
            'Item',
            0,
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

    private function query(): ItemsFromOthersQueryInterface
    {
        $query = self::getContainer()->get(ItemsFromOthersQueryInterface::class);
        self::assertInstanceOf(ItemsFromOthersQueryInterface::class, $query);

        return $query;
    }
}
