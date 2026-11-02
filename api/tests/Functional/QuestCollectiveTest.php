<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekDraw;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.69: a collective quest counts what the whole community played, each check once, and pays each member
 * whose share reaches the threshold - outside the draw, the week's number of quests and the chest.
 */
final class QuestCollectiveTest extends FunctionalTestCase
{
    public function testASharedSlotCountsOnceAndEachMemberFromTheThresholdIsPaidOnce(): void
    {
        $this->regular();
        $this->collective(10, 3);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $dave = $this->createUser('dave@example.org', ['ROLE_USER'], 'Dave');
        $this->checks('s-alice', $alice, 6, $bob);
        $this->checks('s-carol', $carol, 4);
        $this->checks('s-dave', $dave, 1);
        $this->entityManager->flush();
        $week = $this->week();

        // 6 checks of Alice's slot played with Bob count once: 6 + 4 + 1 = 11, not 17.
        self::assertSame(4 + 4 + 3, $this->award()->awardWeek($week), 'the week\'s quest and its chest for the four, the collective quest for three');
        self::assertSame(0, $this->award()->awardWeek($week), 'once');

        self::assertSame(10 + 50 + 40, $this->paid($alice));
        self::assertSame(10 + 50 + 40, $this->paid($bob), 'a co-player\'s share is the slot\'s');
        self::assertSame(10 + 50 + 40, $this->paid($carol));
        self::assertSame(10 + 50, $this->paid($dave), 'one check is not a share: the chest still opens without the collective quest');
        self::assertNotNull($this->movement(sprintf('quest-group:%s:together:%s', $week->key, $carol->getId())));

        $this->loginAs($alice);
        $this->client->request('GET', '/api/v1/me/quests');
        self::assertResponseIsSuccessful();
        $mine = $this->decodedJsonResponse();
        self::assertCount(1, is_array($mine['quests'] ?? null) ? $mine['quests'] : [], 'the collective quest is not one of the week\'s quests');
        self::assertIsArray($mine['chest'] ?? null);
        self::assertSame(1, $mine['chest']['total'] ?? null);
        self::assertIsArray($mine['collective'] ?? null);
        self::assertSame(['target' => 10, 'total' => 11, 'mine' => 6, 'minContribution' => 3, 'done' => true, 'paid' => true], array_intersect_key($mine['collective'], array_flip(['total', 'mine', 'minContribution', 'target', 'done', 'paid'])));

        $this->client->request('GET', '/api/v1/community/collective-quest');
        self::assertResponseIsSuccessful();
        $gauge = $this->decodedJsonResponse()['quest'] ?? null;
        self::assertIsArray($gauge);
        self::assertSame(11, $gauge['total'] ?? null);
        self::assertTrue($gauge['reached'] ?? null);
        self::assertSame('10 checks', $gauge['objective'] ?? null);
    }

    public function testAMissedGoalPaysNobodyAndThePublicGaugeOnlyReadsTheLastCount(): void
    {
        $this->regular();
        $this->collective(100, 3);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->checks('s-alice', $alice, 6);
        $this->entityManager->flush();
        $week = $this->week();

        $this->client->request('GET', '/api/v1/community/collective-quest');
        self::assertResponseIsSuccessful();
        self::assertNull($this->decodedJsonResponse()['quest'] ?? null, 'nothing counted yet');
        self::assertSame([], $this->entityManager->getRepository(QuestWeekDraw::class)->findAll(), 'a public read never draws the week');
        $entry = $this->entityManager->getRepository(QuestWeekEntry::class)->findOneBy(['questId' => 'together']);
        self::assertInstanceOf(QuestWeekEntry::class, $entry);
        self::assertFalse($entry->isFrozen(), 'nor freezes it');

        $this->award()->awardWeek($week);
        self::assertNull($this->movement(sprintf('quest-group:%s:together:%s', $week->key, $alice->getId())), 'the goal is not reached');

        $this->client->request('GET', '/api/v1/community/collective-quest');
        self::assertResponseIsSuccessful();
        $gauge = $this->decodedJsonResponse()['quest'] ?? null;
        self::assertIsArray($gauge);
        self::assertSame(6, $gauge['total'] ?? null);
        self::assertFalse($gauge['reached'] ?? null);
        self::assertArrayNotHasKey('members', $gauge, 'no member named');
    }

