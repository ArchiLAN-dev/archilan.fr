<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\RecomputeAchievements;
use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Identity\Domain\User;

final class AdminAchievementGrantTest extends FunctionalTestCase
{
    public function testAdminGrantsAchievementAndItShowsUnlockedOnProfile(): void
    {
        $this->loginAs($this->createAdmin());
        $target = $this->createUser('alice@example.org', slug: 'alice');
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => $target->getSlug()]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['special'], $this->grantedKeys($target->getId()));

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        self::assertContains('special', $this->profileAchievementKeys());
    }

    public function testGrantIsIdempotent(): void
    {
        $this->loginAs($this->createAdmin());
        $target = $this->createUser('alice@example.org', slug: 'alice');
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => $target->getSlug()]);
        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => $target->getSlug()]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['special'], $this->grantedKeys($target->getId()));
    }

    public function testRevokeRemovesTheGrant(): void
    {
        $this->loginAs($this->createAdmin());
        $target = $this->createUser('alice@example.org', slug: 'alice');
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => $target->getSlug()]);
        self::assertSame(['special'], $this->grantedKeys($target->getId()));

        $this->client->jsonRequest('DELETE', $this->grantsUrl($definition).'/'.$target->getSlug());
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->grantedKeys($target->getId()));
    }

    public function testUnknownDefinitionOrUserIsNotFound(): void
    {
        $this->loginAs($this->createAdmin());
        $target = $this->createUser('alice@example.org', slug: 'alice');
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.bin2hex(random_bytes(16)).'/grants', ['slug' => $target->getSlug()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => 'ghost']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiresAdmin(): void
    {
        $this->loginAs($this->createUser('plain@example.org', slug: 'plain'));
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => 'whoever']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testManualGrantSurvivesRecompute(): void
    {
        $this->loginAs($this->createAdmin());
        $target = $this->createUser('alice@example.org', slug: 'alice');
        // A rule the user can never satisfy (no events) - only a manual grant can award it.
        $definition = $this->definition('special');

        $this->client->jsonRequest('POST', $this->grantsUrl($definition), ['slug' => $target->getSlug()]);
        self::assertResponseStatusCodeSame(201);

        $recompute = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $recompute);
        $recompute->recomputeForUser($target->getId(), false);

        self::assertSame(['special'], $this->grantedKeys($target->getId()));
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin');
    }

    private function definition(string $key): AchievementDefinition
    {
        // eventsWithGoal >= 1 never matches a user with no events, so only a manual grant can award it.
        $rule = ['op' => 'all', 'rules' => [['fact' => 'eventsWithGoal', 'operator' => '>=', 'value' => 1]]];
        $definition = AchievementDefinition::create($key, ucfirst($key), '', $rule, 1, new \DateTimeImmutable());
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
    }

    private function grantsUrl(AchievementDefinition $definition): string
    {
        return '/api/v1/admin/community/achievements/'.$definition->getId().'/grants';
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

    /**
     * @return list<string>
     */
    private function profileAchievementKeys(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $achievements = $data['achievements'] ?? null;
        self::assertIsArray($achievements);

        $keys = [];
        foreach ($achievements as $achievement) {
            if (is_array($achievement) && is_string($achievement['key'] ?? null)) {
                $keys[] = $achievement['key'];
            }
        }

        return $keys;
    }
}
