<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\DeletionAudit;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AccountDeletionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(DeletionAudit::class),
            $this->entityManager->getClassMetadata(RefreshToken::class),
            $this->entityManager->getClassMetadata(EmailConfirmationToken::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedDeletionIsRejected(): void
    {
        $this->client->jsonRequest('DELETE', '/api/v1/account');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedDeletionAnonymizesPersonalFieldsAndCreatesMinimalAudit(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('DELETE', '/api/v1/account');

        self::assertResponseIsSuccessful();
        $user = $this->entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isDeleted());
        self::assertStringStartsWith('deleted-', $user->getEmail());
        self::assertStringEndsWith('@deleted.local', $user->getEmail());
        self::assertNull($user->getDisplayName());
        self::assertSame(['ROLE_USER'], $user->getRoles());

        $audit = $this->entityManager->getRepository(DeletionAudit::class)->findOneBy([
            'userId' => $user->getId(),
        ]);
        self::assertInstanceOf(DeletionAudit::class, $audit);
        self::assertNotSame('', $audit->getId());
        self::assertSame('user_request', $audit->getReason());
        self::assertNotSame('jean@example.org', $audit->getEmailHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit->getEmailHash());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $audit->getDeletedAt());
    }

    public function testDeletionClearsCookieAndInvalidatesSession(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('DELETE', '/api/v1/account');

        self::assertResponseIsSuccessful();
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame(AuthSessionSigner::COOKIE_NAME, $cookie->getName());
        self::assertLessThan(time(), $cookie->getExpiresTime());

        $this->client->jsonRequest('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeletedUserCannotLoginAgain(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('DELETE', '/api/v1/account');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('invalid_credentials', $response['error']['code']);
    }

    private function createAndLoginUser(): void
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $registerUser);
        $result = $registerUser->register('jean@example.org', 'correct horse battery staple', true, 'Jean');
        self::assertSame([], $result['errors']);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();
    }
}
