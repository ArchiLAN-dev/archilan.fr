<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class HelloAssoSyncTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminGetsOperationalErrorWhenHelloAssoApiConfigIsMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(503);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('helloasso_not_configured', $response['error']['code']);
        self::assertIsString($response['error']['message']);
        self::assertStringContainsString('HELLOASSO_', $response['error']['message']);
    }

    public function testAdminGets422WhenEventHasNoFormSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(helloassoFormSlug: null);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminGets404ForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/nonexistent/payments/sync');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLambdaCannotTriggerSync(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->loginAs($lambda);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotTriggerSync(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events/any/payments/sync');

        self::assertResponseStatusCodeSame(401);
    }

    private function createEvent(?string $helloassoFormSlug): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            Event::STATUS_PUBLISHED,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
            true,
            null,
            false,
            [],
            null,
            null,
            $now,
            $now,
            null,
            null,
            $helloassoFormSlug,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
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