    public function testABannedMembersPlayCountsButTheyAreNotPaid(): void
    {
        $this->collective(5, 1);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $bob->ban('Triche', new \DateTimeImmutable('-1 hour'));
        $this->checks('s-alice', $alice, 3);
        $this->checks('s-bob', $bob, 3);
        $this->entityManager->flush();
        $week = $this->week();

        self::assertSame(1, $this->award()->awardWeek($week), 'Alice only, with Bob\'s checks in the total');
        self::assertSame(40, $this->paid($alice));
        self::assertSame(0, $this->paid($bob));
    }

    public function testTheWeeksNoticeAndAdminTellTheCollectiveQuestApart(): void
    {
        $this->regular();
        $this->collective(10, 3);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->checks('s-alice', $alice, 1, null, QuestWeek::containing(new \DateTimeImmutable())->start->modify('-1 week')->modify('+1 day'));
        $this->entityManager->flush();

        $this->award()->announce($this->week());
        $notice = $this->entityManager->getRepository(Notification::class)->findOneBy(['recipientId' => $alice->getId(), 'type' => Notification::TYPE_QUESTS_RENEWED]);
        self::assertInstanceOf(Notification::class, $notice);
        self::assertSame(1, $notice->getPayload()['count'] ?? null);
        self::assertSame(10 + 50, $notice->getPayload()['maxPelles'] ?? null, 'the collective quest is not in the most a member earns');
        self::assertSame('Tous ensemble', $notice->getPayload()['collective'] ?? null);

        $this->award()->awardWeek($this->week());
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();
        $weeks = $this->decodedJsonResponse()['weeks'] ?? null;
        self::assertIsArray($weeks);
        self::assertIsArray($weeks[0] ?? null);
        $collective = array_values(array_filter(is_array($weeks[0]['quests'] ?? null) ? $weeks[0]['quests'] : [], static fn (mixed $quest): bool => is_array($quest) && true === ($quest['collective'] ?? null)));
        self::assertCount(1, $collective);
        self::assertSame(['groupTotal' => 0, 'groupTarget' => 10], array_intersect_key($collective[0], array_flip(['groupTotal', 'groupTarget'])));
    }

    public function testTheAdminWritesACollectiveQuestWithAShareAndPinsOneAWeek(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $base = ['title' => 'Ensemble', 'reward' => 40, 'objectives' => [['metric' => 'checks', 'target' => 500]], 'collective' => true, 'minContribution' => 10];

        foreach ([
            ['minContribution' => null],
            ['minContribution' => 0],
            ['minContribution' => 501],
            ['objectives' => [['metric' => 'weeklies', 'target' => 50]]],
            ['objectives' => [['metric' => 'checks', 'target' => 500], ['metric' => 'goals', 'target' => 5]]],
            ['tiers' => [['target' => 500, 'reward' => 40], ['target' => 900, 'reward' => 40]]],
        ] as $change) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([...$base, ...$change], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, (string) json_encode($change));
        }

