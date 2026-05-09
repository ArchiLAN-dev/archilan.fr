<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminUserRoleTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(RoleChangeAudit::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedRoleChangeIsRejected(): void
    {
        $target = $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaRoleChangeIsForbidden(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($lambda);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminPromotesLambdaToMemberWithAudit(): void
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
        self::assertSame('lambda', $audit->getPreviousRole());
        self::assertSame('member', $audit->getNewRole());
        self::assertNotSame('', $audit->getId());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $audit->getChangedAt());
    }

    public function testAdminDemotesMemberToLambdaWithAudit(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'lambda',
            'confirmed' => true,
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('lambda', $response['data']['role']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);

        $audit = $this->singleAudit();
        self::assertSame('member', $audit->getPreviousRole());
        self::assertSame('lambda', $audit->getNewRole());
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

    public function testAdminAndSelfRoleMutationsAreRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $otherAdmin = $this->createUser('other-admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Other Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $admin->getId()), [
            'role' => 'lambda',
            'confirmed' => true,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $otherAdmin->getId()), [
            'role' => 'lambda',
            'confirmed' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
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

    private function singleAudit(): RoleChangeAudit
    {
        $audits = $this->entityManager->getRepository(RoleChangeAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertInstanceOf(RoleChangeAudit::class, $audit);

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
