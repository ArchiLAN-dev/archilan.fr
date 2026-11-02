<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\QuestAchievementsQueryInterface;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestTier;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.68: a quest with tiers pays each tier reached, once, and counts as done from its first tier.
 */
final class QuestTiersTest extends FunctionalTestCase
{
    public function testEachTierReachedIsPaidOnceEvenSeveralAtATime(): void
    {
        $this->tiered();
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $this->checks('s-alice', $alice, 5);
        $this->checks('s-bob', $bob, 2);
        $this->checks('s-carol', $carol, 1);
        $this->entityManager->flush();
        $week = $this->week();

        self::assertSame(3 + 2, $this->award()->awardWeek($week), 'Alice: tiers 1 and 2 at once, and the chest; Bob: tier 1 and the chest');
        self::assertSame(0, $this->award()->awardWeek($week), 'each tier once');

        self::assertSame(10 + 20 + 50, $this->paid($alice));
        self::assertSame(10 + 50, $this->paid($bob), 'the chest from the first tier');
        self::assertSame(0, $this->paid($carol));
        self::assertNotNull($this->movement(sprintf('quest:%s:checks:%s', $week->key, $alice->getId())), 'the first tier keeps the quest key');
        self::assertNotNull($this->movement(sprintf('quest-tier:%s:checks:%s:2', $week->key, $alice->getId())));
        self::assertNull($this->movement(sprintf('quest-tier:%s:checks:%s:3', $week->key, $alice->getId())));

        $achievements = self::getContainer()->get(QuestAchievementsQueryInterface::class);
        self::assertInstanceOf(QuestAchievementsQueryInterface::class, $achievements);
        self::assertSame(1, $achievements->questsCompleted($alice->getId()), 'a quest done once, whatever its tiers');

        $this->loginAs($alice);
        $this->client->request('GET', '/api/v1/me/quests');
        self::assertResponseIsSuccessful();
        $quests = $this->decodedJsonResponse()['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame([
            ['target' => 2, 'reward' => 10, 'reached' => true, 'paid' => true],
            ['target' => 4, 'reward' => 20, 'reached' => true, 'paid' => true],
            ['target' => 6, 'reward' => 30, 'reached' => false, 'paid' => false],
        ], $quests[0]['tiers'] ?? null);
    }

    public function testTheRenewalNoticeCountsEveryTier(): void
    {
        $this->tiered();
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->checks('s-alice', $alice, 1, QuestWeek::containing(new \DateTimeImmutable())->start->modify('-1 week')->modify('+1 day'));
        $this->entityManager->flush();

        $this->award()->announce(QuestWeek::containing(new \DateTimeImmutable()));

        $notice = $this->entityManager->getRepository(Notification::class)->findOneBy(['recipientId' => $alice->getId(), 'type' => Notification::TYPE_QUESTS_RENEWED]);
        self::assertInstanceOf(Notification::class, $notice);
        self::assertSame(60 + 50, $notice->getPayload()['maxPelles'] ?? null, 'every tier and the chest');
    }

    public function testTheAdminSeesTheMembersOfEachTierTheLastTimeTheQuestWasServed(): void
    {
        $this->tiered();
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $last = QuestWeek::containing(new \DateTimeImmutable())->previous();
        $this->checks('s-alice', $alice, 6, $last->start->modify('+1 day'));
        $this->checks('s-bob', $bob, 3, $last->start->modify('+1 day'));
        $this->entityManager->flush();
        $this->award()->awardWeek($last);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();
        $quests = $this->decodedJsonResponse()['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame([['target' => 2, 'reward' => 10], ['target' => 4, 'reward' => 20], ['target' => 6, 'reward' => 30]], $quests[0]['tiers'] ?? null);
        self::assertIsArray($quests[0]['stats'] ?? null);
        self::assertSame([2, 1, 1], $quests[0]['stats']['lastTiers'] ?? null);
        self::assertSame(10 + 10 + 20 + 30, $quests[0]['stats']['pelles'] ?? null, 'every tier with its quest');
    }

    public function testTheAdminWritesTiersThatClimbWithinTheMostAQuestPays(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $base = ['title' => 'Paliers', 'reward' => 10, 'objectives' => [['metric' => 'checks', 'target' => 2]]];

        foreach ([
            [['target' => 2, 'reward' => 10]],
            [['target' => 2, 'reward' => 10], ['target' => 2, 'reward' => 20]],
            [['target' => 3, 'reward' => 10], ['target' => 4, 'reward' => 20]],
            [['target' => 2, 'reward' => 10], ['target' => 4, 'reward' => 995]],
        ] as $tiers) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([...$base, 'tiers' => $tiers], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, (string) json_encode($tiers));
        }
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            ...$base, 'objectives' => [['metric' => 'checks', 'target' => 2], ['metric' => 'goals', 'target' => 1]], 'tiers' => [['target' => 2, 'reward' => 10], ['target' => 4, 'reward' => 20]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'one objective only');

        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([...$base, 'tiers' => [['target' => 2, 'reward' => 10], ['target' => 4, 'reward' => 20]]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    private function tiered(): void
    {
        $this->entityManager->persist(QuestDefinition::write(
            'Toujours plus', '', 10, [new QuestObjective(QuestMetric::Checks, 2)], true, 1, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), 'checks',
            [new QuestTier(2, 10), new QuestTier(4, 20), new QuestTier(6, 30)],
        ));
    }

    private function week(): QuestWeek
    {
        return QuestWeek::containing(new \DateTimeImmutable());
    }

    private function checks(string $sessionId, User $user, int $count, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable('-1 minute');
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, $at));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), 'game-1', 'P-'.$sessionId, 1, 'slot-'.$sessionId));
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
