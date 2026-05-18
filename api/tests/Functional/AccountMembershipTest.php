<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use Doctrine\ORM\Tools\SchemaTool;

final class AccountMembershipTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(EmailConfirmationToken::class),
            $this->entityManager->getClassMetadata(Membership::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/account/membership');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testNoMembershipReturnsStatusNone(): void
    {
        $user = $this->createUser('jean@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/account/membership');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame('none', $data['status']);
        self::assertNull($data['expiresAt']);
        self::assertNull($data['startedAt']);
    }

    public function testActiveMembershipReturnsStatusActive(): void
    {
        $user = $this->createUser('jean@example.org');
        $this->loginAs($user);
        $this->createMembership($user->getId(), 'active', new \DateTimeImmutable('2027-05-16'));

        $this->client->jsonRequest('GET', '/api/v1/account/membership');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame('active', $data['status']);
        self::assertIsString($data['expiresAt']);
        self::assertStringStartsWith('2027-05-16', $data['expiresAt']);
        self::assertIsString($data['startedAt']);
    }

    public function testExpiredMembershipReturnsStatusExpired(): void
    {
        $user = $this->createUser('jean@example.org');
        $this->loginAs($user);
        $this->createMembership($user->getId(), 'expired', new \DateTimeImmutable('2025-05-16'));

        $this->client->jsonRequest('GET', '/api/v1/account/membership');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame('expired', $data['status']);
        self::assertIsString($data['expiresAt']);
    }

    private function createMembership(
        string $userId,
        string $status,
        \DateTimeImmutable $expiresAt,
    ): Membership {
        $now = new \DateTimeImmutable('2026-05-16T10:00:00+00:00');
        $membership = Membership::create(
            $userId,
            $now,
            $expiresAt,
            'admin',
            null,
            null,
            $now,
        );

        if ('expired' === $status) {
            $membership->expire($now);
        }

        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }
}
