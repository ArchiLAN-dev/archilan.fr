<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * The gaming panel's read (story 36.4). Personal runs had no admin surface at all before this - the
 * operational hole issue #387 describes.
 */
final class AdminUserGamingTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-08-07T10:00:00+00:00');
    }

    public function testSeparatesOwnedRunsFromJoinedOnes(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $other = $this->createUser('other@example.org', ['ROLE_USER'], 'Other');

        $owned = Run::create($target->getId(), 'Sa propre run', $this->now);
        $joined = Run::create($other->getId(), 'La run d\'un autre', $this->now);
        $this->entityManager->persist($owned);
        $this->entityManager->persist($joined);
        $this->entityManager->persist(RunParticipant::create($joined->getId(), $target->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();

        self::assertIsArray($data['ownedRuns']);
        self::assertCount(1, $data['ownedRuns']);
        self::assertIsArray($data['ownedRuns'][0]);
        self::assertSame('Sa propre run', $data['ownedRuns'][0]['title']);
        // The id is what story 36.6 will act on.
        self::assertSame($owned->getId(), $data['ownedRuns'][0]['id']);

        self::assertIsArray($data['joinedRuns']);
        self::assertCount(1, $data['joinedRuns']);
        self::assertIsArray($data['joinedRuns'][0]);
        self::assertSame('La run d\'un autre', $data['joinedRuns'][0]['title']);
    }

    public function testReportsProgressionAndLinkedAccounts(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $target->linkDiscord('discord-123', 'target#0001', $this->now);
        $target->updateSteamProfile('https://steamcommunity.com/id/target');
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();

        self::assertIsArray($data['progress']);
        self::assertArrayHasKey('level', $data['progress']);
        self::assertArrayHasKey('xp', $data['progress']);

        self::assertIsArray($data['accounts']);
        self::assertSame('discord-123', $data['accounts']['discordId']);
        self::assertSame('target#0001', $data['accounts']['discordUsername']);
        self::assertSame('https://steamcommunity.com/id/target', $data['accounts']['steamProfile']);
    }

    public function testAMemberWithoutAnyGameActivityGetsEmptyLists(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame([], $data['ownedRuns']);
        self::assertSame([], $data['joinedRuns']);
        self::assertSame([], $data['history']);
        self::assertIsArray($data['accounts']);
        self::assertNull($data['accounts']['discordId']);
    }

    public function testAnotherMembersRunsDoNotLeakIn(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $other = $this->createUser('other@example.org', ['ROLE_USER'], 'Other');

        $this->entityManager->persist(Run::create($other->getId(), 'Run de quelqu\'un d\'autre', $this->now));
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame([], $data['ownedRuns']);
        self::assertSame([], $data['joinedRuns']);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', $this->url('nonexistentid000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    private function url(string $userId): string
    {
        return sprintf('/api/v1/admin/users/%s/gaming', $userId);
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
