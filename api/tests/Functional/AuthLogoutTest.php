<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\RefreshTokenFactory;
use App\Identity\Application\RefreshTokenRepository;
use App\Identity\Application\RegisterLambdaUser;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use App\Identity\Presentation\AuthController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\BrowserKit\Cookie;

final class AuthLogoutTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private RefreshTokenFactory $factory;
    private RefreshTokenRepository $repository;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->entityManager;

        $this->factory = new RefreshTokenFactory();
        $this->repository = new RefreshTokenRepository($this->em);

        $metadata = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(RefreshToken::class),
        ];
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema(array_reverse($metadata));
        $schemaTool->createSchema($metadata);

        $register = self::getContainer()->get(RegisterLambdaUser::class);
        self::assertInstanceOf(RegisterLambdaUser::class, $register);
        $result = $register->register('user@example.org', 'correct horse battery staple', true);
        $user = $result['user'] ?? null;
        self::assertInstanceOf(User::class, $user);
        $this->userId = $user->getId();
    }

    public function testLogoutRevokesRefreshTokenAndClears204(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $entity] = $this->factory->issue($this->userId, $now);
        $this->repository->save($entity);

        $this->client->getCookieJar()->set(
            new Cookie(AuthController::REFRESH_COOKIE_NAME, $rawToken, null, AuthController::REFRESH_COOKIE_SCOPE),
        );
        $this->client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(204);

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }
        self::assertArrayHasKey(AuthSessionSigner::COOKIE_NAME, $cookiesByName);
        self::assertArrayHasKey(AuthController::REFRESH_COOKIE_NAME, $cookiesByName);
        self::assertLessThan(time(), $cookiesByName[AuthSessionSigner::COOKIE_NAME]->getExpiresTime());
        self::assertLessThan(time(), $cookiesByName[AuthController::REFRESH_COOKIE_NAME]->getExpiresTime());

        $this->em->clear();
        $token = $this->repository->findByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($token);
        self::assertTrue($token->isRevoked());
    }

    public function testLogoutWithoutCookieReturns204(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(204);

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }
        self::assertArrayHasKey(AuthSessionSigner::COOKIE_NAME, $cookiesByName);
        self::assertArrayHasKey(AuthController::REFRESH_COOKIE_NAME, $cookiesByName);
    }

    public function testLogoutWithUnknownTokenReturns204(): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthController::REFRESH_COOKIE_NAME, 'nonexistent-raw-token', null, AuthController::REFRESH_COOKIE_SCOPE),
        );
        $this->client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(204);
    }

    public function testRefreshWithRevokedTokenReturns401(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $entity] = $this->factory->issue($this->userId, $now);
        $this->repository->save($entity);

        $this->client->getCookieJar()->set(
            new Cookie(AuthController::REFRESH_COOKIE_NAME, $rawToken, null, AuthController::REFRESH_COOKIE_SCOPE),
        );
        $this->client->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $token = $this->repository->findByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($token);
        self::assertTrue($token->isRevoked());

        $this->client->getCookieJar()->set(
            new Cookie(AuthController::REFRESH_COOKIE_NAME, $rawToken, null, AuthController::REFRESH_COOKIE_SCOPE),
        );
        $this->client->jsonRequest('POST', AuthController::REFRESH_COOKIE_PATH);

        self::assertResponseStatusCodeSame(401);
    }
}
