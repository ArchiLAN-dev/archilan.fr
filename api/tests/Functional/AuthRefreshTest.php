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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthRefreshTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RefreshTokenFactory $factory;
    private RefreshTokenRepository $repository;
    private string $userId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->factory = new RefreshTokenFactory();
        $this->repository = new RefreshTokenRepository($em);

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

    public function testValidRefreshTokenRotatesToNewPair(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $entity] = $this->factory->issue($this->userId, $now);
        $this->repository->save($entity);

        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                AuthController::REFRESH_COOKIE_NAME,
                $rawToken,
                null,
                AuthController::REFRESH_COOKIE_PATH,
            )
        );
        $this->client->jsonRequest('POST', AuthController::REFRESH_COOKIE_PATH);

        self::assertResponseStatusCodeSame(204);

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }
        self::assertArrayHasKey(AuthSessionSigner::COOKIE_NAME, $cookiesByName);
        self::assertArrayHasKey(AuthController::REFRESH_COOKIE_NAME, $cookiesByName);

        $this->em->clear();
        $oldToken = $this->repository->findByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($oldToken);
        self::assertTrue($oldToken->isRevoked());
    }

    public function testMissingRefreshCookieReturns401(): void
    {
        $this->client->jsonRequest('POST', AuthController::REFRESH_COOKIE_PATH);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('invalid_refresh_token', $response['error']['code']);
    }

    public function testExpiredRefreshTokenReturns401(): void
    {
        $pastDate = new \DateTimeImmutable('-1 day');
        $rawToken = 'expired-raw-token-value-for-test-purposes-only';
        $entity = RefreshToken::issue($this->userId, $rawToken, $pastDate, $pastDate->modify('-31 days'));
        $this->em->persist($entity);
        $this->em->flush();

        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                AuthController::REFRESH_COOKIE_NAME,
                $rawToken,
                null,
                AuthController::REFRESH_COOKIE_PATH,
            )
        );
        $this->client->jsonRequest('POST', AuthController::REFRESH_COOKIE_PATH);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('invalid_refresh_token', $response['error']['code']);
    }

    public function testReuseOfRevokedTokenRevokesAllAndReturns401(): void
    {
        $now = new \DateTimeImmutable();

        ['rawToken' => $raw1, 'entity' => $token1] = $this->factory->issue($this->userId, $now);
        ['rawToken' => $raw2, 'entity' => $token2] = $this->factory->issue($this->userId, $now);
        $this->em->persist($token1);
        $this->em->persist($token2);
        $this->em->flush();

        $token1->revoke($now);
        $this->em->flush();

        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                AuthController::REFRESH_COOKIE_NAME,
                $raw1,
                null,
                AuthController::REFRESH_COOKIE_PATH,
            )
        );
        $this->client->jsonRequest('POST', AuthController::REFRESH_COOKIE_PATH);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('token_reuse_detected', $response['error']['code']);

        $this->em->clear();
        $stillActive = $this->repository->findByTokenHash(hash('sha256', $raw2));
        self::assertNotNull($stillActive);
        self::assertTrue($stillActive->isRevoked());
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
