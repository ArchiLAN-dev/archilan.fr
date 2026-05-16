<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminEventGameSelectionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Game::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousAndLambdaCannotConfigureGameSelection(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/game-selection', [
            'gameSelectionEnabled' => true,
            'games' => [],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsNotFoundForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/game-selection', [
            'gameSelectionEnabled' => false,
            'games' => [],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminGetsDefaultDisabledConfigWithAvailableGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['gameSelectionEnabled']);
        self::assertSame([], $data['selectedGames']);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(1, $availableGames);
        $firstGame = $availableGames[0];
        self::assertIsArray($firstGame);
        self::assertSame($gameId, $firstGame['id']);
    }

    public function testAdminOnlyGetsAvailableAndExperimentalGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $this->createGameViaApi('Zelda OoT', 'zelda-oot', Game::AVAILABILITY_AVAILABLE);
        $this->createGameViaApi('Factorio', 'factorio', Game::AVAILABILITY_EXPERIMENTAL);
        $this->createGameViaApi('Celeste', 'celeste', Game::AVAILABILITY_UNAVAILABLE);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(2, $availableGames);
        self::assertNotContains(
            Game::AVAILABILITY_UNAVAILABLE,
            array_column($availableGames, 'availability'),
        );
    }

    public function testAdminEnablesGameSelectionWithGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['gameSelectionEnabled']);
        $selectedGames = $data['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $selectedGame = $selectedGames[0];
        self::assertIsArray($selectedGame);
        self::assertSame($gameId, $selectedGame['gameId']);
        self::assertSame('Zelda OoT', $selectedGame['gameName']);
    }

    public function testAdminDisablesGameSelectionPreservingConfig(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [['gameId' => $gameId]],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => false,
            'games' => [],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['gameSelectionEnabled']);
    }

    public function testGameSelectionEnabledIsRequired(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'games' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('gameSelectionEnabled', $response['error']['details']);
    }

    public function testInvalidGameIdIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => 'nonexistent-game-id'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.0.gameId', $response['error']['details']);
    }

    public function testUnavailableGameIsRejectedFromSelection(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Factorio', 'factorio', Game::AVAILABILITY_UNAVAILABLE);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.0.gameId', $response['error']['details']);
    }

    public function testDuplicateGameIdIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.1.gameId', $response['error']['details']);
    }

    public function testGameSelectionEnabledReflectedInEventList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEventViaApi();
        $gameId = $this->createGameViaApi('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list = $this->decodedJsonResponse();
        $listData = $list['data'];
        self::assertIsArray($listData);
        self::assertCount(1, $listData);
        $firstEvent = $listData[0];
        self::assertIsArray($firstEvent);
        self::assertFalse($firstEvent['gameSelectionEnabled']);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [['gameId' => $gameId]],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list = $this->decodedJsonResponse();
        $listData2 = $list['data'];
        self::assertIsArray($listData2);
        $updatedEvent = $listData2[0];
        self::assertIsArray($updatedEvent);
        self::assertTrue($updatedEvent['gameSelectionEnabled']);
    }

    private function createEventViaApi(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events', [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago de printemps.',
            'type' => 'Multiworld ouvert',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    private function createGameViaApi(
        string $name,
        string $slug,
        string $availability = Game::AVAILABILITY_AVAILABLE,
    ): string {
        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => $name,
            'slug' => $slug,
            'description' => 'Un jeu compatible Archipelago.',
            'coverImageAlt' => 'Logo '.$name,
            'coverImageCredit' => 'Publisher',
            'availability' => $availability,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }
}
