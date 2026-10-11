<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Query\RecapSuperlativesQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

final class RecapSuperlativeAchievementTest extends FunctionalTestCase
{
    /** @var array<string, \App\Registrations\Domain\Entity\Registration> one registration per event+user (unique constraint) */
    private array $registrationCache = [];

    public function testCountsOnlyOwnSuperlativesInFinishedPublicSessions(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $bob = $this->createUser('bob@example.org', displayName: 'Bob', slug: 'bob');

        $event = $this->createEvent('ArchiLAN #5', $this->now(), $this->now()->modify('+1 day'));

        // Session 1: Alice most_generous, Bob first_to_goal.
        $this->seedRecappedSession($event->getId(), [
            'slot-a' => $alice->getId(),
            'slot-b' => $bob->getId(),
        ], [
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a', 'value' => 42],
            ['key' => 'first_to_goal', 'label' => 'Speedy Gonzales', 'slotId' => 'slot-b', 'value' => '2026-05-12T11:00:00+00:00'],
        ]);

        // Session 2, same event: Alice most_generous again.
        $this->seedRecappedSession($event->getId(), ['slot-a2' => $alice->getId(), 'slot-b2' => $bob->getId()], [
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a2', 'value' => 7],
        ]);

        // Private event: Alice's superlative there must not count.
        $privateEvent = $this->createEvent('Privé', $this->now(), $this->now()->modify('+1 day'), isPublic: false);
        $this->seedRecappedSession($privateEvent->getId(), ['slot-p' => $alice->getId(), 'slot-pb' => $bob->getId()], [
            ['key' => 'biggest_hub', 'label' => 'Le Facteur', 'slotId' => 'slot-p', 'value' => 3],
        ]);

        $query = self::getContainer()->get(RecapSuperlativesQueryInterface::class);
        self::assertInstanceOf(RecapSuperlativesQueryInterface::class, $query);

        self::assertSame(['most_generous' => 2], $query->superlativeCountsFor($alice->getId()));
        self::assertSame(['first_to_goal' => 1], $query->superlativeCountsFor($bob->getId()));
    }

    public function testRecomputeGrantsAnAchievementDefinedOverASuperlativeFact(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $bob = $this->createUser('bob@example.org', displayName: 'Bob', slug: 'bob');
        $event = $this->createEvent('ArchiLAN #5', $this->now(), $this->now()->modify('+1 day'));
        $this->seedRecappedSession($event->getId(), ['slot-a' => $alice->getId(), 'slot-b' => $bob->getId()], [
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a', 'value' => 42],
        ]);

        $rule = [
            'op' => AchievementRuleGroup::OP_ALL,
            'rules' => [[
                'fact' => AchievementMetricCatalog::SUPERLATIVE_PREFIX.'most_generous',
                'operator' => AchievementOperator::GreaterOrEqual->value,
                'value' => 1,
            ]],
        ];
        $this->entityManager->persist(
            AchievementDefinition::create('recap_godfather', 'Le Parrain', '', $rule, 100, $this->now()),
        );
        $this->entityManager->flush();

        $service = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $service);
        $service->recomputeForUser($alice->getId(), false);

