<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementCatalog;
use App\Community\Domain\AchievementGrant;

final class CommunityAchievementsTest extends FunctionalTestCase
{
    public function testProfileSurfacesUnlockedAndLockedAchievements(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(AchievementGrant::grant($user->getId(), 'first_run', new \DateTimeImmutable('2026-06-01T10:00:00+00:00')));
        $this->entityManager->persist(AchievementGrant::grant($user->getId(), 'first_goal', new \DateTimeImmutable('2026-06-02T10:00:00+00:00')));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $achievements = $data['achievements'] ?? null;
        self::assertIsArray($achievements);
        self::assertCount(count(AchievementCatalog::all()), $achievements);

        $byKey = [];
        foreach ($achievements as $achievement) {
            self::assertIsArray($achievement);
            $key = $achievement['key'] ?? null;
            self::assertIsString($key);
            $byKey[$key] = $achievement;
        }

        self::assertTrue($byKey['first_run']['unlocked']);
        self::assertIsString($byKey['first_run']['unlockedAt']);
        self::assertTrue($byKey['first_goal']['unlocked']);
        self::assertFalse($byKey['veteran']['unlocked']);
        self::assertNull($byKey['veteran']['unlockedAt']);
    }
}
