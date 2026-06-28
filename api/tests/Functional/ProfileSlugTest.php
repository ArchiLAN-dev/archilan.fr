<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;

final class ProfileSlugTest extends FunctionalTestCase
{
    public function testChangeSlugSuccess(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'alice-new']);
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('alice-new', $data['slug']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertSame('alice-new', $refreshed->getSlug());
        self::assertSame('alice', $refreshed->getPreviousSlug(), 'old slug kept for reclaim/reservation');
    }

    public function testInvalidSlugRejected(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'No Spaces!']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slug_invalid', $this->errorCode());
    }

    public function testReservedWordRejected(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'admin']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slug_reserved_word', $this->errorCode());
    }

    public function testTakenSlugRejected(): void
    {
        $this->createUser('bob@example.org', slug: 'taken');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'taken']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slug_taken', $this->errorCode());
    }

    public function testCooldownBlocksNewSlugButReclaimIsAllowed(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        // Simulate a recent change: now slug=alice2, previous=alice, changed 5 days ago (within cooldown).
        $user->changeSlug('alice2', new \DateTimeImmutable('-5 days'));
        $this->entityManager->flush();
        $this->loginAs($user);

        // A *new* slug is blocked by the cooldown.
        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'alice3']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slug_cooldown', $this->errorCode());

        // Reclaiming the just-released previous slug bypasses the cooldown.
        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'alice']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testSlugReleasedByAnotherUserIsReserved(): void
    {
        // Bob released "wanted" 5 days ago (still within the reservation window).
        $bob = $this->createUser('bob@example.org', slug: 'wanted');
        $bob->changeSlug('bob-new', new \DateTimeImmutable('-5 days'));
        $this->entityManager->flush();

        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('PUT', '/api/v1/account/slug', ['slug' => 'wanted']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slug_reserved', $this->errorCode());
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }
}
