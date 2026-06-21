<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementGrant;
use App\Community\Domain\DefaultAchievementDefinitions;

final class CommunityAchievementsTest extends FunctionalTestCase
{
    public function testProfileSurfacesRecentUnlockedAndCounts(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(AchievementGrant::grant($user->getId(), 'first_run', new \DateTimeImmutable('2026-06-01T10:00:00+00:00')));
        $this->entityManager->persist(AchievementGrant::grant($user->getId(), 'first_goal', new \DateTimeImmutable('2026-06-02T10:00:00+00:00')));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        // The profile card shows only the unlocked achievements (most recent first); the full catalogue
        // (incl. locked) now lives on /achievements (story 30.31, covered by CommunityProfileAchievementsTest).
        $achievements = $data['achievements'] ?? null;
        self::assertIsArray($achievements);
        self::assertCount(2, $achievements);
        $first = $achievements[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('first_goal', $first['key']); // most recent (2026-06-02)
        self::assertIsString($first['unlockedAt']);
        foreach ($achievements as $achievement) {
            self::assertIsArray($achievement);
            self::assertTrue($achievement['unlocked']);
        }

        $stats = $data['achievementStats'] ?? null;
        self::assertIsArray($stats);
        self::assertSame(2, $stats['unlocked']);
        self::assertSame(count(DefaultAchievementDefinitions::all()), $stats['total']);

        // Level/XP: 2 achievements * 100 XP, no run stats -> 200 XP -> level 1 (next at 200).
        $level = $data['level'] ?? null;
        self::assertIsArray($level);
        self::assertSame(200, $level['xp']);
        self::assertSame(1, $level['level']);
        self::assertSame(100, $level['xpIntoLevel']);
        self::assertSame(200, $level['xpForNextLevel']);
    }
}
