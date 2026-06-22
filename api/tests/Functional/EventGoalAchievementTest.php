<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\EventParticipationQueryInterface;
use App\Community\Application\RecomputeAchievements;
use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

final class EventGoalAchievementTest extends FunctionalTestCase
{
    public function testEventGoalIsDetectedAndGrantsEventFinisher(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $event = $this->createEvent('ArchiLAN #3', $this->now(), $this->now()->modify('+1 day'));

        $this->reachGoalInEvent($user->getId(), $event->getId());

        // The cross-context query detects the event.
        $query = self::getContainer()->get(EventParticipationQueryInterface::class);
        self::assertInstanceOf(EventParticipationQueryInterface::class, $query);
        self::assertSame([$event->getId()], $query->eventIdsWithGoal($user->getId()));

        // Recompute grants the seeded event_finisher.
        $this->recompute($user->getId());
        self::assertContains('event_finisher', $this->grantedKeys($user->getId()));
    }

    public function testScopedEventFactMatchesOnlyTheRightEvent(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $eventA = $this->createEvent('ArchiLAN #3', $this->now(), $this->now()->modify('+1 day'));
        $eventB = $this->createEvent('ArchiLAN #4', $this->now(), $this->now()->modify('+1 day'));

        $this->reachGoalInEvent($user->getId(), $eventA->getId());

        $this->persistScopedAchievement('won_archilan3', $eventA->getId());
        $this->persistScopedAchievement('won_archilan4', $eventB->getId());

        $this->recompute($user->getId());
        $granted = $this->grantedKeys($user->getId());

        self::assertContains('won_archilan3', $granted); // goal reached in A
        self::assertNotContains('won_archilan4', $granted); // never played B
    }

    public function testUnsubmittedRegistrationDoesNotCount(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $event = $this->createEvent('ArchiLAN #3', $this->now(), $this->now()->modify('+1 day'));

        // Reach a goal but never confirm (submit) the registration.
        $this->reachGoalInEvent($user->getId(), $event->getId(), confirmed: false);

        $query = self::getContainer()->get(EventParticipationQueryInterface::class);
        self::assertInstanceOf(EventParticipationQueryInterface::class, $query);
        self::assertSame([], $query->eventIdsWithGoal($user->getId()));
    }

    private function reachGoalInEvent(string $userId, string $eventId, bool $confirmed = true): void
    {
        $game = $this->createGame('Super Metroid '.bin2hex(random_bytes(4)), 'sm-'.bin2hex(random_bytes(4)));
        $registration = $this->createRegistration($eventId, $userId);
        if ($confirmed) {
            $registration->confirm($this->now());
        }

        $session = $this->makeFinishedSession($eventId);
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $game->getId(), 'A', 0);
        $slot->setGoalReachedAt($this->now()->modify('+1 hour'));
        $slot->setChecksDone(50);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();
    }

    private function makeFinishedSession(string $eventId): Session
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
        $this->entityManager->flush();

        return $session;
    }

    private function persistScopedAchievement(string $key, string $eventId): void
    {
        $rule = [
            'op' => AchievementRuleGroup::OP_ALL,
            'rules' => [[
                'fact' => AchievementMetricCatalog::EVENT_GOAL_PREFIX.$eventId,
                'operator' => AchievementOperator::GreaterOrEqual->value,
                'value' => 1,
            ]],
        ];
        $this->entityManager->persist(
            AchievementDefinition::create($key, ucfirst($key), '', $rule, 100, $this->now()),
        );
        $this->entityManager->flush();
    }

    private function recompute(string $userId): void
    {
        $service = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $service);
        $service->recomputeForUser($userId, false);
    }

    /**
     * @return list<string>
     */
    private function grantedKeys(string $userId): array
    {
        $repo = self::getContainer()->get(AchievementGrantRepositoryInterface::class);
        self::assertInstanceOf(AchievementGrantRepositoryInterface::class, $repo);

        return $repo->grantedKeys($userId);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
    }
}
