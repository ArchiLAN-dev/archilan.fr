<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\AdminCreationAudit;
use App\Identity\Domain\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAccountCreationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUnauthenticatedAdminCreationIsRejected(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $this->validPayload());

        self::assertResponseStatusCodeSame(401);
    }

    public function testStandardAdminCreationIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $this->validPayload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesAdminAccountAndAudit(): void
    {
        $creator = $this->createUser('creator@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Creator');
        $this->loginAs($creator);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $this->validPayload());

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('new-admin@example.org', $response['data']['email']);
        self::assertSame('Nouvelle Admin', $response['data']['displayName']);
        self::assertSame('admin', $response['data']['role']);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $response['data']['roles']);

        $created = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'new-admin@example.org']);
        self::assertInstanceOf(User::class, $created);
        self::assertNotSame('correct horse battery staple', $created->getPassword());
        self::assertContains('ROLE_ADMIN', $created->getRoles());

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        self::assertTrue($passwordHasher->isPasswordValid($created, 'correct horse battery staple'));

        $audit = $this->singleAudit();
        self::assertSame($created->getId(), $audit->getCreatedUserId());
        self::assertSame($creator->getId(), $audit->getCreatorUserId());
        self::assertNotSame('', $audit->getId());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $audit->getCreatedAt());
    }

    public function testAdminCreationValidatesRequiredFields(): void
    {
        $creator = $this->createUser('creator@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Creator');
        $this->loginAs($creator);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', [
            'email' => 'invalid',
            'password' => 'short',
            'displayName' => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('email', $response['error']['details']);
        self::assertArrayHasKey('password', $response['error']['details']);
        self::assertArrayHasKey('displayName', $response['error']['details']);
    }

    public function testAdminCreationRejectsDuplicateEmail(): void
    {
        $creator = $this->createUser('creator@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Creator');
        $this->createUser('new-admin@example.org', ['ROLE_USER'], 'Existing');
        $this->loginAs($creator);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $this->validPayload());

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('email', $response['error']['details']);
    }

    public function testClientProvidedRolesAreIgnored(): void
    {
        $creator = $this->createUser('creator@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Creator');
        $this->loginAs($creator);

        $payload = $this->validPayload();
        $payload['roles'] = ['ROLE_USER'];
        $payload['role'] = 'user';

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $payload);

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('admin', $response['data']['role']);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $response['data']['roles']);
    }

    /**
     * @return array{email: string, password: string, displayName: string}
     */
    private function validPayload(): array
    {
        return [
            'email' => 'new-admin@example.org',
            'password' => 'correct horse battery staple',
            'displayName' => 'Nouvelle Admin',
        ];
    }

    private function singleAudit(): AdminCreationAudit
    {
        $audits = $this->entityManager->getRepository(AdminCreationAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertInstanceOf(AdminCreationAudit::class, $audit);

        return $audit;
    }
}
