<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminDolibarrResyncTest extends FunctionalTestCase
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
        $this->client->jsonRequest('POST', '/api/v1/admin/memberships/dolibarr/resync');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminReturns403(): void
    {
        $user = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships/dolibarr/resync');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminNoMembershipsReturnsZeroQueued(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships/dolibarr/resync');

        self::assertResponseStatusCodeSame(202);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame(0, $data['queued']);
    }

    public function testAdminWithMembershipsReturnsCorrectQueuedCount(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member1 = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $member2 = $this->createUser('pierre@example.org', ['ROLE_USER'], 'Pierre');
        $this->createMembership($member1->getId());
        $this->createMembership($member2->getId());

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships/dolibarr/resync');

        self::assertResponseStatusCodeSame(202);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame(2, $data['queued']);
    }

    private function createMembership(string $userId): Membership
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
