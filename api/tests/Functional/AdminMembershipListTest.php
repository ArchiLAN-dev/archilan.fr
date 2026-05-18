<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminMembershipListTest extends FunctionalTestCase
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
        $this->client->jsonRequest('GET', '/api/v1/admin/memberships');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminReturns403(): void
    {
        $user = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/memberships');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReturnsEmptyList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/memberships');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertIsArray($response['meta']);
        self::assertSame([], $response['data']);
        $meta = $response['meta'];
        self::assertSame(0, $meta['total']);
    }

    public function testAdminReturnsFilteredByStatus(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member = $this->createUser('jean@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Jean');
        $this->createMembership($member->getId(), 'active');

        $this->client->jsonRequest('GET', '/api/v1/admin/memberships?status=active');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        self::assertIsArray($response['data'][0]);
        $entry = $response['data'][0];
        self::assertSame('active', $entry['status']);
        self::assertSame('jean@example.org', $entry['email']);
    }

    public function testAdminReturnsSearchResults(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member1 = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean Dupont');
        $member2 = $this->createUser('pierre@example.org', ['ROLE_USER'], 'Pierre Martin');
        $this->createMembership($member1->getId(), 'active');
        $this->createMembership($member2->getId(), 'active');

        $this->client->jsonRequest('GET', '/api/v1/admin/memberships?search=jean');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        self::assertIsArray($response['data'][0]);
        $entry = $response['data'][0];
        self::assertSame('jean@example.org', $entry['email']);
    }

    public function testAdminInvalidStatusParameterReturns400(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/memberships?status=invalid');

        self::assertResponseStatusCodeSame(400);
    }

    private function createMembership(string $userId, string $status): Membership
    {
        $now = new \DateTimeImmutable('2026-05-16T10:00:00+00:00');
        $membership = Membership::create(
            $userId,
            $now,
            $now->add(new \DateInterval('P12M')),
            'admin',
            null,
            null,
            $now,
        );

        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }
}
