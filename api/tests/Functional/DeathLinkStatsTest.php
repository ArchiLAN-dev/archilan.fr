<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\DeathLinkStatsQueryInterface;
use App\Community\Application\Support\MetricBagBuilder;
use App\Community\Domain\AchievementMetricCatalog;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 32.25: the deaths a member sent over DeathLink, the players they took with them, and their worst session.
 */
final class DeathLinkStatsTest extends FunctionalTestCase
{
    public function testDeathsCountForThePlayersOfTheSlotThatDied(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        foreach (['s1', 's2'] as $session) {
            $this->entityManager->persist(Session::create($session, 'event-'.$session, new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        }
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice-1');
        $this->slot('s1', $bob->getId(), 'Bob', 'slot-bob-1');
        $this->slot('s2', $alice->getId(), 'Alice', 'slot-alice-2');
        // Carol plays Alice's slot of the second session with her.
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-alice-2', $carol->getId(), new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));

        $this->death('s1', 'Alice', ['Bob', 'Dan']);
        $this->death('s1', 'Alice', ['Bob']);
        $this->death('s1', 'Alice', null);
        $this->death('s1', 'Bob', ['Alice']);
        $this->death('s2', 'Alice', ['Eve']);
        // A source naming no slot (the admin's manual DeathLink): nobody's.
        $this->death('s1', 'ArchiLAN', ['Alice', 'Bob']);
        // Not a death.
        $this->entityManager->persist(new SessionFeedEvent(bin2hex(random_bytes(16)), 's1', SessionFeedEvent::TYPE_GOAL, 'Alice has completed their goal', new \DateTimeImmutable('2026-10-01T12:00:00+00:00'), null, null, null, null, null, 1, 'Alice', 'Game', null, null, null));
        $this->entityManager->flush();

        $aliceStats = $this->query()->forUser($alice->getId());
        self::assertSame(4, $aliceStats->deaths);
        self::assertSame(4, $aliceStats->victims);
        self::assertSame(3, $aliceStats->maxDeathsInSession);

        $carolStats = $this->query()->forUser($carol->getId());
        self::assertSame(1, $carolStats->deaths, 'the death of the slot she co-plays');
        self::assertSame(1, $carolStats->victims);
        self::assertSame(1, $carolStats->maxDeathsInSession);

        self::assertSame(1, $this->query()->forUser($bob->getId())->deaths);

        $nobody = $this->createUser('dan@example.org', slug: 'dan');
        self::assertSame(0, $this->query()->forUser($nobody->getId())->deaths);
    }

    public function testTheFactsReachTheAchievementRules(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(Session::create('s1', 'event-s1', new \DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice-1');
        $this->death('s1', 'Alice', ['Bob', 'Carol']);
        $this->entityManager->flush();

        $builder = self::getContainer()->get(MetricBagBuilder::class);
        self::assertInstanceOf(MetricBagBuilder::class, $builder);
        $bag = $builder->build($alice->getId());

        self::assertSame(1, $bag->get(AchievementMetricCatalog::FACT_DEATH_LINK_DEATHS));
        self::assertSame(2, $bag->get(AchievementMetricCatalog::FACT_DEATH_LINK_VICTIMS));
        self::assertSame(1, $bag->get(AchievementMetricCatalog::FACT_MAX_DEATH_LINK_DEATHS_IN_SESSION));
        self::assertTrue(AchievementMetricCatalog::isValidFact(AchievementMetricCatalog::FACT_DEATH_LINK_VICTIMS));
    }

    private function slot(string $session, string $userId, string $name, string $slotId): void
    {
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session, $userId, 'game-1', $name, 1, $slotId));
    }

    /**
     * @param list<string>|null $victims
     */
    private function death(string $session, string $who, ?array $victims): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $session,
            SessionFeedEvent::TYPE_DEATH,
            'Spikes',
            new \DateTimeImmutable('2026-10-01T11:00:00+00:00'),
            null,
            null,
            null,
            null,
            null,
            null,
            $who,
            null,
            null,
            null,
            null,
            $victims,
        ));
    }

    private function query(): DeathLinkStatsQueryInterface
    {
        $query = self::getContainer()->get(DeathLinkStatsQueryInterface::class);
        self::assertInstanceOf(DeathLinkStatsQueryInterface::class, $query);

        return $query;
    }
}
