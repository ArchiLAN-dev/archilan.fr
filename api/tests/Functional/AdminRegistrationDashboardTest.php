<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminRegistrationDashboardTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
            $this->entityManager->getClassMetadata(HelloAssoOrder::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyEventReturnsEmptyList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(0, $data);
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame(0, $meta['total']);
    }

    public function testListsRegistrationsWithParticipantInfo(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER'], 'Jean Marius');
        $event = $this->createEvent();
        $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame(1, $meta['total']);

        $row = $data[0];
        self::assertIsArray($row);
        self::assertSame(Registration::STATUS_RESERVED, $row['status']);
        self::assertFalse($row['usedPrivateAccess']);
        self::assertIsString($row['createdAt']);
        self::assertNull($row['submittedAt']);

        $participant_ = $row['participant'];
        self::assertIsArray($participant_);
        self::assertSame($participant->getId(), $participant_['userId']);
        self::assertSame('Jean Marius', $participant_['displayName']);
        self::assertSame('user@example.org', $participant_['email']);

        self::assertSame([], $row['selectedGames']);
        self::assertFalse($row['gameSelectionComplete']);
        self::assertArrayHasKey('payment', $row);
        self::assertNull($row['payment']);
    }

    public function testListsPaymentSummaryWhenOrderMatchesParticipantEmail(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('paid@example.org', ['ROLE_USER'], 'Paid Player');
        $event = $this->createEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->createRegistration($event->getId(), $participant->getId());
        $this->createOrder('archilan-spring-2027', 'paid@example.org', 'processed', 2500, false);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertIsArray($row['payment']);
        self::assertSame('processed', $row['payment']['status']);
        self::assertSame(2500, $row['payment']['amountCents']);
        self::assertFalse($row['payment']['isStale']);
    }

    public function testMarksPrivateAccessRegistrations(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $this->createRegistration($event->getId(), $participant->getId());
        $this->createPrivateAccessLog($event->getId(), $participant->getId(), granted: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertTrue($row['usedPrivateAccess']);
    }

    public function testFilterByStatusReserved(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $this->createRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->createRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations?status=reserved', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row0 = $data[0];
        self::assertIsArray($row0);
        self::assertSame(Registration::STATUS_RESERVED, $row0['status']);
    }

    public function testFilterByStatusCancelled(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $this->createRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->createRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations?status=cancelled', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row0 = $data[0];
        self::assertIsArray($row0);
        self::assertSame(Registration::STATUS_CANCELLED, $row0['status']);
    }

    public function testIncludesSelectedGamesSummaryAndCompleteness(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(gameSelectionConfig: [['gameId' => $game->getId()]]);
        $this->createRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, [$game->getId()]);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);

        $selectedGames = $row['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $game0 = $selectedGames[0];
        self::assertIsArray($game0);
        self::assertSame($game->getId(), $game0['gameId']);
        self::assertSame('Zelda OoT', $game0['gameName']);
        self::assertTrue($row['gameSelectionComplete']);
    }

    public function testMissingSelectedGameMarksSelectionIncomplete(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent(gameSelectionConfig: [['gameId' => 'missing-game']]);
        $this->createRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, ['missing-game']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);

        $selectedGames = $row['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $game0 = $selectedGames[0];
        self::assertIsArray($game0);
        self::assertSame('missing-game', $game0['gameId']);
        self::assertSame('missing-game', $game0['gameName']);
        self::assertFalse($row['gameSelectionComplete']);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function createEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?string $helloassoFormSlug = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            Event::STATUS_PUBLISHED,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            true,
            null,
            $gameSelectionEnabled,
            $gameSelectionConfig,
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
     * @param list<string> $selectedGameIds
     */
    private function createRegistration(
        string $eventId,
        string $userId,
        string $status = Registration::STATUS_RESERVED,
        array $selectedGameIds = [],
    ): Registration {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $slots = array_map(
            static fn (string $gameId, int $idx): array => [
                'slotId' => bin2hex(random_bytes(8)),
                'gameId' => $gameId,
                'slotOrder' => $idx + 1,
            ],
            $selectedGameIds,
            array_keys($selectedGameIds),
        );
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $status,
            $now,
            $now,
            $slots,
        );

        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    private function createPrivateAccessLog(string $eventId, string $userId, bool $granted): EventPrivateAccessLog
    {
        $log = new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $granted,
            new \DateTimeImmutable('2026-05-01T10:00:00+00:00'),
        );

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function createGame(string $name, string $slug): ArchipelagoGame
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $game = ArchipelagoGame::create($name, $slug, 'Description.', null, 'Alt', 'Publisher', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createOrder(string $formSlug, string $payerEmail, string $status, int $amountCents, bool $stale): HelloAssoOrder
    {
        $now = $stale
            ? new \DateTimeImmutable('-48 hours')
            : new \DateTimeImmutable();

        $order = HelloAssoOrder::fromHelloAsso(
            random_int(100000, 999999),
            'evenements',
            $formSlug,
            $status,
            $amountCents,
            $payerEmail,
            'Jean',
            'Dupont',
            new \DateTimeImmutable('2026-04-20T14:00:00+00:00'),
            $now,
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles, ?string $displayName = null): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
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
