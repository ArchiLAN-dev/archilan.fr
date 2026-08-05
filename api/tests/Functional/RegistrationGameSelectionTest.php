<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;

final class RegistrationGameSelectionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        $event = $this->makeEvent();
        $registration = $this->createRegistration($event->getId(), $owner->getId());

        $this->loginAs($other);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationReturns404OnGetAndPut(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: true);
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
        $event = $this->makeEvent();
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
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]], maxPerRegistrant: 2);
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
        // Story 28.16: the picker filters by platform and recognises a coupled Steam library, which
        // it cannot do unless the payload carries these - a game with no catalogue metadata still
        // answers, with an empty platform list and no app id, rather than omitting the keys.
        self::assertSame([], $firstGame['platforms']);
        self::assertNull($firstGame['steamAppId']);
    }

    public function testPutSavesGameSelection(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
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
        $event = $this->makeEvent();
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
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
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

    public function testPutRejectsNewlyAddedDisabledGame(): void
    {
        // Story 11.4: a disabled game stays listed (with its message) but cannot be newly added.
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $game->disable('Apworld cassé, correctif en cours.', new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $this->entityManager->flush();
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertIsArray($availableGames[0]);
        self::assertTrue($availableGames[0]['disabled']);
        self::assertSame('Apworld cassé, correctif en cours.', $availableGames[0]['disabledMessage']);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('gameIds.0', $details);
        $messages = $details['gameIds.0'];
        self::assertIsArray($messages);
        self::assertIsString($messages[0]);
        self::assertStringContainsString('Apworld cassé, correctif en cours.', $messages[0]);
    }

    public function testPutKeepsExistingSlotOfNewlyDisabledGame(): void
    {
        // A disable after selection must not brick the registration: re-submitting the same
        // selection stays accepted, only NEW additions are blocked.
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId()],
        ]);
        self::assertResponseStatusCodeSame(200);

        $game->disable(null, new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));
        $this->entityManager->flush();

        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/game-selection', $registration->getId()), [
            'gameIds' => [$game->getId()],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testPutRejectsWhenMaxExceeded(): void
    {
        $user = $this->createUser('user@example.org');
        $gameA = $this->createGame('Zelda OoT', 'zelda-oot');
        $gameB = $this->createGame('Super Metroid', 'super-metroid');
        $event = $this->makeEvent(
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
        $event = $this->makeEvent(
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
        $event = $this->makeEvent(gameSelectionEnabled: true, gameSelectionConfig: [['gameId' => $game->getId()]]);
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
        $event = $this->makeEvent();
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
        $event = $this->makeEvent(
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
        $event = $this->makeEvent(
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
    private function makeEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?int $maxPerRegistrant = null,
        ?\DateTimeImmutable $registrationClosesAt = null,
    ): Event {
        return $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
            gameSelectionEnabled: $gameSelectionEnabled,
            gameSelectionConfig: $gameSelectionConfig,
            registrationClosesAt: $registrationClosesAt,
            gameSelectionMaxPerRegistrant: $maxPerRegistrant,
        );
    }
}
