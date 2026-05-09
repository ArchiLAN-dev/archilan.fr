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

final class RegistrationSlotYamlTest extends WebTestCase
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

    public function testAnonymousUserGets401(): void
    {
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/registrations/nonexistent/slots/nonexistent/yaml',
            ['playerYaml' => 'name: Test'],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongUserGets404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $game = $this->createApworldGame('Hollow Knight', 'hollow-knight');
        $event = $this->createEvent($game);
        $reg = $this->createRegistrationWithSlot($event, $owner->getId(), $game);

        $slotId = $reg->getGameSlots()[0]['slotId'];

        $this->loginAs($other);
        $this->client->jsonRequest(
            'PUT',
            sprintf('/api/v1/registrations/%s/slots/%s/yaml', $reg->getId(), $slotId),
            ['playerYaml' => 'name: Test'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonApworldGameReturns422(): void
    {
        $owner = $this->createUser('owner@example.org');
        $game = $this->createGame('Super Metroid', 'super-metroid');
        $event = $this->createEvent($game);
        $reg = $this->createRegistrationWithSlot($event, $owner->getId(), $game);

        $slotId = $reg->getGameSlots()[0]['slotId'];

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            sprintf('/api/v1/registrations/%s/slots/%s/yaml', $reg->getId(), $slotId),
            ['playerYaml' => 'name: Test'],
        );
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $errorData = $response['error'];
        self::assertIsArray($errorData);
        self::assertSame('validation_failed', $errorData['code']);
        $errorDetails = $errorData['details'];
        self::assertIsArray($errorDetails);
        self::assertArrayHasKey('game', $errorDetails);
    }

    public function testValidYamlSaveStoresPlayerYaml(): void
    {
        $owner = $this->createUser('owner@example.org');
        $game = $this->createApworldGame('Hollow Knight', 'hollow-knight');
        $event = $this->createEvent($game);
        $reg = $this->createRegistrationWithSlot($event, $owner->getId(), $game);

        $slotId = $reg->getGameSlots()[0]['slotId'];
        $yaml = "name: PlayerName\ngame: Hollow Knight\nHollow Knight:\n  start_location: King's Pass\n";

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            sprintf('/api/v1/registrations/%s/slots/%s/yaml', $reg->getId(), $slotId),
            ['playerYaml' => $yaml],
        );
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame('ok', $responseData['outcome']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Registration::class, $reg->getId());
        self::assertInstanceOf(Registration::class, $refreshed);
        $slot = $refreshed->getSlot($slotId);
        self::assertIsArray($slot);
        self::assertSame($yaml, $slot['playerYaml'] ?? null);
    }

    public function testGetSelectionShowsPlayerYamlAndDefaultYaml(): void
    {
        $defaultYaml = "name: PlayerName\ngame: Hollow Knight\n";
        $owner = $this->createUser('owner@example.org');
        $game = $this->createApworldGame('Hollow Knight', 'hollow-knight', $defaultYaml);
        $event = $this->createEvent($game);
        $reg = $this->createRegistrationWithSlot($event, $owner->getId(), $game);

        $slotId = $reg->getGameSlots()[0]['slotId'];
        $playerYaml = "name: Custom\ngame: Hollow Knight\n";
        $reg->setSlotPlayerYaml($slotId, $playerYaml, 'test-hash', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $reg->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);

        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(1, $availableGames);
        $firstGame = $availableGames[0];
        self::assertIsArray($firstGame);
        self::assertTrue($firstGame['isApworldReady']);
        self::assertSame($defaultYaml, $firstGame['defaultYaml']);

        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame($slotId, $firstSlot['slotId']);
        self::assertSame($playerYaml, $firstSlot['playerYaml']);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function createUser(string $email): User
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'hash',
            ['ROLE_USER'],
            $now, $now, $now,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createGame(string $name, string $slug): ArchipelagoGame
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $game = ArchipelagoGame::create($name, $slug, 'A game.', null, 'Alt', 'Credit', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createApworldGame(string $name, string $slug, string $defaultYaml = "name: PlayerName\ngame: Hollow Knight\n"): ArchipelagoGame
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $game = ArchipelagoGame::create($name, $slug, 'A game.', null, 'Alt', 'Credit', ArchipelagoGame::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('test-key', 'test-hash', $name, $defaultYaml, $now);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createEvent(ArchipelagoGame $game): Event
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Test Event',
            'Description',
            Event::STATUS_PUBLISHED,
            $now->modify('+30 days'),
            $now->modify('+31 days'),
            'Somewhere',
            30,
            $now->modify('-10 days'),
            $now->modify('+20 days'),
            true,
            null,
            true,
            [['gameId' => $game->getId()]],
            null,
            null,
            $now,
            $now,
        );
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function createRegistrationWithSlot(Event $event, string $userId, ArchipelagoGame $game): Registration
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $slotId = bin2hex(random_bytes(8));
        $reg = new Registration(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $userId,
            Registration::STATUS_RESERVED,
            $now,
            $now,
            [['slotId' => $slotId, 'gameId' => $game->getId(), 'slotOrder' => 1]],
        );
        $this->entityManager->persist($reg);
        $this->entityManager->flush();

        return $reg;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /** @return array<mixed> */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
