<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementGrant;
use App\Community\Domain\Entity\ActivityEntry;
use App\Community\Domain\Entity\Friendship;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * The /joueurs directory. Since story 30.38 the endpoint takes a sort, a search and a friends filter that
 * compose, instead of three exclusive modes plus a search that replaced them.
 */
final class CommunityDirectoryTest extends FunctionalTestCase
{
    public function testSortsByXpAndKeepsMembersWithoutAny(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        // Carol has no XP. She used to be dropped from the "top" tab; the directory lists every member,
        // so she sorts last instead of vanishing - otherwise the list contradicts the member count.
        $this->createUser('carol@example.org', slug: 'carol');

        // XP via achievement grants (100 each): alice 2, bob 1.
        foreach (['first_run', 'regular'] as $key) {
            $this->entityManager->persist(AchievementGrant::grant($alice->getId(), $key, new \DateTimeImmutable()));
        }
        $this->entityManager->persist(AchievementGrant::grant($bob->getId(), 'first_run', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertSame(3, $this->meta()['total']);

        self::assertSame(['alice', 'bob', 'carol'], array_map(
            static fn (mixed $row): mixed => is_array($row) ? $row['slug'] : null,
            $rows,
        ));
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame(200, $first['xp']);
        self::assertFalse($first['playing']);
    }

    public function testXpRowsCarryLevelProgress(): void
    {
        // The member cards draw a progress bar from these two fields (story 30.38).
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'first_run', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();
        $first = $this->data()[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('xpIntoLevel', $first);
        self::assertArrayHasKey('xpForNextLevel', $first);
        self::assertGreaterThan(0, $first['xpForNextLevel']);
    }

    public function testCountsWeeklyRunXp(): void
    {
        // A completed weekly run feeds XP exactly like the public profile: 1 goal (500) + 1 run (50) +
        // 10 checks (10) = 560. Regression guard: the directory previously ignored weekly runs entirely.
        $alice = $this->createUser('alice@example.org', slug: 'alice');

        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $entry = new WeeklyEntry(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            $alice->getId(),
            1,
            $now,
            $now,
            goalReachedAt: $now,
            completionTimeSeconds: 1200,
            checksTotal: 10,
            itemsTotal: 5,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();
        $first = $this->data()[0];
        self::assertIsArray($first);
        self::assertSame('alice', $first['slug']);
        self::assertSame(560, $first['xp']);
    }

    public function testSearchMatchesSlugOrName(): void
    {
        $this->createUser('alice@example.org', slug: 'alice');
        $this->createUser('bob@example.org', slug: 'bob');

        $this->client->jsonRequest('GET', '/api/v1/community/directory?search=ali');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertSame(1, $this->meta()['total']);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('alice', $first['slug']);
    }

    public function testRecentSortOrdersByLatestActivity(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $old = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $new = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $this->entityManager->persist(ActivityEntry::record($alice->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's1:zelda', $old, ['game' => 'Zelda']));
        $this->entityManager->persist(ActivityEntry::record($bob->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's2:metroid', $new, ['game' => 'Metroid']));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=recent');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('bob', $first['slug']); // most recent first
    }

    public function testFriendsFilterNeedsAViewerAndListsFriends(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $friendship = Friendship::request($alice->getId(), $bob->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        // Anonymous + friendsOnly is an empty set, never the whole directory.
        $this->client->jsonRequest('GET', '/api/v1/community/directory?friendsOnly=1');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->data());

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/directory?friendsOnly=1');
        $rows = $this->data();
        self::assertCount(1, $rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('bob', $first['slug']);
    }

    public function testSearchComposesWithTheFriendsFilter(): void
    {
        // The point of story 30.38's restructuring: a term narrows the friends set instead of replacing
        // it with strangers, which is what the old tab + search model did.
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->createUser('bobby@example.org', slug: 'bobby'); // matches "bob" but is not a friend

        $friendship = Friendship::request($alice->getId(), $bob->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/directory?friendsOnly=1&search=bob');

        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertCount(1, $rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('bob', $first['slug'], 'the non-friend "bobby" must not appear');
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function meta(): array
    {
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta;
    }
}
