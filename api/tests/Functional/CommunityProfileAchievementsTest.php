<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementGrant;

final class CommunityProfileAchievementsTest extends FunctionalTestCase
{
    public function testProfileShowsOnlyRecentUnlockedPlusCounts(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $alice = $this->createUser('alice@example.org', slug: 'alice');

        // 8 unlocked with increasing dates → the profile keeps only the 6 most recent.
        $keys = ['first_run', 'regular', 'veteran', 'first_goal', 'goal_hunter', 'explorer', 'collector', 'polyglot'];
        foreach ($keys as $i => $key) {
            $this->entityManager->persist(
                AchievementGrant::grant($alice->getId(), $key, new \DateTimeImmutable(sprintf('2026-05-%02dT10:00:00+00:00', $i + 1))),
            );
        }
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        $data = $this->data();

        $achievements = $data['achievements'] ?? null;
        self::assertIsArray($achievements);
        self::assertCount(6, $achievements);
        $first = $achievements[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('polyglot', $first['key']); // most recent (2026-05-08)
        foreach ($achievements as $a) {
            self::assertIsArray($a);
            self::assertTrue($a['unlocked']);
        }

        $stats = $data['achievementStats'] ?? null;
        self::assertIsArray($stats);
        self::assertSame(8, $stats['unlocked']);
        self::assertSame(9, $stats['total']);
    }

    public function testCatalogueReturnsFullListWithStateAndRarity(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        // first_run held by both members; goal_hunter by alice only.
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'first_run', $now));
        $this->entityManager->persist(AchievementGrant::grant($bob->getId(), 'first_run', $now));
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'goal_hunter', $now));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/achievements');
        self::assertResponseIsSuccessful();
        $data = $this->data();

        self::assertSame('alice', $data['slug']);
        $achievements = $data['achievements'] ?? null;
        self::assertIsArray($achievements);
        self::assertCount(9, $achievements); // the whole catalogue

        $byKey = [];
        foreach ($achievements as $a) {
            self::assertIsArray($a);
            $key = $a['key'] ?? null;
            self::assertIsString($key);
            $byKey[$key] = $a;
        }

        // Held by 2 of 2 listable members → unlocked + 100 %.
        $this->assertAchievement($byKey['first_run'] ?? null, unlocked: true, count: 2, percent: 100);
        // Held by alice only (1 of 2) → 50 %.
        $this->assertAchievement($byKey['goal_hunter'] ?? null, unlocked: true, count: 1, percent: 50);
        // Never granted → locked, 0.
        $this->assertAchievement($byKey['omnivore'] ?? null, unlocked: false, count: 0, percent: 0);
    }

    private function assertAchievement(mixed $achievement, bool $unlocked, int $count, ?int $percent): void
    {
        self::assertIsArray($achievement);
        self::assertSame($unlocked, $achievement['unlocked']);
        $rarity = $achievement['rarity'] ?? null;
        self::assertIsArray($rarity);
        self::assertSame($count, $rarity['count']);
        self::assertSame($percent, $rarity['percent']);
    }

    public function testCatalogueIsNotFoundForUnknownSlug(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ghost/achievements');
        self::assertResponseStatusCodeSame(404);
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
}
