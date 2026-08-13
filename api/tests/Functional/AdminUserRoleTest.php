<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\RoleChangeAudit;
use App\Identity\Domain\Entity\User;

final class AdminUserRoleTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUnauthenticatedRoleChangeIsRejected(): void
    {
        $target = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testStandardRoleChangeIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminPromotesUserToMemberWithAudit(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('member', $response['data']['role']);
        self::assertIsArray($response['data']['roles']);
        self::assertContains('ROLE_MEMBER', $response['data']['roles']);

        $audit = $this->singleAudit();
        self::assertSame($target->getId(), $audit->getTargetUserId());
        self::assertSame($admin->getId(), $audit->getAdminUserId());
        self::assertSame('user', $audit->getPreviousRole());
        self::assertSame('member', $audit->getNewRole());
        self::assertNotSame('', $audit->getId());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $audit->getChangedAt());
    }

    public function testAdminDemotesMemberToUserWithAudit(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'user',
            'confirmed' => true,
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('user', $response['data']['role']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);

        $audit = $this->singleAudit();
        self::assertSame('member', $audit->getPreviousRole());
        self::assertSame('user', $audit->getNewRole());
    }

    public function testConfirmationIsRequired(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => false,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('confirmed', $response['error']['details']);
    }

    public function testSelfRoleMutationIsRejected(): void
    {
        // The only way back from demoting yourself would be another admin, and there may be none.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->createUser('other-admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Other Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $admin->getId()), [
            'role' => 'user',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminPromotesAMemberToAdmin(): void
    {
        // Story 36.1: promoting an existing account to admin was impossible through the interface -
        // only creating a brand-new admin account was.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'admin',
            'confirmed' => true,
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $target->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles());
        self::assertContains('ROLE_MEMBER', $refreshed->getRoles(), 'promotion must not cost the member status');
    }

    public function testAdminDemotesAnotherAdminAndKeepsTheirMemberStatus(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $otherAdmin = $this->createUser('other-admin@example.org', ['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN'], 'Other Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $otherAdmin->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $otherAdmin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertNotContains('ROLE_ADMIN', $refreshed->getRoles());
        self::assertContains('ROLE_MEMBER', $refreshed->getRoles());
    }

    public function testTheSiteAlwaysKeepsAnAdministrator(): void
    {
        // No explicit "last admin" guard exists, and none is needed: demoting the last admin would
        // require the last admin to be the target, and the last admin can only be the acting one -
        // which the self rule refuses. This test pins that invariant rather than a guard.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $otherAdmin = $this->createUser('other-admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Other Admin');
        $this->loginAs($admin);

        // Strip the site down to a single administrator: allowed, one remains.
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $otherAdmin->getId()), [
            'role' => 'user',
            'confirmed' => true,
        ]);
        self::assertResponseIsSuccessful();

        // That last administrator is necessarily the acting one, and cannot demote himself.
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $admin->getId()), [
            'role' => 'user',
            'confirmed' => true,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $admin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles(), 'the site must never end up with zero admins');
    }

    public function testRoleChangeForUnknownUserReturnsValidationError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/users/nonexistentid000000000000000000/role', [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('user', $response['error']['details']);
    }

    public function testRoleChangeForDeletedUserReturnsValidationError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $target->anonymizeForDeletion(new \DateTimeImmutable('2026-04-25T12:00:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('user', $response['error']['details']);
    }

    private function singleAudit(): RoleChangeAudit
    {
        $audits = $this->entityManager->getRepository(RoleChangeAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertInstanceOf(RoleChangeAudit::class, $audit);

        return $audit;
    }
}
