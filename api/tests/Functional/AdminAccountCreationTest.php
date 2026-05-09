<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\AdminAccountCreationAudit;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAccountCreationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(AdminAccountCreationAudit::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedAdminCreationIsRejected(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', $this->validPayload());

        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaAdminCreationIsForbidden(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');
        $this->loginAs($lambda);

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
        $payload['role'] = 'lambda';

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

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles, ?string $displayName): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            $displayName,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    private function singleAudit(): AdminAccountCreationAudit
    {
        $audits = $this->entityManager->getRepository(AdminAccountCreationAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertInstanceOf(AdminAccountCreationAudit::class, $audit);

        return $audit;
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
