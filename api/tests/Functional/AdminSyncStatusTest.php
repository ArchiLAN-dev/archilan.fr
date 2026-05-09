<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Payments\Domain\HelloAssoSyncLog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminSyncStatusTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(HelloAssoSyncLog::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testReturnsEmptyLogsWhenNoSyncAttemptted(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent('archilan-spring-2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertSame('archilan-spring-2027', $response['data']['formSlug']);
        self::assertSame([], $response['data']['recentSyncs']);
    }

    public function testReturnsNullFormSlugWhenEventHasNone(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(null);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['formSlug']);
        self::assertSame([], $response['data']['recentSyncs']);
    }

    public function testReturnsSyncLogsOrderedByMostRecentFirst(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent('archilan-spring-2027');
        $this->createSyncLog('archilan-spring-2027', true, null, new \DateTimeImmutable('2026-04-25T09:00:00+00:00'));
        $this->createSyncLog('archilan-spring-2027', false, 'HTTP 503', new \DateTimeImmutable('2026-04-25T10:00:00+00:00'));
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        $recentSyncs = $response['data']['recentSyncs'];
        self::assertIsArray($recentSyncs);
        self::assertCount(2, $recentSyncs);
        self::assertIsArray($recentSyncs[0]);
        self::assertFalse($recentSyncs[0]['success']);
        self::assertSame('HTTP 503', $recentSyncs[0]['errorMessage']);
        self::assertIsArray($recentSyncs[1]);
        self::assertTrue($recentSyncs[1]['success']);
    }

    public function testReturns404ForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/payments/sync/status');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLambdaCannotReadSyncStatus(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent('archilan-spring-2027');
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotReadSyncStatus(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/any/payments/sync/status');

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

    private function createSyncLog(string $formSlug, bool $success, ?string $errorMessage, \DateTimeImmutable $attemptAt): void
    {
        $log = $success
            ? HelloAssoSyncLog::fromSuccess($formSlug, $attemptAt)
            : HelloAssoSyncLog::fromFailure($formSlug, $errorMessage ?? 'Unknown error', $attemptAt);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }
}
