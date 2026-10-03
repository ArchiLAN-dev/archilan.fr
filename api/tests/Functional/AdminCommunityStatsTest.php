<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\CommunityStatsQueryInterface;
use App\Community\Domain\Entity\AchievementGrant;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Shared\Application\Support\StatsPeriod;

/**
 * Story 42.1: the Community section of the admin statistics page.
 */
final class AdminCommunityStatsTest extends FunctionalTestCase
{
    private const string NOW = '2026-10-03T12:00:00+00:00';

    public function testTheSectionCountsWhatHappenedInEachWeek(): void
    {
        // 4 weeks: 09-07, 09-14, 09-21, 09-28 (in progress); the previous period starts on 08-10.
        $alice = $this->userCreatedAt('alice@example.org', '2026-09-08T10:00:00+00:00');
        $bob = $this->userCreatedAt('bob@example.org', '2026-09-29T10:00:00+00:00');
        $this->userCreatedAt('carol@example.org', '2026-08-20T10:00:00+00:00');
        $gone = $this->userCreatedAt('gone@example.org', '2026-09-30T10:00:00+00:00');
        $gone->anonymizeForDeletion(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));

        $this->entityManager->persist(Membership::create($alice->getId(), new \DateTimeImmutable('2026-09-15T10:00:00+00:00'), new \DateTimeImmutable('2027-09-15T10:00:00+00:00'), 'admin', null, null, new \DateTimeImmutable('2026-09-15T10:00:00+00:00')));

        $friendship = Friendship::request($alice->getId(), $bob->getId(), new \DateTimeImmutable('2026-09-20T10:00:00+00:00'));
        $friendship->accept(new \DateTimeImmutable('2026-09-22T10:00:00+00:00'));
        $this->entityManager->persist($friendship);
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'first_goal', new \DateTimeImmutable('2026-09-30T10:00:00+00:00')));

        // Alice plays her slot with Bob as co-player; each item she sends is a check of that slot.
        $session = Session::create('session-stats', 'event-stats', new \DateTimeImmutable('2026-08-01T10:00:00+00:00'));
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $alice->getId(), 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-alice', $bob->getId(), new \DateTimeImmutable('2026-08-01T10:00:00+00:00')));
        foreach (['2026-08-20T10:00:00+00:00', '2026-09-08T10:00:00+00:00', '2026-09-08T11:00:00+00:00', '2026-09-30T10:00:00+00:00'] as $at) {
            $this->entityManager->persist($this->checkBy('session-stats', 'Alice', $at));
        }
        // A check from a slot nobody on the site holds counts for nobody.
        $this->entityManager->persist($this->checkBy('session-stats', 'Stranger', '2026-09-30T10:00:00+00:00'));
        $this->entityManager->flush();

        $stats = $this->query()->stats(StatsPeriod::fromCode('4s', new \DateTimeImmutable(self::NOW)), new \DateTimeImmutable(self::NOW));

        self::assertSame(3, $stats['accounts'], 'alice, bob and carol; the erased account leaves');
        self::assertSame(1, $stats['members']);

        self::assertSame([1, 0, 0, 2], array_column($stats['accountsCreated']['series'], 'value'), 'the erased account still counts where it was created');
        self::assertSame(3, $stats['accountsCreated']['total']);
        self::assertSame(1, $stats['accountsCreated']['previous']);

        self::assertSame([0, 1, 0, 0], array_column($stats['membershipsStarted']['series'], 'value'));
        self::assertSame([0, 0, 1, 0], array_column($stats['friendshipsAccepted']['series'], 'value'));
        self::assertSame([0, 0, 0, 1], array_column($stats['achievementsUnlocked']['series'], 'value'));

        // Two checks in the same week are one active pair (Alice and her co-player), counted once.
        self::assertSame([2, 0, 0, 2], array_column($stats['activePlayers']['series'], 'value'));
        self::assertSame(2, $stats['activePlayers']['total'], 'distinct over the period, not a sum of weeks');
        self::assertSame(2, $stats['activePlayers']['previous']);
    }

    public function testTheEndpointIsAdminOnlyAndFallsBackToTwelveWeeks(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/admin/stats/community');
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->request('GET', '/api/v1/admin/stats/community?period=2ans');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        $period = $body['period'] ?? null;
        self::assertIsArray($period);
        self::assertSame('12s', $period['code'] ?? null);
        $created = $body['accountsCreated'] ?? null;
        self::assertIsArray($created);
        $series = $created['series'] ?? null;
        self::assertIsArray($series);
        self::assertCount(12, $series);
    }

    private function userCreatedAt(string $email, string $createdAt): User
    {
        $user = $this->createUser($email, ['ROLE_USER'], $email);
        $this->entityManager->getConnection()->executeStatement('UPDATE "user" SET created_at = :at WHERE id = :id', ['at' => $createdAt, 'id' => $user->getId()]);

        return $user;
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

    private function query(): CommunityStatsQueryInterface
    {
        $query = self::getContainer()->get(CommunityStatsQueryInterface::class);
        self::assertInstanceOf(CommunityStatsQueryInterface::class, $query);

        return $query;
    }
}
