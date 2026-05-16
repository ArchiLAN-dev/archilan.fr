<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class RegistrationSlotYamlTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
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
        $event = $this->makeEvent($game);
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
        $event = $this->makeEvent($game);
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
        $event = $this->makeEvent($game);
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
        $event = $this->makeEvent($game);
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

    private function createApworldGame(string $name, string $slug, string $defaultYaml = "name: PlayerName\ngame: Hollow Knight\n"): Game
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $game = $this->createGame($name, $slug);
        $game->configureApworld('test-key', 'test-hash', $name, $defaultYaml, $now);
        $this->entityManager->flush();

        return $game;
    }

    private function makeEvent(Game $game): Event
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');

        return $this->createEvent(
            'Test Event',
            $now->modify('+30 days'),
            $now->modify('+31 days'),
            capacity: 30,
            published: true,
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
            registrationOpensAt: $now->modify('-10 days'),
            registrationClosesAt: $now->modify('+20 days'),
        );
    }

    private function createRegistrationWithSlot(Event $event, string $userId, Game $game): Registration
    {
        return $this->createRegistration($event->getId(), $userId, selectedGameIds: [$game->getId()]);
    }
}