        $grants = self::getContainer()->get(AchievementGrantRepositoryInterface::class);
        self::assertInstanceOf(AchievementGrantRepositoryInterface::class, $grants);
        self::assertContains('recap_godfather', $grants->grantedKeys($alice->getId()));
    }

    public function testASessionPlayedAloneContributesNothing(): void
    {
        // Story 32.23: a recap built before the two-player minimum still carries superlatives.
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $event = $this->createEvent('ArchiLAN #5', $this->now(), $this->now()->modify('+1 day'));
        $this->seedRecappedSession($event->getId(), ['slot-a' => $alice->getId()], [
            ['key' => 'first_to_goal', 'label' => 'Speedy Gonzales', 'slotId' => 'slot-a', 'value' => '2026-05-12T11:00:00+00:00'],
        ]);

        self::assertSame([], $this->query()->superlativeCountsFor($alice->getId()));
    }

    public function testAPersonalRunPlayedByOneMemberOnSeveralSlotsContributesNothing(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $this->seedRecappedRunSession(['slot-a' => $alice->getId(), 'slot-a2' => $alice->getId()], [], [
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a', 'value' => 12],
        ]);

        self::assertSame([], $this->query()->superlativeCountsFor($alice->getId()));
    }

    public function testAPersonalRunCreditsTheWinnerAndTheirCoPlayer(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $bob = $this->createUser('bob@example.org', displayName: 'Bob', slug: 'bob');
        $carol = $this->createUser('carol@example.org', displayName: 'Carol', slug: 'carol');
        $this->seedRecappedRunSession(['slot-a' => $alice->getId(), 'slot-b' => $bob->getId()], ['slot-a' => [$carol->getId()]], [
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a', 'value' => 12],
            ['key' => 'first_to_goal', 'label' => 'Speedy Gonzales', 'slotId' => 'slot-b', 'value' => '2026-05-12T11:00:00+00:00'],
        ]);

        self::assertSame(['most_generous' => 1], $this->query()->superlativeCountsFor($alice->getId()));
        self::assertSame(['most_generous' => 1], $this->query()->superlativeCountsFor($carol->getId()));
        self::assertSame(['first_to_goal' => 1], $this->query()->superlativeCountsFor($bob->getId()));
    }

    public function testACoPlayerIsTheSecondPlayerOfASingleSlotRun(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $carol = $this->createUser('carol@example.org', displayName: 'Carol', slug: 'carol');
        $this->seedRecappedRunSession(['slot-a' => $alice->getId()], ['slot-a' => [$carol->getId()]], [
            ['key' => 'first_to_goal', 'label' => 'Speedy Gonzales', 'slotId' => 'slot-a', 'value' => '2026-05-12T11:00:00+00:00'],
        ]);

        self::assertSame(['first_to_goal' => 1], $this->query()->superlativeCountsFor($alice->getId()));
    }

    public function testAMemberOnTwoSlotsCountsEachSuperlativeOnce(): void
    {
        // Alice owns slot-a and co-plays Bob's slot-b: she is two rows of the join, and each key
        // must still count once for the session.
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $bob = $this->createUser('bob@example.org', displayName: 'Bob', slug: 'bob');
        $this->seedRecappedRunSession(['slot-a' => $alice->getId(), 'slot-b' => $bob->getId()], ['slot-b' => [$alice->getId()]], [
            ['key' => 'first_to_goal', 'label' => 'Speedy Gonzales', 'slotId' => 'slot-a', 'value' => '2026-05-12T11:00:00+00:00'],
            ['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-b', 'value' => 3],
        ]);

        $counts = $this->query()->superlativeCountsFor($alice->getId());
        ksort($counts);
        self::assertSame(['first_to_goal' => 1, 'most_generous' => 1], $counts);
    }

    public function testUnfinishedSessionContributesNothing(): void
    {
        $alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $event = $this->createEvent('ArchiLAN #5', $this->now(), $this->now()->modify('+1 day'));

        // Projection exists but the session never finished (defensive - should not happen).
        $game = $this->createGame('Multi '.bin2hex(random_bytes(4)), 'multi-'.bin2hex(random_bytes(4)));
        $registration = $this->createRegistration($event->getId(), $alice->getId());
        $registration->confirm($this->now());
        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), $this->now());
        $session->transition(Session::STATUS_VALIDATING, $this->now());
        $session->transition(Session::STATUS_READY, $this->now());
        $this->entityManager->persist($session);
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $game->getId(), 'Alice', 0, 'slot-a');
        $this->entityManager->persist($slot);
        $this->entityManager->persist(new SessionRecap(
            $session->getId(),
            $this->now(),
            [],
            [],
            [],
            [['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-a', 'value' => 1]],
        ));
        $this->entityManager->flush();

        $query = self::getContainer()->get(RecapSuperlativesQueryInterface::class);
        self::assertInstanceOf(RecapSuperlativesQueryInterface::class, $query);
        self::assertSame([], $query->superlativeCountsFor($alice->getId()));
    }

    /**
     * Seeds a finished session on $eventId with one confirmed slot per [$slotId => $userId] and a
     * persisted recap projection carrying $superlatives.
     *
     * @param array<string, string>                                                      $slotUsers
     * @param list<array{key: string, label: string, slotId: string, value: int|string}> $superlatives
     */
    private function seedRecappedSession(string $eventId, array $slotUsers, array $superlatives): void
    {
        $now = $this->now();
        $game = $this->createGame('Multi '.bin2hex(random_bytes(4)), 'multi-'.bin2hex(random_bytes(4)));

        $session = $this->finishedSession($eventId);

        $order = 0;
        foreach ($slotUsers as $slotId => $userId) {
            $cacheKey = $eventId.':'.$userId;
            $registration = $this->registrationCache[$cacheKey] ?? null;
            if (null === $registration) {
                $registration = $this->createRegistration($eventId, $userId);
                $registration->confirm($now);
                $this->registrationCache[$cacheKey] = $registration;
            }
            $slot = SessionSlot::create(
                bin2hex(random_bytes(16)),
                $session->getId(),
                $registration->getId(),
                $game->getId(),
                'P'.$order,
                $order,
                $slotId,
            );
            $this->entityManager->persist($slot);
            ++$order;
        }

        $this->entityManager->persist(new SessionRecap($session->getId(), $now, [], [], [], $superlatives));
        $this->entityManager->flush();
    }

    /**
     * Seeds a finished personal-run session: a slot's owner key is the member id itself
     * (LaunchPersonalRunJobHandler), and co-players hang on the game slot id.
     *
     * @param array<string, string>                                                      $slotUsers    slotId => owner id
     * @param array<string, list<string>>                                                $coPlayers    slotId => co-player ids
     * @param list<array{key: string, label: string, slotId: string, value: int|string}> $superlatives
     */
    private function seedRecappedRunSession(array $slotUsers, array $coPlayers, array $superlatives): void
    {
        $now = $this->now();
        $game = $this->createGame('Multi '.bin2hex(random_bytes(4)), 'multi-'.bin2hex(random_bytes(4)));
        $run = Run::create(array_values($slotUsers)[0], 'Partie entre amis', $now);
        $this->entityManager->persist($run);

        $session = $this->finishedSession($run->getId());

        $order = 0;
        foreach ($slotUsers as $slotId => $userId) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $userId, $game->getId(), 'P'.$order, $order, $slotId));
            ++$order;
        }
        foreach ($coPlayers as $slotId => $userIds) {
            foreach ($userIds as $userId) {
                $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $slotId, $userId, $now));
            }
        }

        $this->entityManager->persist(new SessionRecap($session->getId(), $now, [], [], [], $superlatives));
        $this->entityManager->flush();
    }

    private function finishedSession(string $eventId): Session
    {
        $now = $this->now();
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $this->entityManager->persist($session);

        return $session;
    }

    private function query(): RecapSuperlativesQueryInterface
    {
        $query = self::getContainer()->get(RecapSuperlativesQueryInterface::class);
        self::assertInstanceOf(RecapSuperlativesQueryInterface::class, $query);

        return $query;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
    }
}
