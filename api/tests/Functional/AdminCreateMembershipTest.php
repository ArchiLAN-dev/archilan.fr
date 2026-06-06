<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class AdminCreateMembershipTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', ['userId' => 'some-id']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminReturns403(): void
    {
        $user = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', ['userId' => 'some-id']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesMembershipReturns201(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', [
            'userId' => $member->getId(),
            'adminNote' => 'Créé manuellement',
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame($member->getId(), $data['userId']);
        self::assertSame('active', $data['status']);
        self::assertSame('admin', $data['source']);
    }

    public function testAdminMissingUserIdReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', ['adminNote' => 'sans userId']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminInvalidStartedAtReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', [
            'userId' => $member->getId(),
            'startedAt' => 'not-a-date',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminInvalidExpiresAtReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', [
            'userId' => $member->getId(),
            'expiresAt' => 'not-a-date',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCreatesMembershipWithExplicitExpiresAt(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $member = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');

        $this->client->jsonRequest('POST', '/api/v1/admin/memberships', [
            'userId' => $member->getId(),
            'startedAt' => '2026-01-01',
            'expiresAt' => '2026-06-30',
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertSame('active', $data['status']);
        self::assertIsString($data['startedAt']);
        self::assertStringStartsWith('2026-01-01', $data['startedAt']);
        self::assertIsString($data['expiresAt']);
        self::assertStringStartsWith('2026-06-30', $data['expiresAt']);
    }
}
