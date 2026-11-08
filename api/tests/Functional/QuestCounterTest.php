<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementCounter;
use App\Community\Domain\Enum\AchievementCounterKind;
use App\Community\Domain\Enum\AchievementCounterMode;
use App\GameSelection\Domain\Entity\Game;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 41.72: a quest objective on a counter of checks or items (story 30.57) - the counter's rules, over the
 * week only, for each member and for the community, and a counter in use stays.
 */
final class QuestCounterTest extends FunctionalTestCase
{
    private const string OOT = 'Ocarina of Time';
    private const string MORPHA = 'Water Temple Morpha Heart';
    private const string SUN = 'LH Sun';

    private Game $oot;
    private QuestWeek $week;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $this->oot = Game::create('Ocarina of Time', 'ocarina-of-time', 'Description.', null, 'Alt', 'Publisher', Game::AVAILABILITY_AVAILABLE, $now);
        $this->oot->configureApworld('apworlds/oot.apworld', str_repeat('a', 64), self::OOT, 'game: '.self::OOT, $now);
        $this->oot->recordLocationNames([self::MORPHA, self::SUN, 'Deku Tree Queen Gohma Heart']);
        $this->entityManager->persist($this->oot);
        $this->entityManager->flush();
        // Monday 2 November 2026 00:00 to Monday 9 November 00:00, Paris time.
        $this->week = QuestWeek::containing(new \DateTimeImmutable('2026-11-04T12:00:00+00:00'));
    }

    public function testTheWeekCountsByTheCounterModesWithoutTheReleaseNorOtherWeeks(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->session('s1');
        $aliceSlot = $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice');
        $aliceSlot->recordHandOver(false, new \DateTimeImmutable('2026-11-04T13:00:00+00:00'));
        $this->line('s1', 'Alice', self::MORPHA, '2026-11-04T11:00:00+00:00');
        $this->line('s1', 'Alice', self::SUN, '2026-11-04T11:30:00+00:00');
        $this->line('s1', 'Alice', 'Deku Tree Queen Gohma Heart', '2026-11-04T13:30:00+00:00'); // her release
        $this->session('s2');
        $this->slot('s2', $alice->getId(), 'Alice', 'slot-alice-2');
        $this->line('s2', 'Alice', self::MORPHA, '2026-11-05T11:00:00+00:00');
        $this->session('s0');
        $this->slot('s0', $alice->getId(), 'Alice', 'slot-alice-0');
        $this->line('s0', 'Alice', self::MORPHA, '2026-11-01T22:30:00+00:00');      // Sunday night, the week before
        $this->line('s0', 'Alice', self::SUN, '2026-11-09T10:00:00+00:00');         // the week after
        $this->entityManager->flush();

        $targets = [self::MORPHA, self::SUN, 'Deku Tree Queen Gohma Heart'];
        $total = $this->objective($this->counter($targets, AchievementCounterMode::Total), 5);
        $most = $this->objective($this->counter($targets, AchievementCounterMode::MaxSession), 2);
        $distinct = $this->objective($this->counter($targets, AchievementCounterMode::Distinct), 2);
        $counts = $this->query()->counts($this->week, [$total, $most, $distinct]);

        self::assertSame([$alice->getId() => 3], $counts[$total->key()]);
        self::assertSame([$alice->getId() => 2], $counts[$most->key()]);
        self::assertSame([$alice->getId() => 2], $counts[$distinct->key()]);
        self::assertSame([$alice->getId() => 3], $this->query()->counts($this->week, [$total], $alice->getId())[$total->key()]);
    }

    public function testTheCommunityCountsASlotPlayedTogetherOnce(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->session('s1');
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice');
        $this->slot('s1', $bob->getId(), 'Bob', 'slot-bob');
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-bob', $alice->getId(), new \DateTimeImmutable('2026-11-01T10:00:00+00:00')));
        $this->line('s1', 'Bob', self::MORPHA, '2026-11-04T11:00:00+00:00');   // Bob's slot, played by both
        $this->line('s1', 'Alice', self::MORPHA, '2026-11-04T11:10:00+00:00');
        $this->entityManager->flush();

        $objective = $this->objective($this->counter([self::MORPHA], AchievementCounterMode::Total), 3);

        $counts = $this->query()->counts($this->week, [$objective])[$objective->key()];
        self::assertSame(2, $counts[$alice->getId()] ?? null, 'her slot and the one she co-plays');
        self::assertSame(1, $counts[$bob->getId()] ?? null);
        self::assertSame(2, $this->query()->groupTotal($this->week, $objective), 'Bob\'s slot once, Alice\'s once');
    }

    public function testAWeeklyAttemptCountsButNotWithOthersOnly(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)),
            'weekly-1',
            $alice->getId(),
            1,
            new \DateTimeImmutable('2026-11-04T10:00:00+00:00'),
            new \DateTimeImmutable('2026-11-04T10:00:00+00:00'),
            externalSessionId: 'weekly-session',
            launchedAt: new \DateTimeImmutable('2026-11-04T10:00:00+00:00'),
        ));
        $this->line('weekly-session', 'Alice', self::MORPHA, '2026-11-04T11:00:00+00:00');
        $this->entityManager->flush();

        $mine = $this->objective($this->counter([self::MORPHA], AchievementCounterMode::Total), 1);
        $withOthers = $this->objective($this->counter([self::MORPHA], AchievementCounterMode::Total, true), 1);
        $counts = $this->query()->counts($this->week, [$mine, $withOthers]);

        self::assertSame([$alice->getId() => 1], $counts[$mine->key()]);
        self::assertSame([], $counts[$withOthers->key()]);
    }

    public function testTheAdminAimsAQuestAtACounterThatThenCannotBeDeleted(): void
    {
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin'));
        $total = $this->counter([self::MORPHA], AchievementCounterMode::Total);
        $distinct = $this->counter([self::MORPHA, self::SUN], AchievementCounterMode::Distinct);

        $this->writeQuest(['title' => 'Sans compteur', 'reward' => 10, 'objectives' => [['metric' => 'counter', 'target' => 1]]]);
        self::assertResponseStatusCodeSame(422);
        $this->writeQuest(['title' => 'Compteur fantôme', 'reward' => 10, 'objectives' => [['metric' => 'counter', 'target' => 1, 'scope' => 'counter', 'scopeId' => 'missing']]]);
        self::assertResponseStatusCodeSame(422);
        $this->writeQuest(['title' => 'Morpha sur un jeu', 'reward' => 10, 'objectives' => [['metric' => 'counter', 'target' => 1, 'scope' => 'game', 'scopeId' => $this->oot->getId()]]]);
        self::assertResponseStatusCodeSame(422);
        $this->writeQuest(['title' => 'Tous ensemble', 'reward' => 10, 'collective' => true, 'minContribution' => 1, 'objectives' => [['metric' => 'counter', 'target' => 2, 'scope' => 'counter', 'scopeId' => $distinct->getId()]]]);
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('quest_counter_not_collective', $error['code'] ?? null);

        $this->writeQuest(['title' => 'Battez Morpha', 'reward' => 10, 'objectives' => [['metric' => 'counter', 'target' => 1, 'scope' => 'counter', 'scopeId' => $distinct->getId()]]]);
        self::assertResponseStatusCodeSame(201);
        $this->writeQuest(['title' => 'Morpha en masse', 'reward' => 10, 'collective' => true, 'minContribution' => 1, 'objectives' => [['metric' => 'counter', 'target' => 20, 'scope' => 'counter', 'scopeId' => $total->getId()]]]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();
        $scopes = $this->decodedJsonResponse()['scopes'] ?? null;
        self::assertIsArray($scopes);
        self::assertIsArray($scopes['counters'] ?? null);
        self::assertContains(['id' => $total->getId(), 'label' => 'Compteur', 'gameName' => 'Ocarina of Time', 'mode' => 'total'], $scopes['counters']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievement-counters/'.$total->getId());
        self::assertResponseStatusCodeSame(409);
        // A collective quest adds the counter up as a total: it keeps that mode, while the other changes go.
        $patch = ['label' => 'Morpha', 'gameId' => $this->oot->getId(), 'kind' => 'location', 'targets' => [self::MORPHA]];
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievement-counters/'.$total->getId(), $patch + ['mode' => 'distinct']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievement-counters/'.$total->getId(), $patch + ['mode' => 'total']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievement-counters/'.$distinct->getId(), $patch + ['mode' => 'max_session']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievement-counters');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $usage = array_column(array_filter($data, is_array(...)), 'usedByQuests', 'id');
        self::assertSame(1, $usage[$total->getId()] ?? null);
        self::assertSame(1, $usage[$distinct->getId()] ?? null);
    }

    public function testAWeekStillServingACounterKeepsItButARetiredQuestLetsItGo(): void
    {
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin'));
        $served = $this->counter([self::MORPHA], AchievementCounterMode::Total);
        $old = $this->counter([self::SUN], AchievementCounterMode::Total);
        $now = new \DateTimeImmutable();
        // Served this week on the served counter, then edited away from it: the week keeps what it froze.
        $quest = QuestDefinition::write('Battez Morpha', '', 10, [$this->objective($served, 1)], true, 1, $now, 'morpha');
        $entry = QuestWeekEntry::serve(QuestWeek::containing($now)->key, 'morpha', QuestWeekOrigin::Drawn, 0, $now);
        $entry->freeze([$this->objective($served, 1)], 10, null);
        $quest->edit('Battez Morpha', '', 10, [new QuestObjective(QuestMetric::Goals, 1)], true, 1);
        // On the old counter, served weeks ago and retired since.
        $retired = QuestDefinition::write('Soleil', '', 10, [$this->objective($old, 1)], true, 1, $now, 'sun');
        $retired->retire($now);
        $this->entityManager->persist($quest);
        $this->entityManager->persist($entry);
        $this->entityManager->persist($retired);
        $this->entityManager->persist(QuestWeekEntry::serve('2026-W30', 'sun', QuestWeekOrigin::Drawn, 0, $now));
        $this->entityManager->flush();

        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievement-counters/'.$served->getId());
        self::assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievement-counters/'.$old->getId());
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param list<string> $targets
     */
    private function counter(array $targets, AchievementCounterMode $mode, bool $withOthersOnly = false): AchievementCounter
    {
        $counter = AchievementCounter::create(bin2hex(random_bytes(16)), 'Compteur', $this->oot->getId(), AchievementCounterKind::Location, $targets, $mode, $withOthersOnly, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $this->entityManager->persist($counter);
        $this->entityManager->flush();

        return $counter;
    }

    private function objective(AchievementCounter $counter, int $target): QuestObjective
    {
        return new QuestObjective(QuestMetric::Counter, $target, QuestObjective::SCOPE_COUNTER, $counter->getId());
    }

    private function session(string $id): void
    {
        $this->entityManager->persist(Session::create($id, 'event-'.$id, new \DateTimeImmutable('2026-11-01T10:00:00+00:00')));
    }

    private function slot(string $session, string $userId, string $name, string $slotId): SessionSlot
    {
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session, $userId, 'game-1', $name, 1, $slotId);
        $this->entityManager->persist($slot);

        return $slot;
    }

    /** A check of one location by the slot, the item sent to itself. */
    private function line(string $session, string $slot, string $location, string $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $session,
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $slot.' found Bow',
            new \DateTimeImmutable($at),
            1,
            'Bow',
            0,
            2,
            $location,
            1,
            $slot,
            self::OOT,
            1,
            $slot,
            self::OOT,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeQuest(array $payload): void
    {
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function query(): WeeklyQuestsQueryInterface
    {
        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);

        return $query;
    }
}
