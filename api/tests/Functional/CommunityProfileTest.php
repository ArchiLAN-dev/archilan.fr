<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\RefreshCommunityAvatars;
use App\Community\Domain\CommunityProfile;

final class CommunityProfileTest extends FunctionalTestCase
{
    public function testProfileReturnsIdentityAndStats(): void
    {
        $this->createUser('alice@example.org', slug: 'alice', displayName: 'Alice');

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('alice', $data['slug']);
        self::assertSame('Alice', $data['displayName']);
        self::assertArrayHasKey('joinedAt', $data);
        self::assertNull($data['avatarUrl']);

        $stats = $data['stats'] ?? null;
        self::assertIsArray($stats);
        self::assertSame(0, $stats['runsParticipated']);
        self::assertSame(0, $stats['goalCompletions']);
        self::assertEquals(0, $stats['goalCompletionRate']);
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/nobody');

        self::assertResponseStatusCodeSame(404);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('player_not_found', $error['code']);
    }

    public function testDeletedUserIsNotFound(): void
    {
        $user = $this->createUser('ghost@example.org', slug: 'ghost');
        $user->anonymizeForDeletion(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ghost');

        self::assertResponseStatusCodeSame(404);
    }

    public function testProfileRowIsCreatedLazilyAndIdempotently(): void
    {
        $user = $this->createUser('bob@example.org', slug: 'bob');

        $repo = $this->entityManager->getRepository(CommunityProfile::class);
        self::assertCount(0, $repo->findBy(['userId' => $user->getId()]));

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $repo->findBy(['userId' => $user->getId()]), 'profile row created once, not duplicated');
    }

    public function testAvatarIsCachedByRefreshAndReturnedOnView(): void
    {
        $this->createUser('carol@example.org', slug: 'carol');

        // First view: the row exists but no avatar is resolved yet.
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/carol');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertNull($data['avatarUrl']);

        // Off-request-path refresh resolves + caches the avatar (stub resolver in test).
        $refresh = self::getContainer()->get(RefreshCommunityAvatars::class);
        self::assertInstanceOf(RefreshCommunityAvatars::class, $refresh);
        self::assertGreaterThanOrEqual(1, $refresh->refreshStale());

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/carol');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['avatarUrl']);
        self::assertStringContainsString('cdn.example.test', $data['avatarUrl']);
    }
}
