<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementGrant;
use App\Community\Domain\ActivityEntry;
use App\Community\Domain\Friendship;
use App\WeeklyRuns\Domain\WeeklyEntry;

final class CommunityDirectoryTest extends FunctionalTestCase
{
    public function testTopRanksByXp(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->createUser('carol@example.org', slug: 'carol'); // no XP -> absent from "top"

        // XP via achievement grants (100 each): alice 2, bob 1.
        foreach (['first_run', 'regular'] as $key) {
            $this->entityManager->persist(AchievementGrant::grant($alice->getId(), $key, new \DateTimeImmutable()));
        }
        $this->entityManager->persist(AchievementGrant::grant($bob->getId(), 'first_run', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?mode=top');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertSame(2, $this->meta()['total']);

        $first = $rows[0];
        $second = $rows[1];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('alice', $first['slug']);
        self::assertSame(200, $first['xp']);
        self::assertFalse($first['playing']);
        self::assertSame('bob', $second['slug']);
        self::assertSame(100, $second['xp']);
    }

    public function testTopCountsWeeklyRunXp(): void
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

        $this->client->jsonRequest('GET', '/api/v1/community/directory?mode=top');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertSame(1, $this->meta()['total']);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('alice', $first['slug']);
        self::assertSame(560, $first['xp']);
    }

    public function testSearchBySlugOrName(): void
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

    public function testRecentlyActiveOrdersByLatestActivity(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $old = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $new = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $this->entityManager->persist(ActivityEntry::record($alice->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's1:zelda', $old, ['game' => 'Zelda']));
        $this->entityManager->persist(ActivityEntry::record($bob->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's2:metroid', $new, ['game' => 'Metroid']));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory?mode=recent');
        self::assertResponseIsSuccessful();
        $rows = $this->data();
        self::assertSame(2, $this->meta()['total']);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('bob', $first['slug']); // most recent first
    }

    public function testFriendsModeRequiresViewerAndListsFriends(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $friendship = Friendship::request($alice->getId(), $bob->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        // Anonymous: friends mode is empty.
        $this->client->jsonRequest('GET', '/api/v1/community/directory?mode=friends');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->data());

        // Alice sees Bob.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/directory?mode=friends');
        $rows = $this->data();
        self::assertCount(1, $rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('bob', $first['slug']);
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
