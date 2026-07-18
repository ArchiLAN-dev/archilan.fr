<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Support\AuthSessionSigner;
use App\Identity\Application\Support\RefreshTokenFactory;
use App\Identity\Domain\Entity\User;
use App\Identity\Infrastructure\Doctrine\DoctrineRefreshTokenRepository;
use App\Identity\Presentation\Controller\AuthController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\Cookie;

final class AuthLogoutTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private RefreshTokenFactory $factory;
    private DoctrineRefreshTokenRepository $repository;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->entityManager;

        $this->factory = new RefreshTokenFactory();
        $this->repository = new DoctrineRefreshTokenRepository($this->em, $this->em->getConnection());

        $user = $this->registerUser('user@example.org');
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