        $ids = [];
        foreach (['Ensemble', 'Encore ensemble'] as $title) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([...$base, 'title' => $title, 'inDraw' => true], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $id = $this->decodedJsonResponse()['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        $this->entityManager->clear();
        $quest = $this->entityManager->find(QuestDefinition::class, $ids[0]);
        self::assertInstanceOf(QuestDefinition::class, $quest);
        self::assertTrue($quest->isCollective());
        self::assertFalse($quest->isDrawable(), 'never drawn');
        self::assertSame(10, $quest->getMinContribution());

        $next = $this->week()->next()->key;
        foreach ([[$ids[0], 204], [$ids[1], 409]] as [$id, $status]) {
            $this->client->request('POST', sprintf('/api/v1/admin/quest-weeks/%s/quests', $next), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['questId' => $id], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame($status);
        }
        $this->client->request('POST', sprintf('/api/v1/admin/quest-weeks/%s/quests', $next), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['questId' => $ids[1], 'replaces' => $ids[0]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204, 'in place of the one there');
    }

    public function testEveryMetricThatAddsUpCountsTheCommunityOnce(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->checks('s-alice', $alice, 3, $bob);
        $this->entityManager->flush();
        $slot = $this->entityManager->getRepository(SessionSlot::class)->findOneBy(['sessionId' => 's-alice']);
        self::assertInstanceOf(SessionSlot::class, $slot);
        $slot->recordGoal(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);
        $week = $this->week();
        self::assertSame(1, $query->groupTotal($week, new QuestObjective(QuestMetric::Goals, 1)), 'a goal of a slot played by two counts once');
        self::assertSame(3, $query->groupTotal($week, new QuestObjective(QuestMetric::Checks, 1)));
        self::assertSame(3, $query->groupTotal($week, new QuestObjective(QuestMetric::Checks, 1, QuestObjective::SCOPE_GAME, 'game-1')));
        self::assertSame(0, $query->groupTotal($week, new QuestObjective(QuestMetric::Checks, 1, QuestObjective::SCOPE_GAME, 'game-2')));
        self::assertSame(1, $query->groupTotal($week, new QuestObjective(QuestMetric::Sessions, 1)), 'a session whatever its players');
        foreach ([QuestMetric::PlayMinutes, QuestMetric::ItemsSent, QuestMetric::BountiesWon] as $metric) {
            self::assertSame(0, $query->groupTotal($week, new QuestObjective($metric, 1)), $metric->value);
        }
        self::assertSame(0, $query->groupTotal($week, new QuestObjective(QuestMetric::Checks, 1, QuestObjective::SCOPE_RECENT, '30')));
    }

    /** A quest of the draw, done with one check. */
    private function regular(): void
    {
        $this->entityManager->persist(QuestDefinition::write('Un check', '', 10, [new QuestObjective(QuestMetric::Checks, 1)], true, 1, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), 'one'));
    }

    /** The week's collective quest, pinned: 40 pelles. */
    private function collective(int $target, int $share): void
    {
        $this->entityManager->persist(QuestDefinition::write(
            'Tous ensemble', '', 40, [new QuestObjective(QuestMetric::Checks, $target)], false, 1, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), 'together',
            collective: true, minContribution: $share,
        ));
        $this->entityManager->persist(QuestWeekEntry::serve($this->week()->key, 'together', QuestWeekOrigin::Pinned, 0, new \DateTimeImmutable('-1 hour')));
    }

    private function week(): QuestWeek
    {
        return QuestWeek::containing(new \DateTimeImmutable());
    }

    private function checks(string $sessionId, User $user, int $count, ?User $coPlayer = null, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable('-1 minute');
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, $at));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), 'game-1', 'P-'.$sessionId, 1, 'slot-'.$sessionId));
        if (null !== $coPlayer) {
            $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-'.$sessionId, $coPlayer->getId(), $at));
        }
        for ($i = 0; $i < $count; ++$i) {
            $this->entityManager->persist(new SessionFeedEvent(
                bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', $at,
                1, 'Item', $i, 2, 'Location '.$i, 1, 'P-'.$sessionId, 'Game', 2, 'Someone', 'Game',
            ));
        }
    }

    private function award(): AwardWeeklyQuests
    {
        $award = self::getContainer()->get(AwardWeeklyQuests::class);
        self::assertInstanceOf(AwardWeeklyQuests::class, $award);

        return $award;
    }

    private function movement(string $key): ?PelleMovement
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findOneBy(['uniqueKey' => $key]);
    }

    private function paid(User $user): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $user->getId(), 'reason' => PelleReason::QuestReward]),
        ));
    }
}
