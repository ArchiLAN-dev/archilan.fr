<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminDashboardStatsTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsValidStatsShape(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);

        $data = $response['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('publishedEvents', $data);
        self::assertArrayHasKey('totalConfirmedRegistrations', $data);
        self::assertArrayHasKey('gameCount', $data);
        self::assertIsInt($data['publishedEvents']);
        self::assertIsInt($data['totalConfirmedRegistrations']);
        self::assertIsInt($data['gameCount']);
    }

    public function testCountsPublishedEventsExcludesDrafts(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createEvent(Event::STATUS_DRAFT);
        $this->createEvent(Event::STATUS_PUBLISHED);
        $this->createEvent(Event::STATUS_IN_PROGRESS);
        $this->createEvent(Event::STATUS_COMPLETED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(3, $data['publishedEvents']);
    }

    public function testCountsReservedRegistrationsExcludesCancelled(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->createEvent(Event::STATUS_PUBLISHED);
        $this->createRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->createRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['totalConfirmedRegistrations']);
    }

    public function testCountsGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createGame('Game A', 'game-a');
        $this->createGame('Game B', 'game-b');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(2, $data['gameCount']);
    }

    private function createEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Test Event',
            'Description.',
            $status,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
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
            null,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function createRegistration(string $eventId, string $userId, string $status): Registration
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $status,
            $now,
            $now,
        );

        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    private function createGame(string $name, string $slug): ArchipelagoGame
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $game = ArchipelagoGame::create($name, $slug, 'Description.', null, 'Alt', 'Publisher', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
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
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
