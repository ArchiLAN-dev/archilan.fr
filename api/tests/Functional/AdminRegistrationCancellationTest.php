<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminRegistrationCancellationTest extends WebTestCase
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
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('DELETE', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/nonexistent', $event->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationFromOtherEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $eventA = $this->createEvent();
        $eventB = $this->createEvent();
        $registration = $this->createRegistration($eventA->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $eventB->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelsReservedRegistration(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        self::assertSame('cancelled', $response['outcome']);

        $this->entityManager->refresh($registration);
        self::assertSame(Registration::STATUS_CANCELLED, $registration->getStatus());
    }

    public function testAlreadyCancelledReturns409(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(409);
    }

    public function testCanCancelEvenWhenEventIsInProgress(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent(status: Event::STATUS_IN_PROGRESS);
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->refresh($registration);
        self::assertSame(Registration::STATUS_CANCELLED, $registration->getStatus());
    }

    public function testAdminCanModifyRegistrationGameSelection(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $gameA = $this->createGame('Zelda OoT', 'zelda-oot');
        $gameB = $this->createGame('Celeste', 'celeste');
        $event = $this->createEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [
                ['gameId' => $gameA->getId()],
                ['gameId' => $gameB->getId()],
            ],
            maxGamesPerRegistrant: 2,
        );
        $registration = $this->createRegistration($event->getId(), $participant->getId(), selectedGameIds: [$gameA->getId()]);
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'PATCH',
            sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()),
            [
                'slots' => [
                    ['gameId' => $gameA->getId()],
                    ['gameId' => $gameB->getId()],
                ],
            ],
        );

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame('updated', $meta['outcome']);

        $this->entityManager->refresh($registration);
        $gameSlots = $registration->getGameSlots();
        self::assertSame([$gameA->getId(), $gameB->getId()], array_column($gameSlots, 'gameId'));
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function createEvent(
        string $status = Event::STATUS_PUBLISHED,
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?int $maxGamesPerRegistrant = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            $status,
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
            $maxGamesPerRegistrant,
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
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
