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

final class RegistrationGameSelectionTest extends WebTestCase
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

    public function testAnonymousGets401OnGet(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/registrations/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousGets401OnPut(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/registrations/nonexistent/game-selection', ['gameIds' => []]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationReturns404OnGet(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/registrations/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownRegistrationReturns404OnPut(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/registrations/nonexistent/game-selection', ['gameIds' => []]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserReturns404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $owner->getId());

        $this->loginAs($other);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationReturns404OnGetAndPut(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: true);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_CANCELLED);

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testGetSelectionWhenGameSelectionDisabled(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame($event->getId(), $data['eventId']);
        self::assertFalse($data['gameSelectionEnabled']);
        self::assertNull($data['maxGamesPerRegistrant']);
        self::assertSame([], $data['slots']);
        self::assertSame([], $data['availableGames']);
    }

    public function testGetSelectionWithAvailableGames(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]], maxPerRegistrant: 2);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['gameSelectionEnabled']);
        self::assertSame(2, $data['maxGamesPerRegistrant']);
        self::assertSame([], $data['slots']);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(1, $availableGames);
        $firstGame = $availableGames[0];
        self::assertIsArray($firstGame);
        self::assertSame($game->getId(), $firstGame['id']);
        self::assertSame('Zelda OoT', $firstGame['name']);
        self::assertFalse($firstGame['isApworldReady']);
        self::assertNull($firstGame['defaultYaml']);
    }

    public function testPutSavesGameSelection(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId()],
        ]);
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        self::assertIsArray($slots[0]);
        self::assertSame($game->getId(), $slots[0]['gameId']);
        self::assertSame(1, $slots[0]['slotOrder']);
        self::assertIsString($slots[0]['slotId']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshed);
        self::assertSame([$game->getId()], $refreshed->getSelectedGameIds());
    }

    public function testPutRejectsGameSelectionWhenDisabled(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPutRejectsGameNotInEventConfig(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => ['not-a-valid-game-id'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('gameIds.0', $details);
    }

    public function testPutRejectsWhenMaxExceeded(): void
    {
        $user = $this->createUser('user@example.org');
        $gameA = $this->createGame('Zelda OoT', 'zelda-oot');
        $gameB = $this->createGame('Super Metroid', 'super-metroid');
        $event = $this->createEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [
                ['gameId' => $gameA->getId()],
                ['gameId' => $gameB->getId()],
            ],
            maxPerRegistrant: 1,
        );
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$gameA->getId(), $gameB->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('gameIds', $details);
    }

    public function testPutAllowsDuplicateGameIds(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
            maxPerRegistrant: 2,
        );
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId(), $game->getId()],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshed);
        self::assertCount(2, $refreshed->getGameSlots());
        self::assertSame([$game->getId(), $game->getId()], $refreshed->getSelectedGameIds());
    }

    public function testGetSelectionIncludesSlotDetails(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $slotId = bin2hex(random_bytes(8));
        $registration->replaceSlots([['slotId' => $slotId, 'gameId' => $game->getId()]], new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $slot0 = $slots[0];
        self::assertIsArray($slot0);
        self::assertSame($slotId, $slot0['slotId']);
        self::assertSame($game->getId(), $slot0['gameId']);
        self::assertArrayHasKey('playerYaml', $slot0);
        self::assertArrayHasKey('gameName', $slot0);
    }

    public function testGetSelectionIncludesRegistrationOpenFlag(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['registrationOpen']);
    }

    public function testPutRejectsWhenRegistrationWindowClosed(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->createEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
            registrationClosesAt: new \DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('registration', $details);
    }

    public function testGetSelectionShowsRegistrationOpenFalseWhenClosed(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(
            registrationClosesAt: new \DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['registrationOpen']);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function createEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?int $maxPerRegistrant = null,
        ?\DateTimeImmutable $registrationClosesAt = null,
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
            $registrationClosesAt ?? new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            true,
            null,
            $gameSelectionEnabled,
            $gameSelectionConfig,
            null,
            null,
            $now,
            $now,
            $maxPerRegistrant,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function createRegistration(
        string $eventId,
        string $userId,
        string $status = Registration::STATUS_RESERVED,
    ): Registration {
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
        $game = ArchipelagoGame::create($name, $slug, 'Un jeu compatible Archipelago.', null, 'Logo '.$name, 'Publisher', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createUser(string $email): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            ['ROLE_USER'],
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
