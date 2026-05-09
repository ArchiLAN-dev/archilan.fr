<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class RealtimeTokenTest extends WebTestCase
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

        $metadata = [$this->entityManager->getClassMetadata(User::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminReceivesTokenForValidAdminTopic(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $this->client->setServerParameter('HTTP_HOST', 'example.com');

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/registrations',
        );

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('token', $response['data']);
        self::assertIsString($response['data']['token']);
        self::assertNotEmpty($response['data']['token']);
        self::assertArrayHasKey('hubUrl', $response['data']);
        self::assertStringContainsString(
            'mercureAuthorization=',
            (string) $this->client->getResponse()->headers->get('set-cookie'),
        );
    }

    public function testReturns422WhenNoValidTopicsRequested(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/realtime/subscribe-token');

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns422ForPublicTopics(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/seat-counter',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testLambdaCannotGetSubscribeToken(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/registrations',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotGetSubscribeToken(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/realtime/subscribe-token');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
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
}
