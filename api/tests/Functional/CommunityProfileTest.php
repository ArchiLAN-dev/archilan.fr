<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\RefreshCommunityAvatars;
use App\Community\Domain\CommunityProfile;
use App\Membership\Domain\Membership;

final class CommunityProfileTest extends FunctionalTestCase
{
    public function testProfileExposesMemberAndAdminBadges(): void
    {
        // Plain user: no recognition badges.
        $this->createUser('plain@example.org', slug: 'plain');
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/plain');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->badges()['member']);
        self::assertFalse($this->badges()['admin']);

        // Admin role surfaces the admin badge.
        $this->createUser('boss@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'boss');
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/boss');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->badges()['admin']);
        self::assertFalse($this->badges()['member']);

        // An active membership (live lookup, not the stale ROLE_MEMBER) surfaces the member badge.
        $member = $this->createUser('paid@example.org', slug: 'paid');
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $this->entityManager->persist(Membership::create(
            $member->getId(), $now, new \DateTimeImmutable('2099-01-01T00:00:00+00:00'), 'admin', null, null, $now,
        ));
        $this->entityManager->flush();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/paid');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->badges()['member']);
        self::assertFalse($this->badges()['admin']);
    }

    /**
     * @return array<mixed>
     */
    private function badges(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $badges = $data['badges'] ?? null;
        self::assertIsArray($badges);

        return $badges;
    }

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

    public function testAnonymousViewDoesNotCreateAProfileRow(): void
    {
        $user = $this->createUser('bob@example.org', slug: 'bob');
        $repo = $this->entityManager->getRepository(CommunityProfile::class);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $repo->findBy(['userId' => $user->getId()]), 'a foreign/anonymous read must not write');
    }

    public function testOwnerViewCreatesProfileRowLazilyAndIdempotently(): void
    {
        $user = $this->createUser('bob@example.org', slug: 'bob');
        $repo = $this->entityManager->getRepository(CommunityProfile::class);
        self::assertCount(0, $repo->findBy(['userId' => $user->getId()]));

        $this->loginAs($user);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $repo->findBy(['userId' => $user->getId()]), 'profile row created once, not duplicated');
    }

    public function testAvatarIsCachedByRefreshAndReturnedOnView(): void
    {
        $carol = $this->createUser('carol@example.org', slug: 'carol');

        // Owner self-view creates the profile row; no avatar resolved yet.
        $this->loginAs($carol);
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
