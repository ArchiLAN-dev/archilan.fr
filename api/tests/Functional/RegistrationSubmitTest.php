<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\RegistrationConfirmationMessage;
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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RegistrationSubmitTest extends WebTestCase
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

    public function testAnonymousGets401OnPost(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/registrations/nonexistent/submit');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/registrations/nonexistent/submit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserReturns404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->createEvent(gameSelectionEnabled: false);
        $registration = $this->createRegistration($event->getId(), $owner->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($other);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: false);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_CANCELLED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testGameSelectionEnabledWithNoGamesReturns422(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: true);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('games_required', $error['code']);
    }

    public function testGameSelectionEnabledWithGamesConfirms(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame();
        $event = $this->createEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
        );
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, [$game->getId()]);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame($event->getTitle(), $data['eventTitle']);
        self::assertIsArray($data['selectedGameIds']);
        self::assertContains($game->getId(), $data['selectedGameIds']);

        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertIsString($meta['message']);
    }

    public function testGameSelectionDisabledConfirmsWithoutGames(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: false);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame($event->getTitle(), $data['eventTitle']);
        self::assertSame([], $data['selectedGameIds']);
    }

    public function testFirstSubmitSetsSubmittedAtAndDispatchesMessage(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: false);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshed);
        self::assertNotNull($refreshed->getSubmittedAt());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RegistrationConfirmationMessage::class, $message);
        self::assertSame($user->getEmail(), $message->userEmail);
        self::assertSame($event->getTitle(), $message->eventTitle);
    }

    public function testDuplicateSubmitDoesNotDispatchSecondMessage(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(gameSelectionEnabled: false);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        // The kernel resets the in-memory transport between requests.
        // After the second submit, 0 new messages means the email was not re-dispatched.
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function createEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
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
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * @param list<string> $selectedGameIds
     */
    private function createRegistration(string $eventId, string $userId, string $status, array $selectedGameIds): Registration
    {
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

    private function createGame(): ArchipelagoGame
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $game = ArchipelagoGame::create('Zelda OoT', 'zelda-oot', 'Un jeu compatible Archipelago.', null, 'Logo Zelda OoT', 'Nintendo', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);

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
